https://grok.com/c/b99d56d4-0cfa-4d54-9f20-e8ef4c825112?rid=880a7af1-d4c6-487d-93ba-9bbfd695f1fc
# DirectSponsor — Private Messaging Layer (Outline)

**Status:** Draft / planning — **small-group scope superseded** by
`sponsor-group-chat-plan.md` (2026-10-20): sponsor-group chat will be
plaintext kind-9 events on our relay, not encrypted. The 1:1 DM scope
below remains dormant/undecided.  
**Last updated:** 2026-10-15  
**Context:** Keep the existing roflfaucet public community chat; add a separate, lighter Nostr-based private messaging layer for 1:1 and small-group conversations. Remove the rudimentary private-message features from the current public chat once the new layer is ready.

---

## 1. Goals

- Public community chat stays exactly as it is today (flat-file, polling, `/tip` / `/rain` / bots, JWT iframe embed on DirectSponsor).
- Private 1:1 and small-group messaging moves to a dedicated Nostr-based layer.
- Users do **not** need to understand Nostr. Keys are created/handled for them; power users can export or use external clients later.
- Resource and complexity footprint stays modest (“thin” client): no full Telegram clone, no audio/video calls required in v1, no heavy desktop/mobile packaging.
- Messages are real Nostr events so they remain portable.

---

## 2. Scope Split

| Concern                        | System                          | Notes |
|--------------------------------|----------------------------------|-------|
| Public community stream        | Existing roflfaucet chat         | Keep unchanged |
| Tips, rain, balance commands   | Existing chat + Hub/auth         | Keep |
| Bots (Anzar, ROFLBot)          | Existing chat API                | Keep |
| Floating panel on DS pages     | Existing iframe + JWT            | Keep |
| Rudimentary private msgs in current chat | **Remove** once new layer is live | Clean separation |
| 1:1 encrypted DMs              | New thin Nostr layer             | Primary target |
| Small private groups (e.g. sponsorship coordination) | New thin Nostr layer | Secondary target |
| Audio/video calls              | Out of scope for v1              | Can revisit later |
| Full contact management / Telegram UX | Out of scope                     | Anagram-style richness not required |

---

## 3. Why a thin Nostr layer (not full Anagram)

Anagram is a complete private-messaging client (DMs, groups, calls, contacts, multi-platform). We only need the private messaging core:

- End-to-end encrypted 1:1 (NIP-17 + gift-wrap / NIP-44)
- Small private groups
- Basic conversation list + unread indicators
- Ability to open a chat from a DirectSponsor context (recipient profile, fundraiser, sponsorship group)

A thinner implementation (or a heavily stripped / forked client) uses fewer resources, has a smaller attack surface, and is easier to embed or deep-link from the existing site.

---

## 4. High-level Architecture

```
┌─────────────────────────────────────────────────────────┐
│  DirectSponsor.net (and other sites)                    │
│                                                         │
│  • Existing public chat iframe (JWT passthrough)        │
│  • “Message privately” / “Open group chat” buttons      │
│  • Key generation / storage already present or added    │
└────────────┬────────────────────────────┬───────────────┘
             │                            │
             ▼                            ▼
   Public community chat          Thin private messaging layer
   (roflfaucet flat-file)         (Nostr client + your relay)
             │                            │
             │                            ▼
             │                   Nostr events (NIP-17 DMs,
             │                   private groups) on your relay
             │                   (+ optional extra relays)
             ▼
        Unchanged bots,
        tips, rain, etc.
```

- Public chat continues to use the existing `simple-chat.php` + `chat.js` + JWT frame.
- Private layer talks to Nostr relays (prefer yours) using standard events.
- Same user identity can be linked: site user ↔ Nostr pubkey.

---

## 5. Key Handling (critical for “users don’t need to understand Nostr”)

Assumptions / requirements:

- When a user registers or first needs private messaging, generate (or already have) a Nostr keypair for them.
- Store the private key securely server-side or in a way the site can use it on the user’s behalf for signing (or use a remote-signer / NIP-46 pattern later).
- Never force the user to paste an `nsec`.
- Offer an optional “export keys / use in other clients” path for power users.
- Public chat identity (JWT / username) and Nostr identity should be linkable so “Message this recipient” just works.

Open questions to resolve later:

- Exact key storage & recovery model.
- Whether to support NIP-07 browser extensions as an advanced option.
- How multi-device access will work (same key vs. delegated signing).

