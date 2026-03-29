# DirectSponsor — Progress Notes
_Last updated: 2026-03-29 (session 3)_

## What's done and live

### Infrastructure
- PHP 8.4 on RN1, Apache vhost for `directsponsor.net`
- File-based storage under `/var/www/directsponsor.net/userdata/`
- Build system: `build.sh site` compiles includes, `deploy.sh --auto` rsyncs to RN1
- JWT auth shared with ROFLFaucet (`roflfaucet_session` in localStorage)
- Profile files named `{userId}-{username}.txt` under `userdata/profiles/`
- Nav includes username dropdown with Profile + Logout links (all pages via `social-layout-start.incl`)
- Login links force `https://` in `redirect_uri` even when page visited over HTTP

### Pages (all live on directsponsor.net)
- `index.html` — homepage
- `projects.html` — lists all active projects from `fundraiser-api.php?action=list`
- `fundraiser.html?project=ID&user=USERNAME` — individual project page with donate modal
- `profile.html` — own profile (edit mode) or public profile (`?user=USERNAME`, read-only)
- `edit-project.html` — recipient creates/edits project (no `?project=` = new project, auto-assigned ID)
- `edit-project.html?project=ID` — edit existing project; redirects to fundraiser page on save
- `admin.html` — admin role management UI (search users, add/remove roles)
- `about.html`, `contact.html`

### APIs (all under `/api/`)
- `fundraiser-api.php` — `action=list` / `action=get&id=X&username=Y` / `action=user_projects&username=Y`; returns `image_url`, `website_url`, `location`, `full_description`, `recent_donations`
- `project-donations-api.php` — creates Coinos invoice; passes `donor_username` through to pending entry
- `webhook.php` — payment confirmation, updates `current-amount`, auto-advances queue; writes `donations_made` directly to donor's profile file; logs to `transaction-ledger.json`
- `save-project.php` — saves project HTML comment-tags + writes `{id}-config.json`; falls back to profile's Coinos API key if not in form
- `simple-profile.php` — profile CRUD + role management; `action=my_donations` reads `donations_made` from profile file
- `auth-proxy.php` — proxies JWT validation to auth server

### Donation flow (fully tested with real payments)
1. Donor opens modal → name field auto-filled from JWT (editable); guests can type a name or leave blank
2. Picks amount → JS decodes JWT to get `donor_username` and reads name field → `project-donations-api.php` POSTs to Coinos API
3. Invoice + QR shown in modal, with Copy Invoice button
4. Webhook fires → `webhook.php` updates `current-amount` in project HTML, appends `<li>` to `<!-- recent_donations -->` block
5. If `current-amount >= target-amount`: file moved to `completed/`, next queued project becomes active
6. Overpayment shown on project page; no sats lost
7. `donor_username` written to `donations_made` in donor's profile file (for profile history)
8. `transaction-ledger.json` updated as audit trail
9. Poll loop detects payment → "Payment received!" → reload

### Project queue system
- Active project = lowest numbered HTML file in `username/active/`
- On goal reached: webhook auto-moves to `username/completed/`, next becomes active
- New project auto-numbering skips IDs used in both `active/` and `completed/`
- Overpayment stays as `current-amount` on next project (no carry-over math — just shown)

### Fundraiser page features
- Project image (direct URL from postimages.org etc), linked back to source with attribution
- Location and website link shown if set
- Full description used if available, short description as fallback
- Edit button shown to project owner
- Completed banner shown for non-active projects
- Overpayment shown when `current > goal`
- Recent donations list: donor name, amount, date (all donations kept, no cap)
- Donor name field in modal: optional, auto-filled for logged-in users, editable, blank = Anonymous

### Profile page features
- Recipient section: Coinos username, API key, lightning address (auto-populated from profile)
- My Projects section (recipients only): active + completed
- ⚡ Donations I've Made section (all logged-in users): reads from `donations_made` in profile file, links to project fundraiser page

### Recipient self-service
- Recipient can create/edit projects without admin involvement
- Role check: `recipient` role required (checked against profile file)
- Coinos API key stored in profile once; auto-populated in `edit-project.html`; `save-project.php` falls back to profile key

