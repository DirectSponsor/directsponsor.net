# AGENTS.md

This file provides guidance to AI coding assistants (Cascade/Windsurf, Warp, etc.) when working with code in this repository.

## Project Overview

DirectSponsor.net is a peer-to-peer support platform using Bitcoin Lightning payments. It has two mechanisms: **fundraisers** (one-off campaigns) and **sponsorship groups** (ongoing monthly commitments). Sponsorship groups are the more important of the two — they provide recipients with reliable income and a lasting human relationship, not just a transaction. All payments go directly recipient-to-recipient via Coinos Lightning wallets; no intermediaries hold funds. All storage is file-based; no database.

### Terminology
- **Recipient** — the person (or small group) receiving support (e.g. evans, lightninglova)
- **Recipient group** — a small group of recipients (max 12) cooperating on a shared project. Each member receives their own sponsorship income directly; a voluntary common fund may exist but is collectively controlled — no single person has discretionary financial power. See `direct_sponsor_recipient_groups.docx`.
- **Project** — the recipient's overarching initiative (e.g. Badilisha Food Forest, Bitcoin4Ghana); stored as a content page, not a system entity
- **Fundraiser** — a specific campaign the system manages (goal, progress, donate button); stored as a numbered HTML file (`001.html`, `002.html`...)
- **Sponsorship group** — a small group (max 12) of sponsors making a regular monthly commitment to a specific recipient. Has three tiers: Active, Standby, Queued. See `direct_sponsor_sponsorship_groups.docx`.

## Philosophy

- Static-first, file-driven — no database, no runtime templating
- Minimal dependencies — vanilla JS, handcrafted CSS, PHP built-ins only
- Every byte is a cost — target fast 3G load times, <500KB per core page
- Features must degrade gracefully without JavaScript
- **Structural integrity over rules** — the system is designed so that misuse is structurally impossible ("can not", not "should not"). Money never passes through a single intermediary; common funds stay under collective group control; coordinator roles are administrative only.
- **Not a growing platform** — the long-term model is a network of independent nodes linked via Nostr, not one large central site. Each node serves its own community.

---

## Architecture

### Server
- **RN1**: `root@104.168.38.197` (SSH alias in `~/.ssh/config`)
- **Web root**: `/var/www/directsponsor.net/html/` (built + deployed files)
- **User data**: `/var/www/directsponsor.net/userdata/` (never overwritten by deploy)

### Build & Deploy
```bash
bash build.sh site       # Compiles CMS includes into site/ HTML files
bash deploy.sh --auto    # Rsyncs built files to RN1 (protects userdata/)
```
- **Never edit files directly on the server** — changes will be overwritten on next deploy
- Exception: `userdata/` files (profiles, projects, logs) are server-only

### Data Storage (file-based, under `userdata/`)
```
userdata/
  profiles/
    {userId}-{username}.txt       # JSON: roles, display_name, coinos_api_key,
                                  #       donations_made[], etc.
  projects/
    {username}/
      {id}-config.json            # Coinos API key for this project
      active/
        001.html                  # Comment-tags store all project data + donor list
        002.html                  # Queued next project
      completed/
        001.html                  # Past projects (moved here by webhook on goal reached)
      archive/                    # Old/migrated files kept for reference
  data/
    project-donations-pending/
      pending.json                # In-flight invoices (cleared on webhook confirm)
    transaction-ledger.json       # Audit trail of all confirmed payments
  logs/
    project_payments.log
    webhook.log
```

### Fundraiser Queue System

A recipient can have multiple fundraiser files in `active/`. The **lowest-numbered file is the current active fundraiser**; all others are queued (next in line).

- `active/001.html` → currently live, accepting donations
- `active/002.html` → queued (shown as "○ Queued" on profile page, edit link visible to recipient)
- `completed/001.html` → moved here by webhook when `current-amount >= target-amount`

**Advancement:** when the webhook confirms a payment that completes a fundraiser, it moves the completed file to `completed/` automatically. The next lowest-numbered file in `active/` then becomes the active one — no manual action needed.

**Partial balances:** a queued fundraiser can already have a non-zero `current-amount` (e.g. from early donations). This is fine — the balance carries through when it becomes active.

**Public display rules:**
- `fundraisers.html` listing — only shows the single lowest-numbered active file per recipient
- Profile page — shows all files in `active/`, labelled Active (first) or Queued (rest); completed files shown in a separate section
- Donors *can* navigate to and donate to a queued fundraiser — this is intentional; no blocking code added (YAGNI)

