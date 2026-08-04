# DirectSponsor — Progress Notes
_Last updated: 2026-08-01 (session 15)_

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
4. Webhook fires → `webhook.php` updates `current-amount` (plain integer, no commas) in project HTML, appends `<li>` to `<!-- recent_donations -->` block
5. Goal check: if `goal-currency` + `goal-fiat-amount` are set, convert fiat target to sats at **current live rate** (same logic as fundraiser-api.php display); else fall back to `target-amount`. When `current-amount >= computed target`: file moved to `completed/`, next queued project becomes active.
6. Overpayment shown on project page; no sats lost
7. `donor_username` written to `donations_made` in donor's profile file (for profile history)
8. `transaction-ledger.json` updated as audit trail
9. Poll loop detects payment → "Payment received!" → reload

### Project queue system
- Active project = lowest numbered HTML file in `username/active/`
- On goal reached: webhook auto-moves to `username/completed/`, next becomes active
- New project auto-numbering skips IDs used in both `active/` and `completed/`
- Overpayment stays as `current-amount` on next project (no carry-over math — just shown)
- **Fiat goal is canonical**: `goal-currency` + `goal-fiat-amount` drive completion; `target-amount` is a display fallback only. `fundraiser-api.php` and `webhook.php` both use the same live conversion so displayed target = completion threshold.

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

### Live fundraisers (as of 2026-08-01)
- `kelvin/004.html` — active (Bitcoin4Ghana land purchase)
- `evans/002.html` — Badilisha Food Forest (active)
- `andytest2/001.html` — active test fundraiser
- `maibelris/001.html` — **completed 2026-08-01** — Bitcoin4Ghana fund reached 12,500 GHS goal; moved to `completed/` automatically by webhook after Jenny's 111,111 sat donation. No next fundraiser queued for maibelris yet.
- `lightninglova/001.html` — completed (earlier)
- `kelvin/001-003.html` — completed
- Grant & Annegret (Desert Farm): on hold — Bitcoin not viable in Namibia. Project page archived to `archive/grant-annegret-project.html`. May revisit if a third-party runner is found.

### Session 15 — 2026-08-01: Fiat-canonical goal system fix
- **Bug**: `webhook.php` was using the static `target-amount` (sats snapshot set at fundraiser creation) for goal completion, while `fundraiser-api.php` was showing the dynamically-computed fiat→sats equivalent. These diverge as BTC price moves, creating an inconsistency between what donors see and when completion actually fires.
- **Fix applied directly to `webhook.php` on RN1** (not in local repo):
  1. Added `FX_CACHE_FILE`/`FX_CACHE_TTL` constants + `httpGet()`/`getFxRates()`/`fiatToSats()` functions (same as fundraiser-api.php)
  2. Goal-check now reads `goal-currency` + `goal-fiat-amount`; if set, converts fiat → sats at current live rate; falls back to `target-amount` if FX unavailable or no fiat goal
  3. Stopped storing `current-amount` with commas (`number_format()` → plain integer)
- **Design decision**: fiat amount is canonical. Sats are the payment rail; recipients should withdraw often and convert. `target-amount` in HTML is a display fallback only.
- **Note**: `webhook.php` lives at `/var/www/directsponsor.net/html/webhook.php` on RN1 and is **not** in the local git repo. Always backup before editing.

---

## Payment provider notes

### Coinos outage — 2026-05-30
**Symptom:** donors see "payment service not available" after ~10s delay.
**Root cause confirmed from logs:** RN1 curl call to `https://coinos.io/api/invoice` times out with `0 bytes received` — TCP-level failure, not an API error. The Coinos website loads fine in a browser, which suggests the API endpoint (`coinos.io/api`) is on different infrastructure and is down or blocking RN1's IP.

**Affected:** `maibelris/001` — all attempts at 11:15, 11:16, 15:48, 15:53 UTC all timed out identically.

**Confirmed root cause (2026-05-30 ~17:15 UTC diagnostic):**
Cloudflare bot-challenge is blocking RN1 (`104.168.38.197`) from reaching the API.

- `curl -I https://coinos.io` → **HTTP 403** with `cf-mitigated: challenge` — Cloudflare is presenting a JS bot challenge that a headless server cannot pass
- `curl -v https://coinos.io/api/info` → TLS handshake completes fine, HTTP/2 request is sent, then **hangs for 10s with 0 bytes** — Cloudflare holds the connection waiting for a browser challenge response that never comes
- This is a server-side Cloudflare rule change on Coinos's end blocking API calls from non-browser user-agents/IPs

**Resolved ~19:00 UTC same day** — Coinos lifted the CF restriction without any report needed. Payments confirmed working (2 test payments made). Total downtime ~8 hours.

