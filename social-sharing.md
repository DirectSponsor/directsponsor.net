# Sharing a news post

Decided 2026-10-29. Nobody here logs in to X, Facebook, or the other sites, and we are not automating those logins. The card is already done. Whoever posts only pastes the share link. The site they paste it into fetches the title, description, and image.

## What a recipient does

A recipient publishes a **News update** on DirectSponsor. The Share button copies the canonical URL:

`https://directsponsor.net/posts.html?user=X&post_id=Y`

They paste that URL into the social accounts they already have. That is the whole job on their side. How the card is built is in [HANDOFF-social-previews-and-agent-feed.md](HANDOFF-social-previews-and-agent-feed.md).

## What everyone else does

The same link becomes a task on ClickForCharity, so other people post it to the sites they already use. That side, including retiring the old link when a new one is published, is noted in [share-news-posts.md](../clickforcharity.net/share-news-posts.md).

## What we are not doing

- No X developer account, and no paid API posting.
- Hermes does not log in to those sites. A browser on the Pi is the easy case for them to block, and a lockout of a real account is a bad trade.
- The Hermes draft cron can keep writing a suggested sentence. It is optional. The text that matters is the title, the description, and the share URL.
