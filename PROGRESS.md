# DirectSponsor — Progress Notes
_Last updated: 2026-03-22_

## What's done and live

### Infrastructure
- PHP 8.4 on RN1, Apache vhost for `directsponsor.net`
- File-based storage under `/var/www/directsponsor.net/userdata/`
- Build system: `build.sh site` compiles includes, `deploy.sh --auto` rsyncs to RN1
- JWT auth shared with ROFLFaucet (`roflfaucet_session` in localStorage)

### Pages (all live on directsponsor.net)
- `index.html` — homepage
- `projects.html` — lists all active projects from `fundraiser-api.php?action=list`
- `fundraiser.html?project=ID&user=USERNAME` — individual project page with donate modal
- `profile.html` — user profile, shows coinos_username field
- `edit-project.html?project=ID` — recipient edits their project
- `about.html`, `contact.html`

### APIs (all under `/api/`)
- `fundraiser-api.php` — `action=list` / `action=get&id=X&username=Y`
- `project-donations-api.php` — `create_invoice`, `check_payment`, `get_donations`
- `webhook.php` — receives Coinos payment confirmation, updates project HTML
- `save-project.php` — saves edits to project HTML comment-tags
- `auth-proxy.php` — proxies JWT validation to RF auth server

### Donation flow (fully tested with real payments)
1. User clicks Donate → picks amount → `create_invoice` POSTs to Coinos API
2. Invoice + QR shown in modal, with 📋 Copy Invoice button
3. Webhook fires automatically from Coinos on payment → `webhook.php` updates `current-amount` in project HTML
4. Poll loop detects payment (pending.json entry removed) → shows "✅ Payment received!"
5. User clicks Close → page reloads with updated balance

### RF Cutover
- `roflfaucet.com/fundraisers.html` → redirects to `directsponsor.net/projects.html`
- `roflfaucet.com/fundraiser.html` → redirects to `directsponsor.net/projects.html`
- `site-income.html` link updated to DS
- `projects-404.html` link updated to DS

### Live projects
- `lightninglova/001.html` — Bitcoin4Ghana Internet Connectivity (active, tested with real payments)
- `evans/001.html` — Test project (needs Coinos wallet configured)

---

## Data structure
```
/var/www/directsponsor.net/userdata/
  projects/
    lightninglova/
      001-config.json        # coinos API key + wallet info
      active/001.html        # project page (comment-tags store all data)
    evans/
      001-config.json
      active/001.html
  project-donations-pending/
    pending.json             # in-flight invoices, removed on webhook confirmation
  logs/
    project_payments.log
    webhook.log
```

---

## Next priorities (rough order)

### User profiles
- `profile.html` currently shows username + coinos_username field only
- **Todo:** expand to show list of user's projects (current at top, highlighted; past below)
- **Todo:** make `[username]` links on project pages link to `/profile.html?user=USERNAME`
- **Todo:** public profile view vs own profile (edit mode)

### Accounts / transaction history
- RF had a transaction history system — check zip archive for reusable parts
- DS needs: per-user donation history, totals, maybe a simple ledger view

### Project pages
- Recipient name should link to their profile
- Project images — upload flow exists in RF (`upload-project-image.php`), needs porting to DS
- Project updates / blog-style posts per project

### Coin weighting for ad placement (future / novel)
- RF "useless coins" reframed as reputation/acknowledgement tokens
- Idea: coin holdings give weight to fundraisers → influences automatic project selection when advertiser buys ad space
- No existing system to copy — needs design work before implementation

### Admin role management
- Currently roles are assigned manually via SSH (editing profile `.txt` files directly)
- Default role on signup is `member`; recipients need `recipient` role added manually
- The `recipient` role gates the Coinos username field on `profile.html`
- API endpoint already exists: `simple-profile.php?action=manage_roles` (admin-only POST)
- **Todo:** Build a simple `admin.html` page — search users, view their roles, add/remove roles
- **Todo:** When a new recipient is onboarded, document the steps: create account → assign `recipient` role → set Coinos username in profile → add `*-config.json` with API key

### Evans project
- Needs Coinos account + API key added to `evans/001-config.json` to accept donations
- Evans profile now has `recipient` role — Coinos username field visible on profile.html

---

## Key files to know
| File | Purpose |
|------|---------|
| `site/fundraiser.html` | Main project page + donate modal (source, built by build.sh) |
| `site/projects.html` | Project listing |
| `site/api/project-donations-api.php` | Invoice creation + payment checking |
| `site/api/webhook.php` | Coinos webhook handler |
| `site/api/fundraiser-api.php` | Project data reader |
| `site/api/save-project.php` | Project editor save endpoint |
| `build.sh` | Build + deploy script |