**Retained for reference in case it recurs** — if it does, use this message for Coinos support:
> Our server (IP 104.168.38.197) can no longer reach your API. TLS connects fine but all HTTP requests hang. `curl -I https://coinos.io` from our server returns HTTP 403 with `cf-mitigated: challenge`. It appears Cloudflare is now blocking non-browser requests, which breaks server-to-server API calls. Can you whitelist server IPs or add a Cloudflare rule to allow API traffic without browser challenge?

---

### Self-hosted Lightning node — assessment

**Previously tried and abandoned.** The blocker is not server cost but channel economics:

- To be reliably reachable on Lightning you need well-connected channels, which means either opening channels with a large, well-connected node (costly fee) or tying up significant bitcoin in channels yourself
- A node with poor connectivity means payments fail or route badly — worse UX than a custodial service
- This is a structural problem with Lightning today: being your own node and being well-connected are both expensive

**Long-term vision (noted for future):** each recipient project runs its own node — fully decentralised, no central custodian. This is the right direction but has two hard prerequisites:
1. Running a node must become extremely cheap (server + channel management)
2. Connectivity/routing must work without needing to pay for access to a large hub — i.e. Lightning (or a successor) reaches a state where everyone can be their own well-connected node at low cost

Until both conditions are met, custodial services (Coinos, Blink) are the pragmatic choice. The platform should be designed so switching to self-hosted nodes later is possible per-project without a central migration — each `{id}-config.json` already has a `type` field for this reason.

---

### Blink (blink.sv) — alternative Lightning wallet provider

**What it is:** Open-source custodial Lightning wallet originally built for Bitcoin Beach, El Salvador. Has a public API, works globally including Africa. Status page: https://blink.statuspage.io

**API overview (as of 2026-05):**
- GraphQL API at `https://api.blink.sv/graphql`
- Auth: `X-API-KEY: blink_...` header (API keys from dashboard.blink.sv)
- Create invoice: `lnInvoiceCreate` mutation → returns `paymentRequest` (bolt11) + `paymentHash`
- Webhooks: registered per-account (not per-invoice) via `callbackEndpointAdd` GraphQL mutation or Blink Dashboard; event type `receive.lightning`; uses Svix (exponential backoff retries)
- Webhook payload contains `walletId`, `paymentHash`, `settlementAmount`, `status`
- Registration: phone number required (unavailable in US and some other countries)
- Fees: free for intra-Blink; ~0.02% Lightning routing fees

**Advantages over Coinos:**
- Designed for developing-world use (El Salvador → Africa); anecdotally more stable
- Svix-backed webhooks with automatic retries (Coinos webhooks have no retry)
- Status page exists for monitoring
- Open source (Galoy stack) — can self-host eventually

**Disadvantages / differences requiring code changes:**
- GraphQL vs REST — bigger rewrite of `project-donations-api.php` and `webhook.php`
- Webhook is per-account, not per-invoice: can't embed a `secret` in the invoice. Webhook auth uses Svix-signed payloads (HMAC) instead
- Webhook identifies the recipient by `walletId` — need a lookup table: `walletId → username` (stored in each `{id}-config.json`)
- No `memo` field in webhook payload — matching to pending donation must be done by `paymentHash`
- Phone number required to sign up — potential barrier for some recipients

**Implementation plan when ready:**
1. Add `"type": "blink"` support to `{id}-config.json` (store `api_key` + `wallet_id`)
2. New invoice path in `project-donations-api.php`: GraphQL `lnInvoiceCreate`, same return shape
3. Update `webhook.php` to handle Blink's payload structure alongside existing Coinos format
4. Each recipient registers at dashboard.blink.sv, adds `https://directsponsor.net/webhook.php` as callback endpoint once

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
    post-notify-state.json     # watermark + per-user cooldowns for Telegram post notifications
  logs/
    project_payments.log
    webhook.log
