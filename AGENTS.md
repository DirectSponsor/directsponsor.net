# AGENTS.md

This file provides guidance to AI coding assistants (Cascade/Windsurf, Warp, etc.) when working with code in this repository.

## Project Overview

DirectSponsor.net is a peer-to-peer fundraising platform using Bitcoin Lightning payments. Donors send sats directly to recipients via Coinos Lightning wallets — no intermediaries. Recipients manage their fundraisers through a self-service UI. All storage is file-based; no database.

### Terminology
- **Recipient** — the person running a fundraising initiative (e.g. evans, lightninglova)
- **Project** — the recipient's overarching initiative (e.g. Badilisha Food Forest, Bitcoin4Ghana); stored as a content page, not a system entity
- **Fundraiser** — a specific campaign the system manages (goal, progress, donate button); stored as a numbered HTML file (`001.html`, `002.html`...)

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

---

## Live Fundraisers (as of 2026-04-03)

| Username | Project | Status |
|----------|---------|--------|
| `lightninglova` | Bitcoin4Ghana Internet Connectivity | Active |
| `evans` | Badilisha Food Forest | Active (Coinos key confirmed working) |
| `andytest2` | Test Four (004) | Active (test) |
| `andytest2` | 001, 002, 003 | Completed (test) |

**Pending**:
- Grant & Annegret — Desert Farm (when ready)

---

## Pending Tasks

- Grant & Annegret: create fundraiser stub when ready, then they edit via `edit-fundraiser.html`
- Reconciliation script (backend only, no UI): cross-check `transaction-ledger.json` against per-user `donations_made` arrays
- Auth server post-verification screen: update to show all 3 sites
- `delete-user.sh`: add clickforcharity.net cleanup step

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

---

## Deliberate Design Decisions

- **No accounts/transaction overview page** — all money flows directly peer-to-peer; nothing passes through the platform. Donor accountability is covered by: (1) the donor's own profile page listing all their contributions, and (2) each project's fundraiser page listing all donations received. A separate accounts UI would imply platform-level financial responsibility that doesn't exist and would make DS look like a traditional charity org.