---

## 6. User Flows (v1)

### 6.1 Start a private 1:1 chat

1. User is logged in on DirectSponsor.
2. On a recipient profile, fundraiser, or member list they click **Message privately**.
3. System ensures both parties have Nostr keys (generate if missing).
4. Opens the thin private UI (or deep-link) with the conversation already selected.
5. Messages are published as standard encrypted Nostr DMs; only the participants can read them.

### 6.2 Small private group

1. From a sponsorship group or fundraiser context, an organiser creates a private group chat.
2. Members are added by site identity → resolved to Nostr pubkeys.
3. Group uses an appropriate Nostr private-group mechanism (details to choose later; keep simple).
4. Members open the same thin UI and see the group conversation.

### 6.3 Returning user

- Conversation list shows recent private chats and groups.
- Unread counts.
- Link back to the related DirectSponsor entity (recipient, fundraiser, etc.) where useful.

---

## 7. Technical Directions (to evaluate later)

Possible approaches, ordered roughly from lightest to heavier:

1. **Deep-link to a minimal self-hosted client**  
   Host a stripped or purpose-built web client that only does DMs + small groups. Pre-configure your relay and inject the user’s key / session.

2. **Thin in-page or iframe widget**  
   Similar embedding pattern to the current public chat (JWT or key passthrough). Harder for full Nostr crypto, but keeps everything inside the DS UI.

3. **Fork / strip an existing open-source client** (e.g. parts of Anagram or another NIP-17 client)  
   Keep only the messaging core; remove calls, heavy theming, multi-platform packaging.

4. **Custom thin client on top of a Nostr library**  
   Most control, most work. Use a well-maintained library for NIP-17 wrapping, relay handling, and local storage.

Recommendation for later evaluation: start with (1) or (3) so the protocol work is already done, then tighten the UI and key injection to match DirectSponsor.

---

## 8. Relay Strategy

- Default all private messaging traffic to **your own Nostr relay**.
- Allow power users to add extra relays later if desired.
- Ensure the relay accepts the event kinds required by the chosen DM / group protocol (gift-wraps, etc.).
- Public community chat remains completely independent of the relay.

---

## 9. Migration / Clean-up of Existing Chat

Once the private layer is usable:

1. Disable or remove the rudimentary private-message features from the current flat-file chat.
2. Leave a short notice or redirect for any old private threads if they exist.
3. Public chat becomes unambiguously public-only (community stream + commands + bots).

This keeps the mental model clean for users and simplifies the existing codebase.

---

## 10. Out of Scope for First Version

- Audio / video calls
- Full contact / friend-request system beyond what’s needed for 1:1 and small groups
- Mobile/desktop native apps
- Complex group moderation or large public groups on Nostr
- Replacing the public community chat

---

## 11. Success Criteria (v1)

- A logged-in DirectSponsor user can start an encrypted 1:1 chat with a recipient in one or two clicks, without seeing Nostr jargon.
- Small private groups can be created in the context of a sponsorship / fundraiser.
- Messages are real Nostr events and can be read in a standard external client if the user exports their key.
- Public chat continues to work exactly as today (including cross-site embed and bots).
- Resource use and operational complexity stay modest.

---

## 12. Next Steps (when ready)

1. Decide key generation + storage model and how it links to existing site accounts.
2. Choose the concrete client approach (deep-link minimal client vs. fork vs. custom thin UI).
3. Confirm which NIPs / group mechanism will be used and that your relay supports them.
4. Prototype the “Message privately” button → conversation open flow.
5. Plan the removal of rudimentary private messaging from the current public chat.

---

## References / Related Docs

- [[chat-system]] — existing public chat architecture (roflfaucet flat-file polling + JWT iframe embed). Section 2 (Scope Split) maps directly onto this.
- [[nostr-integration]] — relay infrastructure (strfry on RN1, `wss://relay.directsponsor.net`). Section 8 (Relay Strategy) depends on this.
- [[direct_sponsor_sponsorship_groups]] — sponsorship group structure referenced in Section 6.2 (small private group flow).
- Anagram (full-featured reference implementation): https://github.com/lnbits/anagram  
  Useful for protocol details; not required to adopt wholesale.
- Core protocols: NIP-17 (private DMs), NIP-44 (encryption), NIP-59 (gift wrap)

---

*This outline is intentionally high-level so it can be revisited after more familiarity with the Nostr side of the stack.*