### Project HTML format
All project data is stored in HTML comment tags:
```html
<!-- OWNER: username -->
<!-- title -->Project Title<!-- end title -->
<!-- short-description -->...<!-- end short-description -->
<!-- full-description -->...<!-- end full-description -->
<!-- target-amount -->60000<!-- end target-amount -->
<!-- current-amount -->0<!-- end current-amount -->
<!-- status -->active<!-- end status -->
<!-- location -->Ghana<!-- end location -->
<!-- website-url --><!-- end website-url -->
<!-- image-url --><!-- end image-url -->
<!-- lightning-address --><!-- end lightning-address -->
<!-- recent_donations --><!-- end recent_donations -->
```
The `<!-- recent_donations -->` block is appended to by the webhook on each payment. **All new project stubs must include this block** or donations won't appear on the project page.

### Profile file format
JSON stored in `{userId}-{username}.txt`:
```json
{
  "user_id": "4",
  "username": "lightninglova",
  "roles": ["member", "recipient"],
  "coinos_api_key": "eyJ...",
  "coinos_username": "lightninglova",
  "donations_made": [
    {"project_id": "001", "username": "lightninglova", "amount": 1000, "date": "2026-03-29"}
  ]
}
```

---

## Key Files

| File | Purpose |
|------|---------||
| `site/fundraiser.html` | Individual fundraiser page + donate modal |
| `site/fundraisers.html` | Fundraiser listing (API-driven tiles) |
| `site/edit-fundraiser.html` | Recipient fundraiser create/edit form |
| `site/profile.html` | User profile (own + public view) + donations made |
| `site/admin.html` | Admin role management UI |
| `site/api/project-donations-api.php` | Coinos invoice creation |
| `site/api/webhook.php` | Payment webhook: updates fundraiser HTML, advances queue, writes profile |
| `site/api/fundraiser-api.php` | Fundraiser data reader (parses comment-tags) |
| `site/api/save-fundraiser.php` | Fundraiser save endpoint (creates/updates fundraiser HTML + config.json) |
| `site/api/simple-profile.php` | Profile CRUD + role management + `my_donations` action |
| `site/api/auth-proxy.php` | Proxies JWT validation to auth server |
| `site/cms/includes/social-layout-start.incl` | Shared nav (login link, user menu) — included in all pages |
| `site/styles/directsponsor-compact.css` | Main stylesheet |
| `build.sh` | Build script (processes CMS includes) |
| `deploy.sh` | Rsync deploy to RN1 |
| `PROGRESS.md` | Ongoing progress notes, bug history, pending tasks |
| `archive/old-projects-hardcoded.md` | Archived old project info (Badilisha, Desert Farm) |

---

## Donation Flow

1. Donor opens modal → name field auto-filled from JWT (editable); guests can type or leave blank
2. Picks amount → JS decodes JWT for `donor_username` + reads name field → POST to `project-donations-api.php`
3. `project-donations-api.php` finds `{username}/{id}-config.json`, reads Coinos API key, POSTs to Coinos API
4. Invoice + QR shown in modal; poll loop checks payment status every 3s
5. Coinos fires webhook → `webhook.php` confirms payment
6. `webhook.php` updates `<!-- current-amount -->` and appends `<li>` to `<!-- recent_donations -->` in project HTML
7. If `current-amount >= target-amount`: project HTML moved to `completed/`, next queued project becomes active
8. `webhook.php` appends entry to donor's `donations_made` array in their profile file
9. `transaction-ledger.json` updated as audit trail

---

## Authentication

- JWT tokens from `https://auth.directsponsor.org/jwt-login.php`
- Stored in `sessionStorage` / `localStorage` as `jwt`
- Decoded client-side to get `username`, `user_id`
- Roles checked server-side from profile file (not from JWT)
- Default role: `member`. Additional roles (`recipient`, `admin`) assigned manually via SSH or admin UI

### Assigning roles via SSH
```bash
ssh RN1 "python3 -c \"
import json
f = '/var/www/directsponsor.net/userdata/profiles/4-username.txt'
d = json.load(open(f))
d['roles'].append('recipient')
json.dump(d, open(f,'w'), indent=2)
\""
```

---

## Updating a project's Coinos API key
```bash
ssh RN1 "python3 -c \"
import json
f = '/var/www/directsponsor.net/userdata/projects/USERNAME/001-config.json'
d = json.load(open(f))
d['recipient_wallet']['api_key'] = 'NEW_KEY_HERE'
json.dump(d, open(f,'w'), indent=2)
\""
```

---

## CSS & Layout Conventions

