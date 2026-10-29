# Sponsor Group Chat — Plan

**Status:** Agreed direction, not started. Build this *after* the core
sponsorship-group flow (join/leave/slots/payments) is proven live —
per Andy, 2026-10-20.

**Supersedes:** the "small private groups" part of
`chat-private-messaging-outline.md`. The rest of that doc (1:1 DMs) is
dormant, not cancelled — see "Later / optional" below.

---

## Decisions (agreed)

- **Not private.** Group chat messages are plaintext Nostr events on our
  own relay. Members who want private contact can swap email / set up
  their own channel (Session, Signal, …) — the UI says so at the point of
  use. We provide a way to talk, not a privacy tool.
- **One event shape:** kind 9 chat message with `["h", "<recipient-username>"]`
  tag (the NIP-29-style group tag; same kind coinos uses internally).
  No channel-creation event needed — the `h` tag *is* the channel.
- **Server-side signing.** Messages are signed with the member's existing
  `nostr_privkey` via the same pipeline `save-post.php` already uses
  (`/opt/strfry/nostr-sign.py` + `nostr_publish_ws()`). The browser needs
  **zero crypto and zero JS libraries**.
- **Reads straight off the relay.** Client opens a WebSocket to
  `wss://relay.directsponsor.net` and subscribes — live push, no polling,
  no PHP read endpoint.
- **Membership enforced at the write endpoint** (and later at the relay
  write policy) — structural, not cosmetic.

## Why not coinos's stack

coinos v3's private groups are MLS (Marmot + Cordn — ~6,500 lines of
key-packages, welcomes, epoch state) and its community chat is Concord
shared-key channels. Both buy privacy we've explicitly decided we don't
need. Plain signed events + our closed relay + endpoint-side membership
checks do the same job here at a fraction of the code. Coinos is still the
reference implementation if we ever want the private version (see below).

## Data model

- Group = existing `userdata/sponsorship-groups/{recipient}.json`.
  Chat channel id = the `h` tag = recipient username. **No new files.**
- Message event:
  ```json
  {
    "kind": 9,
    "pubkey": "<member's DS-issued pubkey>",
    "content": "<plain text>",
    "tags": [["h", "<recipient-username>"]],
    "created_at": 1234567890
  }
  ```

## Write path — new `site/api/group-chat.php`

`POST {recipient, text}`:

1. Verify JWT via `jwt-verify.php` → caller username.
2. Load `sponsorship-groups/{recipient}.json`; caller must be in `members[]`.
3. Sanitize: plain text only, cap ~1000 chars; rate-limit 10 msgs/60s per
   user (same pattern as roflfaucet chat).
4. Ensure `nostr_privkey` exists in caller's profile (genkey on first use —
   same lazy pattern as `save-post.php`).
5. Build the kind-9 event → sign via `nostr-sign.py` → publish via
   `nostr_publish_ws()` to `127.0.0.1:7777` only.
   **Not** broadcast to external relays — group chat stays ours.
6. Return `{ok:true, id:<event-id>}`.

## Read path — client

- Chat section inside `site/group.php` (the page already renders the
  member roster — same place users land anyway).
- JS: `new WebSocket('wss://relay.directsponsor.net')` →
  `["REQ","chat",{"kinds":[9],"#h":["<recipient>"],"limit":100}]` → render
  each `EVENT`; keep the sub open for live messages.
- `group.php` emits a `pubkey → display_name` map server-side (members +
  their profile pubkeys) as a JSON blob — no per-author lookups.
- Graceful degradation: no JS / WS fails → panel shows roster plus a
  "chat needs JavaScript" note. Page fully functional without chat.

## Relay write policy (RN1)

Extend `/opt/strfry/write-policy.py` — this is the already-planned
tightening, with group chat as its first consumer:

1. Allowlist = pubkeys found in `userdata/profiles/*.txt` (DS-issued keys).
   Reject chat-kind events (9, 42) from anything else.
2. Later hardening: also reject kind-9 `h`-tagged to a group the author
   isn't a member of (policy can read the same JSON files).

## UI note text (shown above the chat)

> Group chat — messages are stored publicly on our relay, not private.
> For private contact, exchange email or set up your own messenger.

## Moderation (v1 minimal)

- Author self-delete: endpoint also accepts `{action:"delete", id}` →
  publishes kind 5 e-tagging the message; UI hides deleted ids.
- No presence, typing indicators, reactions, media, edits, or bots.
- Recipient delete-as-admin: optional later, same kind-5 mechanism signed
  with the recipient's key.

## Cross-site

Not needed for v1 — group pages only exist on directsponsor.net. But if a
shared community channel is ever wanted on roflfaucet/clickforcharity, the
same JS works there directly: WSS has no same-origin restriction, and the
write endpoint just needs CORS + JWT (replacing today's
iframe-JWT-in-URL-param hack with plain websocket).

## Later / optional

- **Real privacy:** port coinos's `concord.js` (MIT, ~350 lines) — each
  group becomes a private channel whose key is handed to members via this
  same authenticated endpoint. Page structure doesn't change; only the
  envelope does.
- **1:1 DMs** (from `chat-private-messaging-outline.md`): if ever wanted,
  a two-member group under this same scheme covers it informally; proper
  NIP-17 gift-wraps are the interop version.
- **Client-side signing:** hand the nsec to the browser at login — saves
  one local HTTP hop per message. Optional optimization, not a trust
  improvement (server holds the key either way).

## Open questions to settle at build time

- Can queued members (`slots: 0`) post, or are they read-only? (Lean: all
  listed members can post — the queue is part of the group.)
- History depth on load — 100 suggested.
- Server-side flat-file mirror of chat? Probably not — the relay *is* the
  store, mirroring adds a second truth.

## Related

- `nostr-relay/README.md` + `nostr-relay/nostr-integration.md` — relay
  status, write-policy direction
- `chat-private-messaging-outline.md` — older draft; group part now
  superseded by this doc
- `direct_sponsor_sponsorship_groups.md` — group structure
