# Handoff: per-post social previews + agent feed (2026-10-29)

Status: investigation done, plan approved in principle, **nothing changed yet** (no code edits, nothing deployed).
Read `AGENTS.md` first. Ask Andy before deploying to RN1, pushing, or deleting anything.

## Background

Hermes Agent on the Orange Pi (`ssh orangepi5` from hp3) runs cron job `ds-social-drafts`
(id `a50dea6217a3`, every 3 h). Gate script `~/.hermes/scripts/ds-recipient-posts.py` reads
`api/posts-api.php?action=feed`, keeps new posts by users with role `recipient`
(via `simple-profile.php?action=public_profile`), and Hermes writes X drafts to
`~/work/hermes-agent/workspace/social-drafts/`. Prompt: `~/.hermes/ds-social/prompt.txt`.
State: `~/.hermes/ds-social/seen.json`. Drafts only; auto-posting via the `xurl` skill comes later.
Pi checked 2026-10-29: hermes.service + both dashboards running, cron last run OK.
Setup notes: `~/work/hermes-agent/HERMES_INSTALL_PLAN.md`.

## Findings

1. **Every post link shows the same generic card.** `curl -A Twitterbot "https://directsponsor.net/posts.html?user=kelvin&post_id=1784829365"`
   returns title "Posts - DirectSponsor", stock `og-posts.png`, and `og:url` = `https://directsponsor.net` (homepage).
   Posts are rendered by JavaScript, crawlers don't run JS.
2. **The fix pattern already exists** for fundraisers: `site/fundraiser.php` reads the fundraiser file and
   `str_replace`s the og/twitter/title tags in `fundraiser.html`; `site/.htaccess` rewrites
   `fundraiser.html?project=` to it (`RewriteCond %{QUERY_STRING} (?:^|&)project=`).
3. **Post storage:** `userdata/posts/{username}/{post_id}-{slug}.json` with fields
   `post_id, username, user_id, title, intro, body (HTML), image_url, link_preview{url,title,description,image,domain,blocked}, created, updated, nostr_*`.
   Titles are often empty.
4. **Current API** (`site/api/posts-api.php`): `feed` (all users, 20 max, intro only, `has_body` flag),
   `post` (full JSON), `user_posts`. No role filter, so the Pi does 1 feed call + 1 profile call per user + 1 post call per post with a body.
5. **Profile** (`simple-profile.php?action=public_profile`) gives `display_name` (kelvin = "Bitcoin4Ghana"),
   `bio`, `website`, `location`, `picture`, `roles`.
6. **Draft quality:** the only draft so far is from Kelvin's test post "the links preview also works fine",
   a trivial message that shouldn't really be a tweet.
7. Most recent feed posts are from `andytest2` testing link previews; publish0x links come back
   `blocked`/"Just a moment..." (Cloudflare blocks our preview fetcher). Not part of this task.

## Recommended plan

1. **`site/post.php`** (copy the fundraiser.php approach, ~60 lines):
   - `.htaccess`: `RewriteCond %{QUERY_STRING} (?:^|&)post_id=` + `RewriteRule ^posts\.html$ /post.php [L,QSA]`
   - Sanitize `user` / `post_id` like posts-api.php, glob `userdata/posts/{user}/{post_id}-*.json`.
   - og:title = post title, else "{display_name} – update" (display_name from the profile file, else username).
   - og:description = intro stripped of tags, max ~200 chars.
   - og:image / twitter:image = post `image_url` → `link_preview.image` → stock `og-posts.png`. Make relative URLs absolute.
   - og:url = canonical `https://directsponsor.net/posts.html?user=X&post_id=Y`; og:type `article`.
   - Replace the exact tag strings in the **built** `site/posts.html` (check them after `build.sh`).
   - Existing links (including the ones in drafts) start working with no URL change.
   - Test locally (php -S with a temp USERDATA copy, or make the path overridable), then verify with
     `curl -A Twitterbot` after deploy and the X/Facebook card validators.
2. **`action=recipient_feed` in posts-api.php**: only posts from users whose profile roles include `recipient`;
   each item has plain-text full body (strip tags), title, display_name, project website + bio, image,
   share URL, created; optional `since=<unix ts>`. Keep it JSON (RSS can come later). One call per run for the Pi.
3. **Pi**: switch the gate script to `recipient_feed` (keep the seen.json logic and first-run behaviour), and
   tighten the prompt; then run `hermes cron run a50dea6217a3` and check the drafts folder.
4. Deploy: `bash build.sh site` then `bash deploy.sh --auto` (RN1), **only with Andy's OK**.

## Open question for Andy

- Should trivial/off-topic recipient posts (e.g. under ~15 words, or not about the project) be skipped,
  or drafted anyway for Andy to discard? Skipping can be done in the gate script (word count) and/or the
  prompt ("if the post isn't a real project update, write no draft").
