# AGENTS.md

This file provides guidance to AI coding assistants (Cascade/Windsurf, Warp, etc.) when working with code in this repository.

## Project Overview

DirectSponsor.net is a peer-to-peer fundraising platform using Bitcoin Lightning payments. Donors send sats directly to recipients via Coinos Lightning wallets — no intermediaries. Projects are managed by recipients through a self-service UI. All storage is file-based; no database.

## Philosophy

- Static-first, file-driven — no database, no runtime templating
- Minimal dependencies — vanilla JS, handcrafted CSS, PHP built-ins only
- Every byte is a cost — target fast 3G load times, <500KB per core page
- Features must degrade gracefully without JavaScript

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
|------|---------|
| `site/fundraiser.html` | Project fundraiser page + donate modal |
| `site/projects.html` | Project listing (API-driven tiles) |
| `site/edit-project.html` | Recipient project create/edit form |
| `site/profile.html` | User profile (own + public view) + donations made |
| `site/admin.html` | Admin role management UI |
| `site/api/project-donations-api.php` | Coinos invoice creation |
| `site/api/webhook.php` | Payment webhook: updates project HTML, advances queue, writes profile |
| `site/api/fundraiser-api.php` | Project data reader (parses comment-tags) |
| `site/api/save-project.php` | Project save endpoint (creates/updates project HTML + config.json) |
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

- **Layout**: CSS table layout (`display: table` / `table-cell`) — no flexbox, no float hacks, no `@supports`
- **Units**: `em` and `%` throughout; `1px` borders are the only exception (scaling borders with font size makes no sense)
- **Target viewport**: 390px minimum (covers iPhone 14/15 and most modern Android). 320px not supported unless someone reports it.
- **Mobile breakpoint**: `max-width: 40em` stacks the 2-column layout to single column
- **No framework CSS**: handcrafted only — no Bootstrap, Tailwind, etc.
- **No inline colour values** — always use CSS classes (`.color-muted`, `.color-primary`, `.color-success`) rather than `style="color:#888"`. Inline colours are invisible to global updates and cause drift. Exception: dynamic colours set by JS at runtime (e.g. progress bar widths).
- **Use CSS custom properties for all colours** — `var(--bg-card)`, `var(--bg-page)`, `var(--text)`, `var(--text-muted)`, `var(--border)`, `var(--link)` etc. Hardcoded hex values in new CSS won't respond to dark mode. Variables are defined in `:root` and overridden in `[data-theme="dark"]` at the top of `directsponsor-compact.css`.

---

## Known Gotchas

- **Profile glob pattern** — profile files are `{id}-{username}.txt`; webhook glob must be `*-{username}.txt`
- **`my_donations` API needs query params** — `getUserId()` reads GET/POST params, not Authorization header; `loadMyDonations()` must pass `user_id` and `username` as query params
- **`recent_donations` block required** — stub in `save-project.php` includes it; old files must be patched: `sed -i 's|</body>|<!-- recent_donations --><!-- end recent_donations -->\n</body>|' <file>`
- **Coinos API key format** — Bearer JWT. Keys can expire; if invoice creation returns `user not provided`, the key needs regenerating from Coinos account settings
- **RN1 SSH config** — `IdentityFile ~/.ssh/id_rsa` must be present in the RN1 entry in `~/.ssh/config`; `id_rsa.pub` must be in RN1's `authorized_keys`
- **`donor_name` fallback** — if no explicit name given, falls back to `donor_username`, then `Anonymous`

---

## Live Projects (as of 2026-03-29)

| Username | Project | Status |
|----------|---------|--------|
| `lightninglova` | Bitcoin4Ghana Internet Connectivity | Active |
| `andytest2` | Test Four (004) | Active (test) |
| `andytest2` | 001, 002, 003 | Completed (test) |

**Pending recreation** (info in `archive/old-projects-hardcoded.md`):
- Evans — Badilisha Food Forest (needs Coinos account + API key)
- Grant & Annegret — Desert Farm (when ready)

---

## Pending Tasks

- Evans: Coinos account + API key → recreate Badilisha project via `edit-project.html`
- Grant & Annegret: same when ready
- Reconciliation script (backend only, no UI): cross-check `transaction-ledger.json` against per-user `donations_made` arrays — the ledger is the horizontal audit trail (all payments in sequence), the profile arrays are the vertical view (per user). If they diverge, a webhook write was missed. Run via SSH or cron; alert on mismatch.

## Deliberate Design Decisions

- **No accounts/transaction overview page** — all money flows directly peer-to-peer; nothing passes through the platform. Donor accountability is covered by: (1) the donor's own profile page listing all their contributions, and (2) each project's fundraiser page listing all donations received. A separate accounts UI would imply platform-level financial responsibility that doesn't exist and would make DS look like a traditional charity org.