```

---

## Pending / next priorities

### Soon
- Grant & Annegret (Desert Farm): on hold — see above
- **maibelris next fundraiser** — no `002.html` queued; they need to create a new one via edit-fundraiser.html if they want another round

### Recipient guidance page (needed before onboarding more recipients)
Key points to cover:
- **Withdraw immediately when your fundraiser completes** — Coinos has a built-in facility to convert sats to local currency (GHS, KES, etc.) and receive via mobile money. Do this within hours of completion, not days.
- **Why:** the goal is denominated in your local currency, but the sats sit in a custodial wallet. BTC price moves between receipt and conversion mean you might get slightly more or less than the goal amount if you wait. Converting immediately eliminates that risk.
- **For savings clubs (susu-style):** the pot accumulates week by week. The round's recipient should NOT touch the wallet until the fundraiser goal completes — then withdraw everything at once. Contributors are separate from the current recipient.
- **For ongoing sponsorship recipients:** withdraw and convert regularly (e.g. each time a meaningful amount arrives). Don't hold sats speculatively.
- The system tracks cumulative sats received; it doesn't track your wallet balance. Withdrawing does not reset your fundraiser progress.
- Goal is shown as "X sats (≈ Y [local currency])" — the sat number varies with BTC price but the local currency amount is the fixed target.

### Cleanup (once all old fundraisers complete)
- **Remove Tier 2 & 3 fallbacks from `webhook.php`** — once `evans/002` and `andytest2/001` complete (both lack `current-fiat-amount`), all active fundraisers will use Tier 1 fiat tracking. At that point, the live-rate-fiat (Tier 2) and sats-only (Tier 3) branches in the goal-check can be deleted, simplifying the code. Check: any fundraiser without `<!-- current-fiat-amount -->` in its HTML is a candidate — run `grep -rL 'current-fiat-amount' /var/www/directsponsor.net/userdata/projects/*/active/` on RN1 to confirm none remain before removing.

### Future
- **Per-fundraiser Open Graph meta tags** ✅ Step 1 done (2026-06-18) — `site/fundraiser.php` wrapper + `site/.htaccess` rewrite. Apache transparently routes `fundraiser.html?project=X&user=Y` through the PHP wrapper, which reads `title`, `description`/`short-description`, and `image-url` from the fundraiser's comment-tags and injects correct OG tags server-side. Works for all existing fundraisers automatically. Static pages already had per-page OG tags via the `#TITLE#`/`#DESC#`/`#OGIMAGE#` substitution system.
  - **Step 2 (optional polish):** branded composite OG image — overlay title + "X sats raised of Y" on the project photo using PHP GD. Services like opengraph.xyz do this commercially; we could replicate it. Low priority.
- **Reconciliation script** (done — cron Sunday 3am on RN1, Telegram alert via DS_AuthBot to satoshihost-alerts group)
- **Nostr integration** — see `nostr-integration.md` for full plan; deeper integration (cross-node identity, flagging, fraud prevention) still pending
- **Nostr zaps on fundraisers** — Coinos has no account-level webhook (webhook is per-invoice only). Direct payments to a lightning address (e.g. via Nostr zap) go into the wallet silently. Two approaches when this becomes relevant:
  1. **Cron polling**: `GET /api/payments?after={timestamp}` using each recipient's API key every 15 mins — match unrecognised incomings to the active fundraiser and credit them. Small PHP/Python script, no changes to main flow.
  2. **Zap invoice**: when publishing fundraiser to Nostr, generate a long-lived invoice (with our webhook) instead of/alongside the lightning address. Zaps via that invoice are fully tracked. Spontaneous direct-address payments still not caught.
  We have our own Nostr relay which may help with publishing/receiving zap receipts. Start with option 1 (polling) as it requires no Nostr-specific code.
- **PHP error alerting** — use `set_error_handler()` + `error_log()` in the API files to catch unexpected errors, with a lightweight cron (or triggered script) that tails `/var/log/` and fires a Telegram alert via DSSitesCheckBot. No external dependencies; pure PHP + cron + existing bot.
- Auth server post-verification screen: update to show all 3 sites
- `delete-user.sh`: add clickforcharity.net cleanup step

### Sponsorship groups (Phase 1 live — 2026-05-03)

Phase 1 is deployed. Recipients can set up a group; sponsors can join the queue; recipient manages tier assignments.

**Live files:**
- `site/api/sponsorship-api.php` — actions: `list`, `get`, `join`, `leave`, `manage`, `setup`
- `site/sponsorships.html` — public listing of all groups
- `site/profile.html` — sponsorship sections added (public view with join button; recipient management; "Groups I'm Sponsoring")
- Data: `userdata/sponsorship-groups/{username}.json` — one file per recipient

**What's working:**
- Recipient (from profile page): creates/edits group description + suggested monthly sats; sees member list; promotes/demotes members between active/standby/queued tiers; removes members
- Sponsor (from sponsorships.html or recipient's profile page): joins queue; leaves group; sees their tier
- Public: sponsorships.html lists all groups with tier counts; recipient profile page shows group status + join button

**Phase 2 — Payments (live 2026-05-03):**

### Sponsorship payments — how it works

Payments are per calendar month. The system tracks `last_paid_month` (YYYY-MM) per member in the group JSON file. Duplicate payments for the same month are rejected by the API.

**Grace window (5-day rule):**
- Days 1–5 of the month: sponsor pays for the **current** month
- Day 6 onwards: sponsor pays for the **next** month
- Never more than one month ahead — prevents sponsors pre-loading funds against uncommitted income

**Data stored per member in group file:**
```json
"last_paid": "2026-05-03",
"last_paid_month": "2026-05"
```

**UI behaviour:**
- Not yet paid → `⚡ Pay for May 2026` button (large, on its own line)
- Already paid → `✓ Paid for May 2026` (greyed out, no button)
- Modal: QR code + invoice text + 📋 Copy Invoice button + status line
- On payment confirmed: modal stays open showing ✅ success message; sponsor closes manually; page reloads to show paid state

**API actions added to `sponsorship-api.php`:**
- `pay` (POST) — validates month format, checks grace window, checks duplicate, creates Coinos invoice, stores pending payment
- `check_payment` (GET) — polls pending payment status

**Webhook (`webhook.php`):**
- Detects sponsorship payments by `payment_type: sponsorship` in pending record
- Calls `processSponsorshipPayment()` — updates `last_paid` + `last_paid_month` in group file, writes to transaction ledger

**Infrastructure fixes made alongside payments (2026-05-03):**
- `save-fundraiser.php`: when a Coinos API key is saved to the project config, it is now also synced to the recipient's profile file — so sponsorship payments (which read from the profile) work automatically for any recipient who has set up a fundraiser
- `social-layout-start.incl`: nav profile link for logged-in user now goes to `profile.html` (no `?user=` param) instead of `profile.html?user=USERNAME` — the own-profile path triggers lazy auto-creation of the profile file; the public path (`?user=X`) does not
- `simple-profile.php`: `loadProfileData()` already auto-creates a minimal profile file on first own-profile GET (lazy-loads from auth server if possible, otherwise creates default)

**Phase 3 still to build:**

### Sponsorship groups — Phase 3 and beyond

- **Monthly payment flow**: ~~"Pay this month" button for active sponsors → Coinos invoice (same flow as fundraisers); payment recorded per-month per-sponsor in group file~~ ✅ done
- **Reminder + response-window system**: ~~monthly cron dispatches reminders by **email**; tracks who has responded; non-responsive actives demoted after window closes~~ ✅ done (2026-05-28). See `sponsorship-reminder-plan.md` for full design and decisions.
  - Grace window: **7 days** (days 1–7 pay current month; day 8+ pays next month)
  - Reminders: email on day 1, 4, 7 via auth server `send-notification.php` endpoint
  - Demotion: day 8 cron sets `slots=0`, emails sponsor + recipient, Telegram alert to admin
  - Amount: **server-enforced** — `slots × $10` converted to sats via mempool.space price API; client-supplied amount ignored
  - Double payment: blocked server-side (payments history check)
  - BTC price source: `mempool.space/api/v1/prices` (curl, IPv4-forced) — switched from CoinGecko 2026-07-01 after rate-limit errors blocked payments
  - Slot model: **full or available** — no waitlist/queue; if slot opens, admin notified via Telegram, recipient finds replacement through own network
- **Automatic promotion logic**: not needed — slot model is full/available only; admin handles replacement manually when Telegram alert fires
- **Recipient group tools**: common fund accounting (income/outgoings, all members visible), coordinator action log, group decision documentation
- **Browser push / in-tab notifications**: Web Push API (service worker + VAPID keys) for payment arrival alerts to recipients. Decision: not needed for Phase 1 (no action required on join); revisit for Phase 2 when payments land. In-tab notifications (Notification API) are a simpler fallback if recipient has a tab open.
- **Network architecture**: DS is designed as independent nodes linked via Nostr — not a growing central platform. `directsponsor.net` is proof-of-concept. Deeper Nostr integration (cross-node identity, shared sponsor queues, flagging) is on the roadmap.

Design principles (structural, not rules):
- Max 12 sponsors per group
- Money never passes through an intermediary — payments go sponsor→recipient directly
- Common fund (if any) is collectively controlled — no single person has discretionary power
- Coordinator role is administrative only (carries out group decisions, no financial discretion)
- Reputational accountability: violating the DS definition loses network access, not legal sanction

### Comments — future optimisations (not urgent at current scale)
- **Cached comment count**: store `comment_count` in the post's own JSON file (updated by `comments.php` on write/delete) so the feed doesn't need to read the comments file per post — currently fine up to ~50 active posts
- **Comment pagination**: if a post accumulates >50 comments, paginate (API already has all data; add `?offset=` param and a "Load more" button in the UI)
- **How to Donate page** (done — `how-to-donate.html`): covers Lightning rationale, Coinos signup, faucets, Mt Pelerin. Still needs:
  - Video walkthrough (pending Adam confirming account deletion/recreation on Coinos)
  - Faucet details for litebits.io and satsman filled in
  - Recipient cash-out info (how lightninglova/evans convert sats to local currency)
- **Lightning explainer + donor onboarding page** — need a dedicated page (e.g. `how-to-donate.html` or similar) covering:
  - Why we use Bitcoin Lightning *only*: it's the only payment method that lets us see exactly when a specific payment arrives and credit it automatically, without the platform taking custody of funds
  - Why traditional banking doesn't work: fees are unreasonable for small international amounts, payments aren't direct (go through intermediaries), and there's no reliable way to match a payment to a specific fundraiser without building a full merchant system
  - How donors can get started easily:
    1. Open a free [Coinos](https://coinos.io) account (no KYC, instant)
    2. Fund it for free via faucets: **litebits.io** and **satsman** (small amounts but enough to test/start)
    3. Or buy sats with a bank transfer via **Mt Pelerin** exchange — no KYC for small amounts, straightforward bill-payment setup
  - A step-by-step tutorial (video or illustrated walkthrough) will need to be produced at some point covering the above flow end-to-end

### Comments system (live as of 2026-04-13)
- **Storage**: `userdata/comments/{username}-{post_id}.json` (keyed by post author + post ID)
- **API**: `site/api/comments.php` — GET to read, POST to write/delete; JWT required to post
- **One level of threading**: top-level comments (newest first) + replies (oldest first, collapsed by toggle)
- **Feed integration**: `posts-api.php` now returns `comment_count` per post (reads comments file); displayed as "💬 3 comments" or "💬 Comment" link on every feed card
- **Auth**: must be logged in to comment; guests see a login prompt
- **Delete**: users can delete their own comments (also removes replies)
- **Note**: `mbstring` PHP extension not installed on RN1 — use `strlen()` not `mb_strlen()` in all API files

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
- **Profile `username` field blank on creation** (fixed 2026-06-07): profile files were created with the correct `{id}-{username}.txt` filename but `"username": ""` in the JSON. Cause: `site-utils.js` called `simple-profile.php?action=profile` without passing `username`, so `getUsername()` returned `""` and the new-profile path wrote a blank field. Fixed by (1) passing `username` in the profile fetch in `site-utils.js`, and (2) adding a backfill in `loadProfileData()` that patches and saves the file if `username` is blank but a hint is now available. Admin search (`searchProfiles`) matches on the JSON field, so users with blank usernames were invisible to search.
- **`.well-known/` excluded from deploy** (fixed 2026-06-07): `deploy.sh` rsync had `--exclude='.*'` which excluded the `.well-known/` directory. Fixed by adding `--include='.well-known/'` and `--include='.well-known/**'` before the exclude rule.
- **Ledger stored recipient as donor_username** (fixed 2026-04-08): `webhook.php` line 412 used `$donation['username']` (= recipient) instead of `$donation['donor_username']` (= actual donor) when writing the ledger entry. Fixed to `$donation['donor_username']`. Historical entries where donor==recipient in the ledger are flagged as "suspect" by the reconcile script — they are pre-fix test/self-donations lost to the glob bug, not a financial integrity issue.

### Session 12 — JWT signature verification (2026-07-01)

**Root cause (critical — fixed):** All write-capable API endpoints (`sponsorship-api.php`, `save-fundraiser.php`, `save-post.php`, `comments.php`, `simple-profile.php`) were decoding JWT payloads with `base64_decode` only — **no HMAC signature check**. Any attacker could forge a token claiming to be any user, bypass all role checks, and redirect donations (by changing the Coinos API key on a fundraiser), post as any user, or grant themselves admin.

**Fix:**
- New `site/api/jwt-verify.php`: shared `verifyJwt($jwt)` + `getCallerFromJwt($input)` — performs proper HMAC-SHA256 signature check using `hash_equals()` (timing-safe) before trusting any payload claim.
- JWT secret (`simple_secret_2025`) stored server-only at `/etc/ds-jwt-secret` (owner: `root`, group: `www-data`, mode: `640`) — never in git.
- All five affected endpoints now `require_once 'jwt-verify.php'` and use `getCallerFromJwt()`.
- `simple-profile.php` POST actions now require a verified JWT (identity from JWT, not GET/POST params); `search` (admin-only GET) also requires JWT in Authorization header.
- Removed hardcoded `define('JWT_SECRET', ...)` from `save-fundraiser.php`.
- `save-post.php` username fallback from POST body also removed (was allowing arbitrary identity claims).
- `save-fundraiser.php` was already wired to a non-existent `jwt-verify.php` — creating the file with real verification completes that fix.

### Session 11 — Security audit (2026-06-28)

Full security audit completed. All issues fixed and live-verified.

**1. Webhook authentication (critical — fixed)**
- Root cause: Coinos sends the shared secret in the **JSON body** (`"secret": "..."`) not as an HTTP header. The existing header-based check (`HTTP_X_COINOS_SIGNATURE`) was dead code — the webhook was completely unauthenticated.
- Fix: removed header check and dead `verifyWebhookSignature()` function; added `hash_equals(WEBHOOK_SECRET, $webhookData['secret'])` check after JSON parse.
- Also: the raw payload (including plaintext secret) was being logged to `webhook.log` — fixed to log `[secret redacted]` instead.
- Live-tested: curl with no secret → 401; real payment → processed successfully.

**2. Path traversal fixes**
- `simple-profile.php`: `$userId` from GET/POST params was going unsanitised into file paths. Fixed: `preg_replace('/[^a-z0-9_\-]/i', '', ...)` applied to `$userId`, `$targetUserId`, and `$requesterId` at point of retrieval.
- `project-donations-api.php`: `project_id` and `username` from POST body were unsanitised. Fixed: stripped to `[a-z0-9_\-]` before passing to `createProjectInvoice()`.
- `fundraiser-api.php`: `$_GET['id']` (`$fundraiserId`) was unsanitised in file paths (low severity — `.html` suffix limited exploitability). Fixed: same strip pattern applied.
- `project-management-api-v2.php`: legacy API with no JWT auth on write actions, and `project-editor.js` (its only consumer) is not included by any HTML page. All write actions now return 410 Gone. `get_project_public` kept but `$projectId` sanitised.

### Session 10 — Nostr visibility + profile username fix (2026-06-07)
- **External relay broadcasting**: `save-post.php` now broadcasts every new post to `relay.damus.io`, `relay.primal.net`, and `nos.lol` in addition to the private relay. Added SSL support to `nostr_publish_ws()`.
- **Kind 0 metadata**: `save-post.php` publishes a kind 0 event (username + NIP-05 identity) on a user's first post. Controlled by `nostr_metadata_published` flag in profile file — fires once per user, then skipped. Covers both new and existing accounts (triggers on next post).
- **NIP-05 verification**: `site/.well-known/nostr.php` serves `username → pubkey` mappings for all DS users. Accessible at `https://directsponsor.net/.well-known/nostr.json` (Apache rewrite in SSL vhost). Deploy script updated to include `.well-known/` (was excluded by `--exclude='.*'`).
- **Profile username backfill**: `simple-profile.php` now backfills blank `username` in existing profile files when a hint is available. `site-utils.js` now passes `username` in the profile fetch so the hint reaches the server. Root cause: profile files were created with correct filename but blank JSON `username` field.
- **`avatar` → `picture` refactor**: renamed the profile field to match Nostr's field name; dropped the `uploaded:` prefix hack; plain URL stored directly. Auto-migration in `loadProfileData()` converts existing profiles on first load. Added a working profile picture upload UI to `profile.html` (circular preview, upload, remove). Picture now propagates to Nostr `picture` field in Kind 0 on every profile save.
- **Lightning address moved to all users**: previously recipient-only; now in Public Info section so any user can set it (for Nostr zaps via `lud16`).

### Nostr identity design note
- **NIP-05 handle** (`andy@directsponsor.net`) is a convenience label — server-dependent, breaks if the domain lapses, but is just a pointer
- **Keypair** (`nostr_privkey` / `nostr_pubkey`) is the actual identity — cryptographic, portable, server-independent. Users own it forever and can import it into any Nostr client
- This reinforces the decentralised node model: each DS node issues its own `username@node.tld` handles, but all users' Nostr identities are portable and interoperable across nodes

### Session 8 — Post notifications (2026-04-27)
- Created `@DSSitesCheckBot` (Telegram) — general-purpose site alerts bot
- Credentials stored in `/root/.telegram-sites-check` on RN1 (chmod 600)
- Built `/root/scripts/notify-new-posts.py` — scans `userdata/posts/*/*.json`, fires one Telegram message per user for posts newer than watermark; 10-min per-user cooldown
- State file: `/var/www/directsponsor.net/userdata/data/post-notify-state.json`
- Cron installed on RN1: `0 * * * *`, log: `/var/log/ds-post-notify.log`
- Disabled (de-scheduled) the broken `check_links.py` GitHub Actions workflow in `satoshihost/monitors` repo (CFC PTC page JS renderer failing; manual trigger still available)

### Session 6 — Reconciliation (2026-04-08)
- Built `scripts/reconcile.py` (deployed to `/root/scripts/reconcile.py` on RN1)
- Three checks: (1) ledger entries missing from donor profiles, (2) profile `donations_made` missing from ledger, (3) project HTML `current-amount` vs ledger sum
- Found and fixed the `donor_username` ledger bug above
- Reconciliation result: **0 genuine discrepancies**. 17 historical suspect entries (pre-fix test payments, all explainable). 1 HTML amount mismatch on `andytest2/004` (+100 sats, test data, not a concern).

---

## Session 14 (2026-07-28) — Nostr testing & post preview fix

### Verified working
- `kelvin@directsponsor.net` NIP-05 resolves correctly (`/.well-known/nostr.json` Apache rewrite confirmed live)
- kelvin's kind 0 metadata event is on the local strfry relay with full profile (name, nip05, display_name, about, website)
- andytest2 kind 0 confirmed on relay; profile page npub/nsec reveal UI already built and working
- **Recommended test client: primal.net** (not iris.to — unmaintained). Direct profile URL: `https://primal.net/p/<npub>`
- andytest2 npub: `npub1xml526kk4ckmj2ed0sd07ukx7ypq8k5ye4nj8j5vp8rsh5szez4qqyysg5`
- Keypair is only generated when user saves their first post on DS — blank profiles have no Nostr identity yet

