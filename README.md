# DirectSponsor.net

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/DirectSponsor/directsponsor.net)

**Live site: [directsponsor.net](https://directsponsor.net/)**

## Purpose
Peer-to-peer support platform using Bitcoin Lightning payments. Connects sponsors directly with verified recipients — no intermediaries hold funds. Two mechanisms: **fundraisers** (one-off campaigns) and **sponsorship groups** (ongoing monthly commitments, the primary feature). All payments go directly recipient-to-recipient via Coinos Lightning wallets.

## Structure
```
directsponsor.net/
├── site/                        # Source files
│   ├── cms/includes/            # Shared HTML snippets (.incl) — read-only, see below
│   ├── cms/templates/           # Page templates (.tmpl)
│   ├── api/                     # PHP API endpoints
│   ├── styles/                  # CSS (single stylesheet: directsponsor-compact.css)
│   ├── images/                  # Site images
│   ├── js/                      # JavaScript
│   ├── scripts/                 # Utility JS
│   └── *.html / *.php           # Pages
├── build.sh                     # Compiles includes into HTML
├── deploy.sh                    # Rsyncs built files to production
├── AGENTS.md                    # AI assistant guidance
├── PROGRESS.md                  # Session notes and bug history
└── README.md                    # This file
```

## Live Site
- **URL**: https://directsponsor.net
- **Server**: RN1 — `root@104.168.38.197`, web root `/var/www/directsponsor.net/html/`
- **SSL**: Active

## Development Workflow

```bash
# Edit source files in site/ or site/cms/includes/
# NOTE: .incl files are read-only — chmod u+w <file> before editing, chmod u-w after

bash build.sh site       # Compile includes into HTML
bash deploy.sh --auto    # Rsync to RN1 (userdata/ is protected)
```

## Pages
- **index.html** — Homepage
- **fundraisers.html** — All active fundraisers (API-driven)
- **fundraiser.html** — Individual fundraiser + donate modal (`?project=ID&user=USERNAME`)
- **posts.html** — Post feed; write box for logged-in users
- **profile.html** — Own profile (edit) or public profile (`?user=USERNAME`)
- **edit-fundraiser.html** — Create/edit fundraiser (recipients only)
- **admin.html** — Role management UI (admins only)
- **about.html**, **contact.html**, **how-to-donate.html**, **sponsorships.html**, **changelog.html**

## Key APIs (`site/api/`)
- `fundraiser-api.php` — list / get / user_projects
- `project-donations-api.php` — creates Coinos Lightning invoice
- `webhook.php` — payment confirmation; updates fundraiser HTML, advances queue, logs donation
- `save-fundraiser.php` — creates/updates fundraiser HTML + config.json
- `simple-profile.php` — profile CRUD, role management, my_donations
- `save-post.php` / `posts-api.php` — post creation and feed
- `jwt-verify.php` — shared HMAC-SHA256 JWT verification

## Authentication
JWT tokens issued by `auth.directsponsor.org`. Stored in `sessionStorage`/`localStorage`. All write-capable APIs verify the JWT signature server-side. Roles (`member`, `recipient`, `admin`) are stored in profile files on the server, not in the JWT.

## Data Storage
File-based, no database. All user data lives under `/var/www/directsponsor.net/userdata/` on RN1 and is never overwritten by deployments.

## Technical Notes
- **No framework CSS** — handcrafted `directsponsor-compact.css`, CSS table layout, `em`/`%` units
- **Vanilla JS only** — no build pipeline, no npm
- **HTTP/2** enabled on RN1 (mpm_event + php8.4-fpm)
- **Backups** — `/root/backup-rn1-directsponsor.sh` runs every 6h → two offsite servers; monitored with Telegram alerts
- **Nostr relay** — strfry on RN1 at `wss://relay.directsponsor.net`; per-user keypairs generated on first post

---

**Last updated**: July 2026
