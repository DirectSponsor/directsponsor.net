# DirectSponsor — Progress Notes
_Last updated: 2026-03-26_

## What's done and live

### Infrastructure
- PHP 8.4 on RN1, Apache vhost for `directsponsor.net`
- File-based storage under `/var/www/directsponsor.net/userdata/`
- Build system: `build.sh site` compiles includes, `deploy.sh --auto` rsyncs to RN1
- JWT auth shared with ROFLFaucet (`roflfaucet_session` in localStorage)
- Profile files named `{userId}-{username}.txt` under `userdata/profiles/`
- Nav includes username dropdown with Profile + Logout links (all pages via `social-layout-start.incl`)

### Pages (all live on directsponsor.net)
- `index.html` — homepage
- `projects.html` — lists all active projects from `fundraiser-api.php?action=list`
- `fundraiser.html?project=ID&user=USERNAME` — individual project page with donate modal
- `profile.html` — own profile (edit mode) or public profile (`?user=USERNAME`, read-only)
- `edit-project.html` — recipient creates/edits project (no `?project=` = new project, auto-assigned ID)
- `edit-project.html?project=ID` — edit existing project
- `admin.html` — admin role management UI (search users, add/remove roles)
- `about.html`, `contact.html`

### APIs (all under `/api/`)
- `fundraiser-api.php` — `action=list` / `action=get&id=X&username=Y` / `action=user_projects&username=Y`
- `project-donations-api.php` — creates Coinos invoice using per-project API key from `config.json`
- `webhook.php` — receives Coinos payment confirmation, updates `current-amount` in project HTML, auto-advances queue on goal reached
- `save-project.php` — saves edits to project HTML comment-tags + writes `{id}-config.json` with Coinos API key
- `simple-profile.php` — profile CRUD + role management (admin-only `manage_roles` action)
- `auth-proxy.php` — proxies JWT validation to auth server

### Donation flow (fully tested with real payments)
1. User clicks Donate → picks amount → `project-donations-api.php` POSTs to Coinos API using project's own API key
2. Invoice + QR shown in modal, with Copy Invoice button
3. Webhook fires from Coinos on payment → `webhook.php` updates `current-amount` in project HTML
4. If `current-amount >= target-amount`: file moved to `completed/`, overpayment carried into next queued project
5. Poll loop detects payment → shows "Payment received!"
6. User clicks Close → page reloads with updated balance

### Project queue system
- Active project = lowest numbered HTML file in `username/active/`
- Recipient queues future projects by creating `002`, `003`... via `edit-project.html`
- On goal reached: webhook auto-moves current project to `username/completed/`, next lowest becomes active
- New project auto-numbering skips numbers already used in both `active/` and `completed/`
- Overpayment carried into `current-amount` of next queued project

### Recipient self-service
- Recipient can create/edit projects without admin involvement
- Role check: `recipient` role required (checked against profile file, not JWT)
- JWT sent in POST body as fallback (Apache strips Authorization header)
- Coinos API key entered per-project for now (TODO: move to profile)

### RF Cutover
- `roflfaucet.com/fundraisers.html` → redirects to `directsponsor.net/projects.html`
- `roflfaucet.com/fundraiser.html` → redirects to `directsponsor.net/projects.html`

### Live projects
- `lightninglova/001.html` — Bitcoin4Ghana Internet Connectivity (active, tested)
- `andytest2/001.html` — Test project 1 (1000 sat goal, partially funded — queue test in progress)
- `andytest2/002.html` — Test project 2 (queued, waiting for 001 to complete)
- `evans/001.html` — needs Coinos wallet configured

---

## Data structure
```
/var/www/directsponsor.net/userdata/
  profiles/
    {userId}-{username}.txt    # JSON: roles, display name, etc.
  projects/
    {username}/
      {id}-config.json         # Coinos API key for this project
      active/
        001.html               # comment-tags store all project data
        002.html               # queued next project
      completed/
        000.html               # past projects
  data/
    project-donations-pending/
      pending.json             # in-flight invoices
    transaction-ledger.json
  logs/
    project_payments.log
    webhook.log
```

---

## Pending / next priorities

### Immediate
- **Queue test:** push `andytest2/001` over 1000 sat goal via donations → confirm webhook moves to `completed/` and `002` becomes active
- **Coinos API key in profile:** store key once in profile.txt, auto-populate `edit-project.html`, `save-project.php` falls back to profile key if not in form

### Soon
- Evans Coinos account + API key → add to `evans/001-config.json`
- Project images — upload flow (port from ROFLFaucet `upload-project-image.php`)
- Accounts / transaction history view per user

### Future
- Coin weighting for ad placement (design work needed first)
- Project updates / blog posts per project

---

## Key files
| File | Purpose |
|------|---------|
| `site/fundraiser.html` | Project page + donate modal |
| `site/projects.html` | Project listing |
| `site/edit-project.html` | Recipient project create/edit form |
| `site/profile.html` | User profile (own + public view) |
| `site/admin.html` | Admin role management |
| `site/api/project-donations-api.php` | Coinos invoice creation |
| `site/api/webhook.php` | Payment webhook + queue advance |
| `site/api/fundraiser-api.php` | Project data reader |
| `site/api/save-project.php` | Project save endpoint |
| `site/api/simple-profile.php` | Profile + role management API |
| `build.sh` | Build includes |
| `deploy.sh` | Rsync to RN1 |