### Fix: DS post URL now inline in Nostr event content (`save-post.php`)
- **Problem:** old posts published to Nostr had no preview card in Primal; DS post URL was only in the `r` tag (machine-readable, not rendered)
- **Fix:** DS post URL now appended as a bare URL on its own line in the event `content` — Nostr clients fetch our OG meta tags from it and render a preview card (title, description, image)
- `r` tag kept alongside for machine-readable relay hints — no duplication
- Deployed 2026-07-26. Old posts need a re-save to republish their Nostr event with the URL included.

### Known display issues in Primal (needs research)
- **Profile image not showing** — kind 0 includes `picture` field but Primal isn't rendering it; unclear if it's a relay propagation delay, a field name issue, or Primal-specific behaviour
- **Post text renders as one line** — DS post body is HTML; when stripped to plaintext for the Nostr event content (`strip_tags()`), newlines from the HTML structure are lost. Nostr clients treat `\n` as line breaks but our stripped content likely collapses to a single block.
- **General problem:** Nostr clients vary widely in what they render — images, formatting, link previews, NIP-05 badges. There is no single "correct" output; need to research what the major clients (Primal, Damus, Amethyst, Snort) each support and find the best compromise.
- **Research needed before fixing:** check NIP-94 (file metadata), NIP-23 (long-form), and whether kind 1 is even the right event type for DS posts (which have title + body, more like articles). Also check how `strip_tags()` output can preserve paragraph breaks.
- **Key question: kind 1 vs kind 30023 (NIP-23 long-form)** — DS posts have a title + rich body, which is exactly what NIP-23 is designed for. Kind 1 is for short social notes. Switching to kind 30023 would give proper title rendering in clients that support it (Primal does), and the formatting issue largely goes away because the body is expected to be Markdown rather than stripped plaintext. This is probably the most important research item before doing any further Nostr post formatting work.

