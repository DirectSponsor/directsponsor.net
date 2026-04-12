# DirectSponsor — Progress Notes
_Last updated: 2026-04-08 (session 6)_

## What's done and live

### Infrastructure
- PHP 8.4-fpm on RN1, Apache vhost for `directsponsor.net`
- **HTTP/2 enabled** (2026-04-03): swapped mpm_prefork → mpm_event + php8.4-fpm; `Protocols h2 http/1.1` in SSL vhost
- File-based storage under `/var/www/directsponsor.net/userdata/`
- Build system: `build.sh site` compiles includes, `deploy.sh --auto` rsyncs to RN1
- JWT auth shared with ROFLFaucet (`roflfaucet_session` in localStorage)
- Profile files named `{userId}-{username}.txt` under `userdata/profiles/`
- Nav: logo = home link; links are Fundraisers, Posts, About (all pages via `social-layout-start.incl`)
- Login links force `https://` in `redirect_uri` even when page visited over HTTP
- **Backups** (2026-04-03): `/root/backup-rn1-directsponsor.sh` runs every 6h → servarica1 + dr4; monitored by `verify-all-backups.sh` on ES3 with Telegram alerts

### Pages (all live on directsponsor.net)
- `index.html` — homepage
- `fundraisers.html` — lists all active fundraisers from `fundraiser-api.php?action=list`
- `fundraiser.html?project=ID&user=USERNAME` — individual fundraiser page with donate modal
- `posts.html` — blog/post feed; write box for logged-in users; single post view via `?user=X&post_id=Y`
- `profile.html` — own profile (edit mode) or public profile (`?user=USERNAME`, read-only)
- `edit-fundraiser.html` — recipient creates/edits fundraiser (no `?project=` = new, auto-assigned ID)
- `edit-fundraiser.html?project=ID` — edit existing fundraiser; redirects to fundraiser page on save
- `admin.html` — admin role management UI (search users, add/remove roles)
- `about.html`, `contact.html`

### APIs (all under `/api/`)
- `fundraiser-api.php` — `action=list` / `action=get&id=X&username=Y` / `action=user_projects&username=Y`
- `project-donations-api.php` — creates Coinos invoice; passes `donor_username` through to pending entry
- `webhook.php` — payment confirmation, updates `current-amount`, auto-advances queue; writes `donations_made` to donor's profile; logs to `transaction-ledger.json`
- `save-fundraiser.php` — saves fundraiser HTML comment-tags + writes `{id}-config.json`; falls back to profile's Coinos API key
- `simple-profile.php` — profile CRUD + role management; `action=my_donations` reads `donations_made` from profile file
- `auth-proxy.php` — proxies JWT validation to auth server
- `save-post.php` — saves posts as JSON to `userdata/posts/{username}/{timestamp}-{slug}.json`; JWT auth with body fallback
- `posts-api.php` — `action=feed` (all posts, paginated) / `action=post&username=X&post_id=Y` (full post) / `action=user_posts&username=X`
- `upload-project-image.php` — image upload; returns `image_url`

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

### Live fundraisers (as of 2026-04-03)
- `lightninglova/001.html` — Bitcoin4Ghana Internet Connectivity (active)
- `evans/001.html` — Badilisha Food Forest (active; Coinos API key confirmed working 2026-04-03)
- `andytest2/001-003.html` — completed test fundraisers
- `andytest2/004.html` — active test fundraiser (partially funded)
- Grant & Annegret (Desert Farm): on hold — Bitcoin not viable in Namibia. Project page archived to `archive/grant-annegret-project.html`. May revisit if a third-party runner is found.

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
- Grant & Annegret (Desert Farm): on hold — see above

