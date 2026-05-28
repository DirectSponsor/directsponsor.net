# LNURL-Pay / Nostr Zap Integration Plan

**Status**: Planned — not yet built  
**Depends on**: Existing Coinos integration, strfry relay, `nostr-integration.md`

---

## Goal

Allow Nostr clients (Damus, Amethyst, Iris, etc.) to send zaps directly to DS recipients via
the standard NIP-57 zap flow, while preserving **full DS accounting** — exact amounts, donor
identity, audit trail, fundraiser goal progress. Coinos remains the wallet backend throughout;
no Lightning node is required.

---

## Why This Works Without a Node

The current DS donation flow already does this:

1. DS calls Coinos API → gets a Lightning invoice
2. Donor pays the invoice → Coinos receives sats into recipient's Coinos wallet
3. Coinos fires the DS webhook → DS records the payment

LNURL-pay is just a standard HTTP protocol that sits *in front of* step 1. A Nostr client
fetches invoice details from DS, DS generates a Coinos invoice exactly as it does today, and
returns it to the client. The rest of the flow is unchanged. Coinos handles all routing,
channels, and liquidity.

---

## Protocol Flow (NIP-57)

```
Nostr client                 DS server                    Coinos
─────────────                ─────────────                ───────
GET /.well-known/lnurlp/evans
                    ──────────────────────────►
                    ◄──────────────────────────
                         LNURL-pay metadata
                         (min/max sat, callback URL,
                          nostrPubkey for zap receipts)

GET /api/lnurlp.php?user=evans&amount=21000&nostr=<zap_request_event_json>
                    ──────────────────────────►
                                               POST Coinos API (create invoice)
                                               ◄─────────────────────────────
                                                    invoice + payment_hash
                    ◄──────────────────────────
                         { pr: "<bolt11 invoice>" }

User pays invoice
                                               Coinos webhook fires ──► webhook.php
                                                                         (existing flow:
                                                                          update current-amount,
                                                                          append donor record,
                                                                          update ledger)
                                                                         + NEW: publish NIP-57
                                                                           zap receipt to strfry
```

---

## What Needs Building

### 1. Apache rewrite (on RN1)

Add to the directsponsor.net vhost (or `.htaccess`):

```apache
RewriteRule ^\.well-known/lnurlp/(.+)$ /api/lnurlp.php?user=$1 [QSA,L]
```

This routes `/.well-known/lnurlp/evans` → `site/api/lnurlp.php?user=evans`.

### 2. New file: `site/api/lnurlp.php`

Two responsibilities depending on query params:

**Phase 1 — metadata request** (`?user=evans`, no `amount`):
```json
{
  "tag": "payRequest",
  "callback": "https://directsponsor.net/api/lnurlp.php?user=evans",
  "minSendable": 1000,
  "maxSendable": 100000000,
  "metadata": "[[\"text/plain\",\"Donate to evans via DirectSponsor\"]]",
  "nostrPubkey": "<DS relay pubkey hex>",
  "allowsNostr": true
}
```

**Phase 2 — invoice request** (`?user=evans&amount=21000&nostr=<json>`):
- Validate `amount` is in range
- Parse and store the `nostr` zap request event (needed for zap receipt)
- Look up recipient's Coinos API key from their `config.json` (same as `project-donations-api.php`)
- Call Coinos API to create invoice — include zap request event hash in the `description_hash`
  field so the zap is cryptographically linked
- Store `{payment_hash: ..., nostr_zap_request: ..., username: ..., amount: ...}` in a temp
  file under `userdata/data/zap-pending/` (analogous to existing `project-donations-pending/`)
- Return `{ "pr": "<bolt11>", "routes": [] }`

### 3. `site/api/webhook.php` — minor addition

When a payment arrives, check if its `payment_hash` exists in `userdata/data/zap-pending/`.
If so:
- Record the donation against the recipient's **general income** (not a specific fundraiser,
  since zap senders don't choose a project — or optionally use the recipient's current active
  fundraiser as the default)
- Publish a **NIP-57 zap receipt** (kind 9735) to strfry, signed with the DS relay keypair,
  containing the original zap request event
- Clean up the pending file

### 4. `nostr-sign.py` — probably no changes needed