### Next step: LNURL-pay endpoint for zaps
- Users cannot accept zaps yet — no LNURL-pay endpoint
- **No Lightning node needed** — thin PHP file calls Coinos API (same as donate modal)
- Endpoint needed: `/.well-known/lnurlp/{username}` → reads user's Coinos API key → returns LNURL metadata / bolt11 invoice
- Spec in `lnurl-zap-integration.md`
- Webhook already handles payments; zap receipts (kind 9735) to be published on payment confirmation

---

## Session 13 (2026-07-16) — Bug fix: stale JWT causing silent auth failure

**Bug:** Recipients (e.g. Evans) could see the edit-fundraiser form but got "Authentication required" on submit.

**Root cause:** The July 1 security update added proper HMAC-SHA256 + expiry verification to all write APIs (`save-fundraiser.php` etc). However, the client-side role/profile check is a plain GET with no JWT verification — so a user with an expired JWT in `localStorage` from a prior session could still see the form, but the server correctly rejected the expired token on save.

**Fix:** Added a client-side expiry check in `cms/includes/doctype-head.incl`. On every page load, if the stored JWT is expired it is immediately purged from both `sessionStorage` and `localStorage`. The user then sees "Login required" instead of a form that silently fails on submit.

**Note:** Any user who was logged in before July 1 (when HMAC verification was introduced) may have had a pre-existing stale token in `localStorage`. The fix handles all of them automatically on their next page load.

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
| `site/api/jwt-verify.php` | Shared JWT HMAC-SHA256 verification (reads secret from `/etc/ds-jwt-secret`) |
| `build.sh` | Build includes |
| `deploy.sh` | Rsync to RN1 |

