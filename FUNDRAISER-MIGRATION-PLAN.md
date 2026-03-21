
===============================================================================
A question arose since the last session. We have centralized balance management across all sites, using auth.directsponsor.org (I think...) and then we have all the other userdata, like profiles etc being synced by syncthing across all servers/sites. 

This can be a bit complex. Is it possible to re-structure things so that all userdata are on the auth server, no need to sync, and we send users there to do any operations like editing profile and so on. For data to view on each site, we could display that from the user html files, which are public, and only html, so we could pull that direct from those html files, no need for iframes etc, no cross-site stuff? Can we discuss how to go about doing this if it's possible? I think it may be a lot simpler than using syncthing. We could minimize the need for pulling data by having it all in the user profile pages, and even perhaps use comment tags to mark any bits we may beed to pull to show in the other sites. We may not even need to display any. If we send the users to auth server whenever they want to see their stats etc. Also if we do need to alter the non-balance data while a user is on e.g. roflfaucet, could we do that via api to the auth server too? 

==============================================================================

# Fundraiser & Donation System Migration Plan
# RoflFaucet → DirectSponsor.net

*Created: 2026-03-12*  
*Goal: Move the fundraiser/project/donation system out of roflfaucet.com and into
directsponsor.net, its proper long-term home. RoflFaucet refocuses on gaming/faucet
and links to DirectSponsor when needed. ClickForCharity connects as a contributing
platform (coin allocations → projects).*

---

## Ecosystem Roles After Migration

| Site | Role | Fundraisers? |
|---|---|---|
| **directsponsor.net** | Social hub, recipient profiles, project management, donations | **YES — moved here** |
| **roflfaucet.com** | Gaming, faucet, entertainment earning | No — links to DS |
| **clickforcharity.net** | Earn via tasks/ads, coin allocation to projects | No — links to DS |

---

## What Exists in RoflFaucet Today (to understand what moves)

### The Two Donation Systems

**System 1 — Site Income (General Fund)**  
Pooled donations for site operations, distributed monthly to active projects.  
- API: `site/api/site-income-api.php`  
- Data: `/var/roflfaucet-data/data/donations/`  
- UI: `site/site-income.html` + `site/donate-modal.html`

**System 2 — Direct Project Donations**  
100% goes directly to the recipient's own Lightning wallet via their Coinos API key.  
- API: `site/api/project-donations-api.php`  
- Data: `/var/roflfaucet-data/projects/{username}/active/{id}.html` (HTML is source of truth)  
- Config: `/var/roflfaucet-data/projects/{username}/{id}-config.json` (holds API key)  
- UI: `site/fundraiser.html`, `site/fundraisers.html`, donate modal embedded in project pages  
- Webhook: `site/webhook.php` — receives Coinos callbacks, updates HTML files directly

### Key Architecture Points
- **HTML files ARE the database** — no separate DB. Each project's `001.html` stores all data in HTML comment tags (`<!-- current-amount -->`, `<!-- recent_donations -->`, etc.)
- **Per-user project structure**: `/var/roflfaucet-data/projects/{username}/active/` and `completed/`
- **Images protected**: `/var/roflfaucet-data/projects/{username}/images/` (Apache Alias, survives deployments)
- **Roles system**: `member`, `recipient`, `admin` stored in user profiles + cached in localStorage via session-bridge
- **Webhook reliability**: Primary Coinos webhook + spawned backup verification process (progressive backoff)
- **Cross-site auth**: Shared JWT via `auth.directsponsor.net`, same session-bridge.php pattern works across all sites
- **User files sync**: User profile files synced across servers via Syncthing (already running on RF, CFC, DS hub). DS will join the same sync group
- **Balance centralised**: Coin balance is NOT in user files (was changing too fast for Syncthing). Balance lives on a central server only. User files (profile, roles, project data) are synced; balance is fetched via API from the central balance server

---

## Project Lifecycle & Directory Structure (DS Design)

### Directory Structure per Recipient

```
userdata/projects/{username}/
├── pending/          ← submitted by recipient, awaiting admin approval
├── active/           ← the one currently live and accepting donations (max 1)
├── completed/        ← fully funded projects, archived with full history
├── images/           ← project images (survives deployments via Apache Alias)
└── 001-config.json   ← Coinos API key for project 001 (never in git)
```

### Project States

