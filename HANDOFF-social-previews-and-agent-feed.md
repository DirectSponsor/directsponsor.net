# Handoff: news posts, social cards, Share (2026-10-29 evening)

Read `AGENTS.md` first. This work is live on RN1 except the Share button, which was added after the last deploy.

## What is done

Social cards for posts work the same way as fundraisers. Apache rewrites `posts.html?post_id=` to `site/post.php`, which swaps title, description, og tags, and twitter:image in the built `posts.html`. Existing URLs did not change.

A recipient can choose **News update**. That form is title (100), description (200), optional link, optional image. Those fields *are* the card. Ordinary posts stay the write box. `save-post.php` only accepts `kind: "news"` if the profile roles include `recipient`. Kind cannot change on edit.

Hermes on the Orange Pi (`ssh orangepi5-ts`) now reads only news posts:

- Cron: `ds-social-drafts` id `a50dea6217a3`, every 3 h
- Gate: `~/.hermes/scripts/ds-recipient-posts.py` → `api/posts-api.php?action=news_feed`
- Prompt: `~/.hermes/ds-social/prompt.txt`
- Seen ids: `~/.hermes/ds-social/seen.json` (20 ids kept; empty news_feed so first news post will draft)
- Drafts: `~/work/hermes-agent/workspace/social-drafts/`
- Hermes binary: `/home/andy/.local/bin/hermes`
- One test run after the switch: succeeded, no new draft (correct — no news posts yet)

**How sharing works:** post the canonical URL. X/Facebook fetch it and draw the card. Extra tweet text is optional. The optional `link` on a news post is the story they are pointing at, not the card URL.

Nostr is unchanged: `save-post.php` still publishes a kind 1 with the post URL at the end. Nostr does not use these og tags.

## Key files

| File | Role |
|------|------|
| `site/post.php` | Per-post OG. `DS_USERDATA` override for local php -S |
| `site/.htaccess` | `post_id=` → `/post.php` |
| `site/posts.html` | News form, Share, feed labels |
| `site/api/save-post.php` | `kind`, `link`, recipient check |
| `site/api/posts-api.php` | `action=news_feed`; feed/user_posts now include `kind` and `link` |
| `site/styles/directsponsor-v2.css` | News form + Share |

## Share (in tree, not on the last deploy)

Feed cards and the single-post view have a **Share** button. One click copies `https://directsponsor.net/posts.html?user=X&post_id=Y`. If clipboard is blocked, a readonly URL field appears.

`site/posts.html` and `site/styles/directsponsor-v2.css` are dirty relative to `00b536d`. Next session: `bash build.sh site` then `bash deploy.sh --auto` (Andy OK). Changelog entry for Share is not added yet.

Local check: php -S with `DS_USERDATA=/tmp/...`, then click Share on a feed card and on a single post. Live check after deploy: `curl -A Twitterbot "https://directsponsor.net/posts.html?user=kelvin&post_id=1784829365"` — already returns that post's title/description (no image, so `og-posts.png`).

## Card field map

- title → `<title>`, `og:title` (`Title - DirectSponsor`; empty title → `{display_name} - update - DirectSponsor`)
- intro/description → `og:description`, `meta description` (max ~200)
- image_url, else `link_preview.image`, else `og-posts.png`
- canonical URL → `og:url`; `og:type` = `article`
- twitter:image is a separate tag — both image tags must be replaced (they are)

Missing/wrong-owner post_id still serves the generic posts page.

## news_feed item

`post_id, username, display_name, bio, website, title, description, body, link, link_title, image, share_url, created`

Only `kind=news` from a recipient profile. Optional `since=` is there; the Pi still uses `seen.json` as the watermark.

## What next session should do

1. Deploy the Share button (`build.sh site` + `deploy.sh --auto`).
2. Have a recipient (Kelvin or Evans) publish a real **News update**, then `curl -A Twitterbot` that URL and check the X/Facebook card validators.
3. On the Pi: `/home/andy/.local/bin/hermes cron run a50dea6217a3` and look in `social-drafts/`. Expect one file named `YYYY-MM-DD-username-post_id.md`.
4. Auto-posting via the `xurl` skill is still later. Drafts only.
5. `deploy.sh --auto` on 2026-10-29 also committed leftover notes that were already dirty: `HANDOFF-…`, `chat-private-messaging-outline.md`, `sponsor-group-chat-plan.md`, `to-dos-to-check`, nostr-relay docs. Commit `00b536d`. Do not redo that.

## Pi prompt (current)

Draft from title + description. End with `share_url`. May mention `link` in the sentence, must not replace `share_url` with it. Max 270 chars including the link. Do not invent facts.

## Do not

- Do not treat ordinary posts as news.
- Do not skip by word count.
- Do not move `seen.json` with `since=` on a failed draft run.
- Do not edit `.incl` files without chmod u+w / u-w.
- Do not deploy without Andy saying so.