---

## Strategic Note: Chat — Nostr vs Custom (2026-07-30)

### Current situation
- roflfaucet.com has a custom flat-file PHP chat with bots (Anzar rainbot, ROFLBot), coin tipping (`/tip`), and rain events
- directsponsor.net embeds this chat in an iframe on the homepage and sponsorships page
- The custom chat has known issues: `/tip` sender deduction failing (auth server API call broken), bot messages sometimes not appearing until next send (timestamp `>` filter bug + possible Orange Pi bot version mismatch)

### Nostr chat option considered
Nostr (NIP-28 public channels) was evaluated as a replacement or complement. Key points:

**Fits well:**
- directsponsor.net already publishes posts to the strfry relay at `wss://relay.directsponsor.net` — chat is a natural extension
- Users with existing Nostr identities (Damus, Amethyst, etc.) can participate without a new account
- Per-user keypairs already generated on first post — bots are just another keypair
- **Zaps (NIP-57)** provide native Lightning tipping on any message — real sats, no custom coin layer needed
- Decentralised — messages replicate across relays

**Doesn't fit well:**
- UselessCoin mechanics (faucet earnings, rain events, gamification) don't translate to Nostr
- Embedding a Nostr client widget in an iframe requires JS-heavy libraries (against frugal philosophy)
- Moderation harder — permissionless by design, spam falls back on relay write policy