### Data architecture
- **Per-project HTML files** store all project data (title, description, amounts, donor list) in HTML comment tags
- **Per-user profile files** store profile fields + `donations_made` array (written by webhook on each confirmed payment)
- **`transaction-ledger.json`** is the audit trail; used for summaries/reconciliation, not for UI reads
- **No cross-site sync** — each site keeps its own profile data; only coins balance and JWT identity come from auth server

### RF Cutover
- `roflfaucet.com/fundraisers.html` → redirects to `directsponsor.net/projects.html`
- `roflfaucet.com/fundraiser.html` → redirects to `directsponsor.net/projects.html`

### Live projects (as of 2026-03-29)
- `lightninglova/001.html` — Bitcoin4Ghana Internet Connectivity (active, rebuilt with new system)
- `andytest2/001.html`, `002.html`, `003.html` — completed test projects
- `andytest2/004.html` — current active test project (partially funded)
- Old ROFLFaucet-era project file archived at `userdata/projects/lightninglova/archive/001-old-roflfaucet.html`
- Evans (Badilisha Food Forest) + Grant & Annegret (Desert Farm): info archived in `archive/old-projects-hardcoded.md`, recreate when ready

---

## Data structure
```
/var/www/directsponsor.net/userdata/
  profiles/
    {userId}-{username}.txt    # JSON: roles, display_name, coinos_api_key,
                               #       donations_made[], etc.
  projects/
    {username}/
      {id}-config.json         # Coinos API key for this project
      active/
        001.html               # comment-tags store all project data + donor list
        002.html               # queued next project
      completed/
        001.html               # past projects
  data/
    project-donations-pending/
      pending.json             # in-flight invoices (cleared on webhook confirm)
    transaction-ledger.json    # audit trail of all confirmed payments
  logs/
    project_payments.log
    webhook.log
```

---

## Pending / next priorities

### Soon
- Evans (Badilisha Food Forest): get Coinos account + API key, recreate project via `edit-project.html`
- Grant & Annegret (Desert Farm): same when ready
- Accounts / transaction history overview (aggregate totals per user, pull from profile + ledger)

### Future
- Reconciliation script: periodic check that `transaction-ledger.json` and per-user `donations_made` arrays agree
- Coin weighting for ad placement (design work needed first)
- Project updates / blog posts per project

---

## Known gotchas / bug history
- **Profile glob was backwards** — profile files are `{id}-{username}.txt`; webhook glob must be `*-{username}.txt` not `{username}-*.txt`
- **`my_donations` API needs `user_id` param** — `getUserId()` reads GET/POST params, not Authorization header; `loadMyDonations()` in `profile.html` must pass `user_id` and `username` as query params
- **`recent_donations` block missing from older projects** — stub in `save-project.php` now includes it; existing files must be patched manually: `sed -i 's|</body>|<!-- recent_donations --><!-- end recent_donations -->\n</body>|' <file>`
- **`donor_name` defaulted to Anonymous** — now falls back to `donor_username` in `storePendingProjectDonation`; even cleaner via explicit name field in modal
- **lightninglova invoice failing with `user not provided`** — old ROFLFaucet-era project HTML had no comment tags; API key was also stale (6 months old). Fix: archive old file, recreate project stub on server, get fresh Coinos API key from lightninglova
- **RN1 SSH broken after single-key migration** — `IdentityFile` line was missing from RN1 entry in `~/.ssh/config`; fixed by adding `IdentityFile ~/.ssh/id_rsa`. Also needed to add `id_rsa.pub` to RN1's `authorized_keys` via web panel

---

## Key files
| File | Purpose |
|------|---------|
| `site/fundraiser.html` | Project page + donate modal |
| `site/projects.html` | Project listing |
| `site/edit-project.html` | Recipient project create/edit form |
| `site/profile.html` | User profile (own + public view) + donations made |
| `site/admin.html` | Admin role management |
| `site/api/project-donations-api.php` | Coinos invoice creation |
| `site/api/webhook.php` | Payment webhook + queue advance + profile write |
| `site/api/fundraiser-api.php` | Project data reader |
| `site/api/save-project.php` | Project save endpoint |
| `site/api/simple-profile.php` | Profile CRUD + role management + my_donations |
| `site/cms/includes/social-layout-start.incl` | Shared nav (login link, user menu) |
| `build.sh` | Build includes |
| `deploy.sh` | Rsync to RN1 |