| State | Directory | Meaning |
|---|---|---|
| `pending` | `pending/` | Submitted by recipient, not yet admin-approved |
| `active` | `active/` | Live — visible publicly, accepting donations |
| `completed` | `completed/` | Fully funded, moved here automatically |

### Lifecycle Flow

```
Recipient creates project
        ↓
  [pending/001.html]   ← awaiting admin approval
        ↓  (admin approves — moves file)
  [active/001.html]    ← live, accepting donations
        ↓  (target reached — automatic promotion)
  [completed/001.html] ← archived with full donation history
        ↓  (if next project exists in pending/)
  [active/002.html]    ← next project automatically promoted
```

### Automatic Promotion on Completion

When `webhook.php` processes a donation that causes `current-amount` to reach or exceed `target-amount`:

1. Read `<!-- target-amount -->` and `<!-- current-amount -->` from the active project HTML
2. If `current-amount >= target-amount`:
   - Set `<!-- status -->completed<!-- end status -->` in the HTML
   - `mv active/001.html completed/001.html`
   - Scan `pending/` for the next project (lowest numbered file)
   - If found: `mv pending/002.html active/002.html`
   - Log the transition with timestamp
3. The fundraisers listing page automatically reflects the new state (reads from `active/`)

### Key Rules
- **One active project per recipient at a time** — enforced by directory (only one file can be in `active/`)
- **Queue is ordered by filename** — `001`, `002`, `003` etc. — lower number promoted first
- **Overflow handling** — if a donation pushes past the target, the excess is noted in the HTML (`<!-- overflow-amount -->`) and carries forward to the next project's starting balance
- **Admin approval gate** — projects go to `pending/` first; admin moves to `active/` (or to `active/` directly if no current active project and none queued)
- **No deletion** — completed projects stay in `completed/` forever for transparency

### Why Directories Over a Status Field
- The API only needs to `glob("userdata/projects/*/active/*.html")` to find all live projects — no content scanning
- State change = file move — atomic, no partial-update risk
- `completed/` acts as a natural audit archive
- Pending approval workflow is free — just don't move the file until approved

### Future: Past Projects Listings (deferred, design already enabled)

The directory structure makes these straightforward to add later — no schema changes needed:

- **Site-wide completed projects page** — `glob("userdata/projects/*/completed/*.html")`, sort by completion date (readable from HTML comment tag), paginate
- **Per-recipient project history** — `glob("userdata/projects/{username}/completed/*.html")` — show on recipient's profile page as a timeline of past campaigns
- **Recipient stats** — total raised across all completed projects is a simple sum of `<!-- target-amount -->` values in `completed/`
- **"X projects funded"** badge — count of files in `completed/` per recipient

All of this is a read-only display layer on top of what already exists. Nothing needs redesigning when we get there.

---

## Files to Move (from roflfaucet to directsponsor.net)

### HTML Pages
| File | Purpose |
|---|---|
| `site/fundraiser.html` | Individual project/fundraiser display page |
| `site/fundraisers.html` | Browse/list all fundraisers |
| `site/edit-project.html` / `edit-project-v2.html` | Project editing UI for recipients |
| `site/donate-modal.html` / `donate-modal-v2.html` | Reusable Lightning donation modal |
| `site/project-template.html` | Template for new project HTML files |
| `site/projects-404.html` | Missing project fallback page |
| `site/site-income.html` | General/pooled donation page |
| `site/transparency.html` | Full donation transparency log |
| `site/confirm-payment.html` | Payment confirmation page |
| `site/pending-preview.html` | Admin pending invoice preview |
| `site/admin-payment-entry.html` | Manual payment admin tool |
| `site/admin-roles.html` | User roles management |

### API PHP Files (`site/api/`)
| File | Purpose |
|---|---|
| `fundraiser-api.php` | Read project data from HTML comment tags |
| `project-donations-api.php` | Create Lightning invoices per project |
| `project-management-api-v2.php` | Create / edit / manage projects |
| `site-income-api.php` | General site fund donations |
| `record-manual-payment.php` | Admin manual payment entry |
| `financial-totals.php` | Income/donation totals |
| `get-recipient-payments.php` | Recipient payment history |
| `confirm-payment-receipt.php` | Payment confirmation helper |
| `pending-project-viewer.php` | Admin pending invoice view |
| `upload-project-image.php` | Project image upload handler |
| `simple-profile.php` | User profile read/write (shared across ecosystem) |