### Decision
- **directsponsor.net**: replace the embedded roflfaucet chat iframe with a Nostr-backed chat — see server-side proxy approach below
- **roflfaucet.com**: keep custom chat for now — the coin mechanics (faucet, rain, UselessCoins) are the point; Nostr doesn't replace that
- **Zaps complement rather than replace UselessCoins**: zaps = real-value serious support; UselessCoins = low-friction fun/gamification
- **Mobile**: fitting a persistent chat widget into a 390px layout fights physics — the floating panel is already the right pattern; don't try to add more
- **Immediate priority**: fix current roflfaucet chat bugs first (tip failure, bot display issue), then build the DS chat page

### Server-side Nostr client proxy (planned for directsponsor.net)

Rather than embedding a JS-heavy Nostr client or sending users to an external Nostr app, build a thin PHP chat page that acts as a Nostr client on the server:

**How it works:**
1. User visits `chat.html` (or similar) — logged in via existing JWT
2. Server looks up their Nostr keypair from their profile file (already stored as `nostr_privkey`/`nostr_pubkey`)
3. PHP page polls strfry on localhost for recent NIP-28 channel messages and renders them as plain HTML
4. User types a message → PHP signs it with their keypair (using `nostr-sign.py` pattern from `save-post.php`) → publishes to strfry
5. Bots are just Nostr keypairs that subscribe and respond

