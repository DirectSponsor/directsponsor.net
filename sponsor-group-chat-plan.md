# Sponsor Group Chat — Plan

**Status:** Agreed direction, not started. Build this *after* the core
sponsorship-group flow (join/leave/slots/payments) is proven live —
per Andy, 2026-10-20.

**Expect this to evolve.** No one has built exactly this before — there is
little to copy. Treat this as a living plan: details will be added,
changed, or dropped as we come to them.

**Supersedes:** the "small private groups" part of
`chat-private-messaging-outline.md`. The rest of that doc (1:1 DMs) is
dormant, not cancelled — see "Later / optional" below.

**Audience:** sponsor-group members first. A second, planned audience is
**fundraiser donors** who opt in to the group of a recipient they
supported — see "Fundraiser donors" below. Both post into the same
channel, which is keyed by the recipient username.

**Scope vs the other chat:** this is the Nostr layer. The roflfaucet
public chat (bots, rain, tips, embedded on directsponsor.net) stays
non-Nostr and unchanged — see `docs/chat-system.md` in the roflfaucet
project for the two-chat split.

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
2. Load `sponsorship-groups/{recipient}.json`; caller must be in
   `members[]` — or an opted-in fundraiser donor of that recipient (see
   "Fundraiser donors").
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

## Fundraiser donors (planned — not v1)

Sponsorship groups are the primary audience. But not everyone can commit
to a regular monthly sponsorship — that is exactly why fundraisers exist.
So it seems fair that a donor who contributes to a recipient's fundraiser
can also join **that recipient's** group chat if they want to stay in
touch. Access is per-recipient: you are only ever in the channels of
recipients you actually supported.

**Mechanism (to build):**
- Add an opt-in checkbox to the fundraiser donate flow
  (`fundraiser.html` donate modal): e.g. *"Join [recipient]'s group
  chat"*.
- On payment confirmation (`webhook.php`, where the donation is already
  recorded), if the box was ticked, record the donor as a chat member of
  that recipient — by their existing DS Nostr pubkey (genkey on first
  use, same lazy pattern as `save-post.php`).
- Membership therefore lives alongside the existing
  `sponsorship-groups/{recipient}.json` model, but needs to admit donors
  too — not just `members[]`. The write endpoint and (later) relay write
  policy must accept donors in the channel of a recipient they supported.

**Decided (per Andy, 2026-11-08):**
- **Post rights:** an opted-in donor **can post** — they supported the
  recipient, so they are a full participant, not read-only.
- **Duration:** access **persists** and does **not** expire on its own.
  The donor can **leave at any time** of their own accord.

**Still open:**
- **Same opt-in on the sponsorship 'pay' flow?** Probably yes, for
  consistency — a sponsor paying for a month is already a member, so this
  is about the initial join, not each payment.

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

- **Reference / ideas:** https://paff.club/community/ — an existing chat
  that uses Nostr under the hood with a plain web interface. Not our
  stack; worth a look for UI/UX ideas when we build this.
- `nostr-relay/README.md` + `nostr-relay/nostr-integration.md` — relay
  status, write-policy direction
- `docs/chat-system.md` (roflfaucet project) — the *other* chat: the
  public, non-Nostr roflfaucet chat embedded on directsponsor.net. That
  doc also documents the "two chats" split.
- `chat-private-messaging-outline.md` — older draft; group part now
  superseded by this doc
- `direct_sponsor_sponsorship_groups.md` — group structure