### Future
- **Reconciliation script** (done — see session 6 notes): re-run periodically with `ssh RN1 python3 /root/scripts/reconcile.py`
- **Nostr integration** — see `nostr-integration.md` for full plan
- Auth server post-verification screen: update to show all 3 sites
- `delete-user.sh`: add clickforcharity.net cleanup step
- **Lightning explainer + donor onboarding page** — need a dedicated page (e.g. `how-to-donate.html` or similar) covering:
  - Why we use Bitcoin Lightning *only*: it's the only payment method that lets us see exactly when a specific payment arrives and credit it automatically, without the platform taking custody of funds
  - Why traditional banking doesn't work: fees are unreasonable for small international amounts, payments aren't direct (go through intermediaries), and there's no reliable way to match a payment to a specific fundraiser without building a full merchant system
  - How donors can get started easily:
    1. Open a free [Coinos](https://coinos.io) account (no KYC, instant)
    2. Fund it for free via faucets: **litebits.io** and **satsman** (small amounts but enough to test/start)
    3. Or buy sats with a bank transfer via **Mt Pelerin** exchange — no KYC for small amounts, straightforward bill-payment setup
  - A step-by-step tutorial (video or illustrated walkthrough) will need to be produced at some point covering the above flow end-to-end

### Posts / Blog system (live as of 2026-04-03)
- **Single content type** — everything is a "post"
- **Two display modes**: if `body` empty → full feed card; if `body` filled → intro+image preview with "Read more →"
- **Fields**: title, intro (required, 500 char soft limit), image_url, body (optional WYSIWYG HTML)
- **Editor**: inline on `posts.html` — progressive disclosure (short post by default, ✏️ Write an article expands WYSIWYG)
- **WYSIWYG toolbar**: Bold, Italic, H2, H3, Bullet list, Numbered list, Link (external links auto-get `target=_blank`)
- **Sticky toolbar**: `position: sticky; top: 3.2em` so toolbar follows user while writing long articles
- **Character counter**: intro field shows X/500, warns orange >400, red >500, hints to use article section
- **Image upload**: reuses `upload-project-image.php`, stored in `userdata/projects/{username}/images/`
- **Feed**: loads intro-only for performance; "Load more" pagination
- **Storage**: `userdata/posts/{username}/{timestamp}-{slug}.json`
- **JWT auth**: Authorization header with body fallback (Apache strips headers)

---

## Known gotchas / bug history
- **Profile glob was backwards** — profile files are `{id}-{username}.txt`; webhook glob must be `*-{username}.txt` not `{username}-*.txt`
- **`my_donations` API needs `user_id` param** — `getUserId()` reads GET/POST params, not Authorization header; `loadMyDonations()` in `profile.html` must pass `user_id` and `username` as query params
- **`recent_donations` block missing from older projects** — stub in `save-project.php` now includes it; existing files must be patched manually: `sed -i 's|</body>|<!-- recent_donations --><!-- end recent_donations -->\n</body>|' <file>`
- **`donor_name` defaulted to Anonymous** — now falls back to `donor_username` in `storePendingProjectDonation`; even cleaner via explicit name field in modal
- **lightninglova invoice failing with `user not provided`** — old ROFLFaucet-era project HTML had no comment tags; API key was also stale (6 months old). Fix: archive old file, recreate project stub on server, get fresh Coinos API key from lightninglova
- **RN1 SSH broken after single-key migration** — `IdentityFile` line was missing from RN1 entry in `~/.ssh/config`; fixed by adding `IdentityFile ~/.ssh/id_rsa`. Also needed to add `id_rsa.pub` to RN1's `authorized_keys` via web panel
- **CSS list styling** — global `ul { list-style: none }` was overriding bullets in WYSIWYG/post body. Fixed (2026-04-03): removed the global reset; content-page emoji lists use `class="plain-list"` instead; `.wysiwyg-body ul/ol` and `.post-body ul/ol` explicitly set `list-style: disc/decimal`. All post/wysiwyg styles now live in `directsponsor-compact.css`, no page-level `<style>` blocks.
- **Apache strips Authorization header** — JWT from `Authorization: Bearer ...` header is dropped by Apache. Workaround: send JWT in request body as `jwt` field; `save-post.php` and `save-fundraiser.php` both check body as fallback.
- **Ledger stored recipient as donor_username** (fixed 2026-04-08): `webhook.php` line 412 used `$donation['username']` (= recipient) instead of `$donation['donor_username']` (= actual donor) when writing the ledger entry. Fixed to `$donation['donor_username']`. Historical entries where donor==recipient in the ledger are flagged as "suspect" by the reconcile script — they are pre-fix test/self-donations lost to the glob bug, not a financial integrity issue.

### Session 6 — Reconciliation (2026-04-08)
- Built `scripts/reconcile.py` (deployed to `/root/scripts/reconcile.py` on RN1)
- Three checks: (1) ledger entries missing from donor profiles, (2) profile `donations_made` missing from ledger, (3) project HTML `current-amount` vs ledger sum
- Found and fixed the `donor_username` ledger bug above
- Reconciliation result: **0 genuine discrepancies**. 17 historical suspect entries (pre-fix test payments, all explainable). 1 HTML amount mismatch on `andytest2/004` (+100 sats, test data, not a concern).

---

## Key files
| File | Purpose |
|------|---------|
| `site/fundraiser.html` | Fundraiser page + donate modal |
| `site/fundraisers.html` | Fundraiser listing |
| `site/posts.html` | Post feed + write box + single post view |
| `site/edit-fundraiser.html` | Recipient fundraiser create/edit form |
| `site/profile.html` | User profile (own + public view) + donations made |
| `site/admin.html` | Admin role management |
| `site/api/project-donations-api.php` | Coinos invoice creation |
| `site/api/webhook.php` | Payment webhook + queue advance + profile write |
| `site/api/fundraiser-api.php` | Fundraiser data reader |
| `site/api/save-fundraiser.php` | Fundraiser save endpoint |
| `site/api/save-post.php` | Post save endpoint |
| `site/api/posts-api.php` | Post feed / single post / user posts reader |
| `site/api/upload-project-image.php` | Image upload (fundraisers + posts) |
| `site/api/simple-profile.php` | Profile CRUD + role management + my_donations |
| `site/styles/directsponsor-compact.css` | Single stylesheet (all styles incl. posts/wysiwyg) |
| `site/cms/includes/social-layout-start.incl` | Shared nav (logo=home, Fundraisers, Posts, About) |
| `build.sh` | Build includes |
| `deploy.sh` | Rsync to RN1 |