**Why this works:**
- All the hard parts already exist: keypairs in profiles, strfry relay on localhost, WebSocket signing pattern in `save-post.php`, `nostr-sign.py` for BIP340 signing
- User sees a plain chat interface — no Nostr knowledge required
- No external dependencies, no JS libraries, no iframes
- Zaps still possible for users with Lightning wallets (they can use a real Nostr client if they want)
- For users who don't want Nostr at all: a simple "Join chat →" link to `primal.net` or `snort.social` pointed at the channel works as zero-effort fallback

### Write policy — relay-level allowlist

**Goal**: reputation control, not secrecy. We don't care who reads the chat. The point is stopping unvetted accounts from posting into a stream associated with the org — without gatekeeping reads or touching existing public posts.

**How**: set a **per-event-kind write policy** on the relay:
- Chat message kind → only accepted from pubkeys on an allowlist
- Post kind (and everything else) → accepted from anyone, unchanged
- Reads remain open to everyone for all kinds

Every Nostr event is signed by the author's pubkey — the relay can inspect and accept/reject based on that signature without any login/session step.

Add a **rate limit** on the chat kind even for allowlisted users — cheap insurance against a compromised or spammy approved account.

**What NOT to use:**
- **NIP-42** (relay-level connection auth) — restricts who can even *connect* to the relay. Too blunt; we only want to restrict one event kind.
- **NIP-17/NIP-44** (encrypted DMs/groups) — makes content confidential. Not needed; we don't care about read-privacy, only write-control.

**Implementation**: strfry supports write-policy plugins/scripts — check what the existing relay config already has before building custom. Maintain a flat allowlist file of permitted pubkeys for the chat kind.

### Open items before shipping
- How new pubkeys get added to the chat allowlist: manual admin approval vs. auto-add for verified site accounts (i.e. anyone with a DS profile keypair)
- Whether to run a fallback/backup relay — chat availability now depends on this relay staying up