### Root-level PHP
| File | Purpose |
|---|---|
| `site/webhook.php` | Coinos payment webhook — updates project HTML files |
| `site/session-bridge.php` | JWT → session + role caching (copy/adapt for DS) |

### JavaScript (`site/js/` or `site/scripts/`)
| File | Purpose |
|---|---|
| `scripts/user-roles.js` | Role detection from localStorage / username suffix |
| `scripts/project-editor.js` | Project create/edit form logic |
| `js/donate-modal.js` | Donation modal JS (if extracted) |
| `scripts/site-utils.js` | Shared utilities (use absolute API paths `/api/...`) |

### CSS
| File | Purpose |
|---|---|
| `site/main.css` | Main stylesheet (take relevant sections, don't copy wholesale) |

### Docs to carry forward (for context, not deployment)
- `DONATION_SYSTEM_ARCHITECTURE.md`
- `CURRENT_SYSTEM_STATE.md`
- `FUNDRAISER_SYSTEM.md`

---

## What STAYS in RoflFaucet (do not move)

- All game pages: `slots.html`, `roll.html`, `wheel.html`, `poker-dice.html`, `games.html`
- Faucet: `faucet-claim.html`, `faucet-result.html`
- `index.html` — home page (update nav/links to point to DS for fundraisers)
- `accounts.html`, `levels.html`, `profile.html` — user account features
- `api/coins-balance.php`, `api/save-level.php`, `api/accounts.php` — game/coin APIs
- `api/simple-chat.php` — chat (if it stays on RF)
- Analytics, manipulation detection

---

## What to Change in RoflFaucet After Migration

1. **Navigation** — remove "Fundraisers" / "Projects" links, add link to directsponsor.net
2. **Site income donations** — decide: keep a RF site fund (for hosting/ops) pointing at RF's own Coinos key, OR redirect all donations to DS. Likely: keep a minimal RF faucet fund, link "Support projects" to DS.
3. **Profile page** — remove "My Projects" / fundraiser section (or replace with link to DS profile)
4. **`⚡ Donate` nav link** — update to point to DS or RF's own simple fund page
5. **Games pages** — can add a small "Your winnings support projects on DirectSponsor.net" blurb with link

---

## DirectSponsor.net — Current State

- Mostly static placeholder pages: `index.html`, `projects.html`, `about.html`, `contact.html`, `evans-project.html`, `grant-annegret-project.html`
- Static build system (`build.sh`) with include-based templating (same pattern as RF)
- Hosted on DirectAdmin shared hosting
- **No PHP APIs yet** — this migration adds the entire backend
- **No user auth yet** — session-bridge.php needs to be set up on DS server

---

## Migration Phases

### Phase 0 — Audit & Preparation (FINDINGS 2026-03-12)

**Server confirmed: RN1 (104.168.38.197), root SSH access, Apache 2.4.65 on Debian**

- [x] DS deploys to `/var/www/directsponsor.net/html/` via SSH to RN1
- [x] Apache already has `/userdata` Alias → `/var/www/directsponsor.net/userdata/` (this is the data dir equivalent)
- [x] SSL active ✅, HTTPS webhook endpoint will work
- [x] **PHP is NOT installed on RN1** — must install before any PHP APIs will work
- [x] **No `/var/directsponsor-data/` dir** — data will live in `/var/www/directsponsor.net/userdata/` (Apache already set up)
- [x] **No JWT/auth env vars** set in Apache on RN1 yet
- [x] RF server (es7) runs PHP 8.1.2 — install same on RN1 for compatibility
- [x] RF site-income Coinos API key is in Apache env var `COINOS_API_KEY` on es7
- [ ] Read DS `build.sh` and includes structure
- [ ] Decide on project URL structure (see Key Decisions)
- [ ] Answer the Key Decisions questions below before proceeding

### Phase 0.5 — Install PHP on RN1 ✅ COMPLETED 2026-03-21
- [x] Debian 13 (trixie) only carries PHP 8.4 natively — installed that instead of 8.1
- [x] `apt install php8.4 libapache2-mod-php8.4` — PHP 8.4.11 installed
- [x] Apache restarted, mod_php8.4 enabled
- [x] Tested via curl: `PHP OK: 8.4.11` ✅
- Note: update plan references from PHP 8.1 → PHP 8.4 going forward

### Phase 1 — Copy Core Files to DS Repo
- [ ] Copy PHP APIs into `directsponsor.net/site/api/`
- [ ] Copy `webhook.php` to `directsponsor.net/site/`
- [ ] Copy `session-bridge.php` (will need domain/path tweaks)
- [ ] Copy JS: `user-roles.js`, `project-editor.js`, `site-utils.js`
- [ ] Copy HTML pages: `fundraiser.html`, `fundraisers.html`, `edit-project.html`, `donate-modal.html`, `project-template.html`, `transparency.html`
- [ ] Adapt navigation includes to DS style (DS uses its own `includes/` structure)
- [ ] Update all hardcoded `roflfaucet` domain references → `directsponsor.net`
- [ ] Update all data paths (`/var/roflfaucet-data/` → `/var/directsponsor-data/` or DS equivalent)

### Phase 2 — Adapt DS Build System
- [ ] Ensure DS `build.sh` processes new pages (fundraiser.html, fundraisers.html, etc.)
- [ ] Add fundraiser-related nav links to DS navigation include
- [ ] Confirm CSS is compatible or port needed styles from `main.css`

### Phase 3 — Server Setup on DS Host
- [ ] Create data directory structure mirroring RF: `projects/{username}/active/`, `completed/`, `images/`
- [ ] Set up Apache Alias for project images (or equivalent on DirectAdmin)
- [ ] Configure Coinos API key as Apache env var (or config file outside webroot)
- [ ] Copy over existing project data files from RF server (`lightninglova/001.html`, etc.) — these contain real donation history
- [ ] Copy config JSON files with Coinos API keys
- [ ] Set up session-bridge on DS server (JWT secret must match RF/CFC)
- [ ] Register DS webhook URL with Coinos

### Phase 4 — Testing
- [ ] Test project page display (fundraiser.html with a real project)
- [ ] Test fundraiser listing (fundraisers.html)
- [ ] Test donation flow end-to-end: invoice creation → Lightning payment → webhook → HTML updated
- [ ] Test role-based UI (recipient sees "My Projects", admin sees management tools)
- [ ] Test project creation flow as a recipient user
- [ ] Test image upload
- [ ] Verify transparency.html shows correct data

### Phase 5 — Hard Cutover (RF + CFC link updates)
- [ ] Update RF navigation: remove fundraiser links, add "Projects" link → directsponsor.net/projects
- [ ] Update RF profile page: remove My Projects section, replace with link to DS
- [ ] Remove RF `⚡ Donate` nav link (no general fund)
- [ ] Add "for charity" blurb on RF game pages linking to DS projects
- [ ] Update RF OpenGraph descriptions (ISSUE-022 already logged)
- [ ] Archive RF fundraiser HTML pages (add "moved to directsponsor.net" comment at top, keep files)
- [ ] Deprecate RF fundraiser APIs (leave in place, add deprecation note in each file)
- [ ] Update CFC: any "allocate coins" or project links → DS
- [ ] Register DS webhook URL with Coinos (replaces RF webhook URL)

### Phase 6 — ClickForCharity Connection (Later)
- [ ] CFC's coin allocation system links to DS project pages for recipients
- [ ] CFC "Allocate Coins" flow points to DS chat/tipping system
- [ ] CFC profile shows "Your contributions support X projects on DirectSponsor.net"

---

## Key Decisions to Make (Before Phase 1)

1. **Data directory** — ✅ RESOLVED: use `/var/www/directsponsor.net/userdata/` (Apache Alias already configured). Mirror RF structure inside it: `projects/{username}/active/`, `completed/`, `images/`.
2. **PHP on RN1** — ✅ RESOLVED: PHP not installed, must install PHP 8.1 to match RF.
3. **Project URL structure** — ✅ RESOLVED: Keep `pending/`, `active/`, `completed/` subdirectories. APIs glob by directory for efficiency. State change = file move (atomic). `pending/` added as new improvement over RF (admin approval gate + auto-promotion queue). See *Project Lifecycle* section above.
4. **Site fund donations** — ✅ RESOLVED: **No general fund on DS.** All donations are 100% direct to projects. Keeps the system honest and simple — every satoshi goes to a named recipient. For cases where a donor doesn't want to pick a project (e.g. ad revenue allocation), the system will auto-assign to a recipient (random or weighted — design TBD later). The ads system will support advertiser-chosen or auto-chosen recipients at point of purchase. RF's site-income system (`site-income-api.php`, `site-income.html`) will be retired and not ported to DS.
5. **Shared JWT secret** — ✅ RESOLVED: Secret is `hybrid_fresh_2025_secret_key` (from RF `session-bridge.php`). Must be set as `define('JWT_SECRET', 'hybrid_fresh_2025_secret_key')` in DS `session-bridge.php` too, or passed via Apache env var. **Do not commit to git.**
6. **RF post-migration** — ✅ RESOLVED (follows from Q4): RF's site-income page gets retired. No general fund anywhere. RF nav "⚡ Donate" link either removed or replaced with a link to DS projects page. RF hosting costs are covered by SatoshiHost/ad revenue, not donations.
7. **User accounts on DS** — ✅ RESOLVED: DS uses the shared auth server at `auth.directsponsor.net` (same as RF and CFC). No separate registration system needed. Cross-site JWT handles login across all sites.
8. **Coinos webhook secret on DS** — ✅ RESOLVED: Use `directsponsor_webhook_secret_2025` (consistent naming with RF's `roflfaucet_webhook_secret_2024`). Set as Apache env var `COINOS_WEBHOOK_SECRET` on RN1, same as RF pattern.

---

## Deferred Design Discussions

### Page Layout (discuss before building UI)
Current RF/DS pages use a 3-column layout (nav | content | sidebar) because that's the standard social media pattern. But DS is under no obligation to follow corporate conventions — worth reconsidering before building the fundraiser pages here.

**Questions to discuss:**
- Does DS actually need 3 columns, or would a simpler sidebar + main content (2-column) serve better?
- Recipient project pages are content-heavy (description, donation progress, recent donors) — a sidebar could hold donation CTA and stats while main column holds the story
- The fundraisers listing page may work better as a simple card grid with no sidebar at all
- Mobile behaviour: sidebar layouts collapse more gracefully than 3-column

**Constraint reminder**: per project principles — lightweight CSS, no heavy frameworks, semantic HTML. Whatever layout is chosen should be handcrafted and minimal.

*Deferred — decide before Phase 1 HTML work begins, not before PHP/server setup.*

### Migration Order (resolved)
✅ RESOLVED: **Hard cutover.** DS fundraiser system is built fresh on directsponsor.net. Once live and tested, RF and CFC simply update their links to point at DS. No parallel period needed — RF has no active fundraiser traffic that would be disrupted. Order: build DS → test → update links on RF/CFC → done.

---

## Risk Notes

- **Real donation data exists** — `lightninglova/001.html` and others have live donation history. Handle carefully; back up before any copy/move.
- **Coinos API keys are sensitive** — `{id}-config.json` files contain JWT tokens. Do not commit to git. Confirm `.gitignore` covers them on DS.
- **Webhook URL change** — When DS goes live, update the Coinos webhook registration from RF URL to DS URL. There's a period where both need to work if RF still accepts donations.
- **PHP version** — RF is on PHP 8.1 (had to fix from 7.4). Confirm DS DirectAdmin hosting PHP version before copying APIs.
- **Session-bridge JWT secret** — Must be identical across all sites for cross-site login to work. This is stored server-side (Apache env var or config file).

---

## Reference: RF Data Directory Structure (to replicate on DS)

```
/var/roflfaucet-data/              ← replicate as /var/directsponsor-data/ (or similar)
├── projects/
│   ├── {username}/
│   │   ├── active/
│   │   │   └── 001.html           ← HTML is the database
│   │   ├── completed/
│   │   │   └── 001.html
│   │   ├── images/
│   │   │   └── 001.jpg
│   │   └── 001-config.json        ← Coinos API key (keep out of git)
│   └── img/                       ← legacy shared images
├── data/
│   ├── project-donations-pending/
│   │   └── pending.json
│   └── transaction-ledger.json    ← audit trail only
└── logs/
    ├── project_payments.log
    └── webhook.log
```

---

## Next Immediate Step

**Start with Phase 0** — answer the key questions above by checking:
1. What's in `/var/directsponsor-data/` on the DS server
2. PHP version on DS DirectAdmin hosting
3. Whether Syncthing is already sharing user/project data between RF and DS

Once those are answered the actual file copying (Phase 1) can begin.