Signing a kind 9735 event uses the same BIP340 Schnorr signing as existing post events. The
DS relay pubkey (stored in a config file or derived from a dedicated keypair) signs the receipt.

---

## What Stays the Same

- **Coinos** is the only wallet backend — no node, no BTCPay, no new infrastructure
- **Existing donate modal** on fundraiser pages is unchanged — it still generates invoices
  directly and tracks goal progress per-fundraiser
- **Fundraiser goal bars** count both modal invoices and zap payments — both go through DS
  invoice creation via Coinos, so both are fully accounted for
- **Webhook.php** core logic is unchanged; the zap receipt publish is additive

---

## What Zaps Track vs. What the Modal Tracks

| | Donate modal (existing) | Nostr zap (new) |
|---|---|---|
| Tied to a fundraiser | ✅ Yes (chosen by donor) | ✅ Yes (current active fundraiser) |
| Counts toward goal bar | ✅ Yes | ✅ Yes |
| Donor identity recorded | ✅ Yes (JWT username) | ⚠️ Nostr pubkey (see display note below) |
| Audit ledger entry | ✅ Yes | ✅ Yes |
| Visible in zap receipt on Nostr | ❌ No | ✅ Yes |

Both flows use DS invoice creation via Coinos, so both are fully auditable. The difference is
that zaps carry a Nostr pubkey as donor identity rather than a DS username, and are also
visible as zap receipts on the Nostr network.

### Displaying zap donor identity

Nostr pubkeys are 64 hex characters (or ~63 chars in npub bech32 format) — too long for a
`recent_donations` list. Display priority:

1. **NIP-05 identifier** if the zap request event includes one (e.g. `evan@iris.to`)
2. **Display name** from the kind 0 metadata in the zap request event
3. **Truncated npub** as fallback: first 4 + last 4 chars → `npub1abc…f3q9`

The truncation is display-only (not used for verification), so collision risk is irrelevant.
This is the standard pattern across Nostr clients and wallets.

**Implementation note**: the zap request event (the JSON blob passed in the `nostr` query
param during the invoice request phase) often contains the sender's profile metadata inline —
check there first for display name / NIP-05 before attempting any relay lookup. If it's not
present, fall back straight to the truncated npub. No relay queries needed at webhook time.

---

## Lightning Address

Once this is built, recipients can set their Lightning Address to `evans@directsponsor.net`
in their Nostr profile (NIP-05 compatible). Any Nostr client or wallet that supports Lightning
Addresses will route payments through DS, giving full accounting.

Recipients can keep a separate personal Coinos Lightning Address for private payments — those
won't go through DS and won't be tracked, which is fine and expected.

---

## Files to Create / Modify

| File | Action |
|------|--------|
| `site/api/lnurlp.php` | **Create** — LNURL-pay handler (both phases) |
| `site/api/webhook.php` | **Minor edit** — check zap-pending, publish NIP-57 receipt |
| RN1 vhost `.conf` | **Edit** — add `RewriteRule` for `/.well-known/lnurlp/` |
| `userdata/data/zap-pending/` | **Create dir** on RN1 (analogous to `project-donations-pending/`) |

No new dependencies. No new services. No schema changes.

---

## Open Questions (decide when building)

1. **Which fundraiser gets the zap credit?** ✅ **Decided: the recipient's current active
   fundraiser** (lowest-numbered file in `active/`). Only one fundraiser runs at a time per
   recipient, so there's no ambiguity. The webhook already handles over-target payments
   correctly (moves to `completed/`, advances queue) — zap payments behave identically to
   modal payments in this regard.
2. **DS relay signing keypair** — use the same per-user Nostr keypair stored in the profile
   file, or a dedicated DS-service keypair? The NIP-57 spec says the LNURL server signs the
   receipt, not the recipient — so a dedicated DS keypair is correct.
3. **Rate limiting** — LNURL endpoints are public; add basic rate limiting (per IP, per
   username) to prevent invoice spam.

---

## References

- NIP-57 spec: https://github.com/nostr-protocol/nostr/blob/master/57.md
- LNURL-pay spec: https://github.com/lnurl/luds/blob/luds/06.md
- Lightning Address spec: https://lightningaddress.com/
- Existing Coinos invoice creation: `site/api/project-donations-api.php`
- Existing webhook: `site/api/webhook.php`
- Nostr infrastructure overview: `nostr-integration.md`