- **Single stylesheet**: `site/styles/directsponsor-compact.css` — all styles including posts/wysiwyg. No page-level `<style>` blocks.
- **Layout**: CSS table layout (`display: table` / `table-cell`) — no flexbox, no float hacks, no `@supports`
- **Units**: `em` and `%` throughout; `1px` borders are the only exception
- **Target viewport**: 390px minimum (covers iPhone 14/15 and most modern Android). 320px not supported unless someone reports it.
- **Mobile breakpoint**: `max-width: 40em` stacks the 2-column layout to single column
- **No framework CSS**: handcrafted only — no Bootstrap, Tailwind, etc.
- **No inline colour values** — always use CSS classes rather than `style="color:#888"`. Exception: dynamic colours set by JS at runtime.
- **CSS custom properties for all colours** — `var(--bg-card)`, `var(--bg-page)`, `var(--text)`, `var(--text-muted)`, `var(--border)`, `var(--link)`. Defined in `:root`, overridden in `[data-theme="dark"]`.
- **Lists**: no global `ul` reset. Use `class="plain-list"` for emoji/nav lists that don't need markers. Real content lists (in WYSIWYG/post body) get proper `list-style` via scoped CSS classes.

---

## Known Gotchas

- **Profile glob pattern** — profile files are `{id}-{username}.txt`; webhook glob must be `*-{username}.txt`
- **`my_donations` API needs query params** — `getUserId()` reads GET/POST params, not Authorization header; `loadMyDonations()` must pass `user_id` and `username` as query params
- **`recent_donations` block required** — stub in `save-fundraiser.php` includes it; old files must be patched: `sed -i 's|</body>|<!-- recent_donations --><!-- end recent_donations -->\n</body>|' <file>`
- **Coinos API key format** — Bearer JWT. Keys can expire; if invoice creation returns `user not provided`, the key needs regenerating from Coinos account settings
- **RN1 SSH config** — `IdentityFile ~/.ssh/id_rsa` must be present in the RN1 entry in `~/.ssh/config`; `id_rsa.pub` must be in RN1's `authorized_keys`
- **`donor_name` fallback** — if no explicit name given, falls back to `donor_username`, then `Anonymous`
- **Apache strips Authorization header** — JWT sent as `Authorization: Bearer ...` is dropped. Both `save-post.php` and `save-fundraiser.php` check `$input['jwt']` as fallback; frontend sends JWT in request body too
- **CSS list reset** — global `ul { list-style: none }` was removed (2026-04-03). Emoji/nav lists that need markers stripped must use `class="plain-list"`. WYSIWYG and post body lists use explicit `list-style: disc/decimal` in `directsponsor-compact.css`
- **Nostr: strfry DB not writable by www-data** — `/var/lib/strfry/db/` is owned by root; PHP-FPM (`www-data`) cannot run `strfry import` directly. Instead, `save-post.php` publishes events via raw WebSocket to `127.0.0.1:7777` (strfry's internal port), bypassing Apache and file permissions entirely. Do not attempt to fix this with sudo or chown — the WS approach is cleaner.
- **Nostr: nostr-sign.py uses pure Python BIP340** — `/opt/strfry/nostr-sign.py` implements secp256k1 Schnorr signing with no external dependencies (only stdlib). PHP shells out to it for keypair generation and event signing. Do not replace with a library without confirming it's available on RN1.

---

## Live Fundraisers (as of 2026-04-03)

| Username | Project | Status |
|----------|---------|--------|
| `lightninglova` | Bitcoin4Ghana Internet Connectivity | Active |
| `evans` | Badilisha Food Forest | Active (Coinos key confirmed working) |
| `andytest2` | Test Four (004) | Active (test) |
| `andytest2` | 001, 002, 003 | Completed (test) |

**Pending**:
- Grant & Annegret — Desert Farm (on hold — Bitcoin not viable in Namibia; may revisit with a third-party runner + bank transfers later)

---

## Nostr Infrastructure (as of 2026-04-13)

- **Relay**: strfry running on RN1 at `127.0.0.1:7777`, public via `wss://relay.directsponsor.net`
- **Systemd**: `/etc/systemd/system/strfry.service` — boot-enabled
- **Config**: `/opt/strfry/strfry.conf`
- **DB**: `/var/lib/strfry/db/` (LMDB, root-owned)
- **Write policy**: `/opt/strfry/write-policy.py` — currently open (accepts all); to be tightened to DS-issued pubkeys once production-ready
- **Signing helper**: `/opt/strfry/nostr-sign.py` — pure Python BIP340 Schnorr; used by `save-post.php`
- **Per-user keypairs**: generated on first post, stored as `nostr_privkey` / `nostr_pubkey` in profile `.txt` file
- **Apache vhost**: `/etc/apache2/sites-available/relay.directsponsor.net.conf` — HTTP/2 disabled for WS compatibility
- **SSL**: Let's Encrypt via acme.sh, covers `directsponsor.net`, `www.directsponsor.net`, `relay.directsponsor.net`
- **Full plan**: `nostr-integration.md`

Useful commands:
```bash
ssh RN1 "/opt/strfry/strfry --config /opt/strfry/strfry.conf scan --count '{}'"   # event count
ssh RN1 "systemctl status strfry"                                                  # service status
ssh RN1 "journalctl -u strfry -f"                                                  # live logs
```

---

## Sponsorship Groups (planned — not yet built)

The core feature of DS, currently in design stage. Key facts for agents:

### Sponsor tiers
- **Active** — core member; monthly commitment; must respond within defined window or is replaced
- **Standby** — self-selected; fills gaps when an active lapses; maintains group stability
- **Queued** — wants to join; waits for a place to open (place opens when active lapses with no standby, or group expands)

### Group rules
- Max 12 members (often smaller by design — see nimno.net/notes/small-groups/)
- Reminder system triggers at the right time each month; non-responsive actives are removed and replaced (not punitive — structural)
- A waiting list of queued sponsors is a signal of value, not a problem

### What needs building
- Sponsor tier management (active / standby / queued membership per recipient)
- Monthly reminder dispatch + response-window tracking
- Automatic active→standby→queued promotion logic
- UI for sponsors to join a group, view their commitments, see recipient updates
- UI for recipients to see their group composition

---

## Recipient Groups (planned — not yet built)

Recipients can be solo or a small group (max 12). The DS name/network access is conditional on meeting the definition:

1. Each member receives their sponsorship income **directly** from their own sponsors — no member controls another's funds
2. Group is small (≤ 12 members)
3. A common fund may exist if chosen — funded voluntarily by members, under **collective group control**
4. No single individual has discretionary power over the common fund
5. Coordinator (internal or hired) is **administrative only** — carries out group decisions, makes no financial decisions independently
6. All significant decisions and coordinator actions are documented within the system
7. All activity takes place within the public DS system — soliciting funds outside it disqualifies the group from using the DS name

A group that violates these characteristics loses access to the DS network/brand — there is no enforcement, but the value of membership is the incentive to stay within it.

### What needs building
- Common fund accounting tool (income + outgoings, visible to all members)
- Coordinator action log
- Group decision documentation
- Nostr cross-node identity for multi-node recipient groups

---

## Network Architecture (long-term vision)

- DS is designed as a **network of independent nodes**, not a growing central platform
- Each node = a separate DS installation serving a local or topical community
- Nodes are linked via Nostr: cross-node identity, sponsor queues can span nodes, flagging/fraud-prevention layer
- Current site (`directsponsor.net`) is the proof-of-concept foundation
- Central site does not grow into a large platform — successful groups spawn independent nodes

---

## Pending Tasks

- Grant & Annegret: on hold — Bitcoin not viable in Namibia. Project page archived to `archive/grant-annegret-project.html`. May revisit if a third-party runner is found who can handle bank transfers.
- Reconciliation script (backend only, no UI): cross-check `transaction-ledger.json` against per-user `donations_made` arrays
- Auth server post-verification screen: update to show all 3 sites
- `delete-user.sh`: add clickforcharity.net cleanup step
- **Sponsorship group system** — see Sponsorship Groups section above
- **Recipient group tools** — common fund accounting, coordinator log, decision docs (see Recipient Groups section above)
- **Nostr deeper integration** — cross-node identity, flagging, fraud prevention (see `nostr-integration.md`)

## User Account Deletion

To completely delete a user from the ecosystem (for GDPR compliance or user requests):

1. **Site Data Cleanup** (on RN1):
   ```bash
   ./delete-user.sh <username>
   ```
   This removes:
   - DirectSponsor profile and projects
   - RoflFaucet profile (if exists)
   - Any other site-specific data

2. **Auth Server Cleanup** (on auth.directsponsor.org):
   ```bash
   ssh es3-auth "php /root/scripts/delete-auth-user.php <username>"
   ```
   This removes:
   - Authentication credentials
   - Session data
   - Tokens

**Scripts Location**: Stored in auth-server project at `/scripts/` directory
**Important**: Always delete site data BEFORE auth data to avoid foreign key issues

## Changelog — AI Agent Reminder

After completing **significant work** on this project, update the public changelog.

- **File**: `site/changelog.html` — prepend a new `<li>` inside the `<!-- EMBED:changelog -->` block
- **Instructions**: `CHANGELOG-INSTRUCTIONS.md` — full format, categories, and rules
- **Format**: `<li><strong>YYYY-MM-DD</strong> · <strong>DirectSponsor</strong> — <span class="feature">Category</span> One-line plain-English summary.</li>`
- **When**: new features, bug fixes with user impact, auth/payment changes, deployment changes
- **Skip**: typos, refactors, style tweaks, WIP
- **Then deploy**: `bash /home/andy/work/projects/directsponsor.net/deploy.sh --auto`

---

## Deliberate Design Decisions

- **No accounts/transaction overview page** — all money flows directly peer-to-peer; nothing passes through the platform. Donor accountability is covered by: (1) the donor's own profile page listing all their contributions, and (2) each project's fundraiser page listing all donations received. A separate accounts UI would imply platform-level financial responsibility that doesn't exist and would make DS look like a traditional charity org.
