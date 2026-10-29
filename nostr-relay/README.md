# Nostr Relay (strfry on RN1)

Status: **Live**, closed/private by design — not planned, not open.

Moved under `directsponsor.net/` because that's its only purpose: this relay
is DS infrastructure, not a standalone project. This folder is intentionally
thin — the detailed, actively-maintained operational doc lives one level up:

**`../nostr-integration.md`** ← source of truth, read this first (see
especially the "Scope decision" section — no public writes, ever; see below).

## Quick summary

| Component          | Status      | Detail                                                                                                                       |
| ------------------ | ----------- | ---------------------------------------------------------------------------------------------------------------------------- |
| strfry relay       | Running     | systemd service on RN1, boot-enabled                                                                                         |
| Public URL         | Live        | `wss://relay.directsponsor.net`                                                                                              |
| SSL cert           | Valid       | Let's Encrypt via acme.sh                                                                                                    |
| Apache proxy       | Working     | `/etc/apache2/sites-available/relay.directsponsor.net.conf`                                                                  |
| Write policy       | Open (temp) | `/opt/strfry/write-policy.py` — accepts all events for now; being tightened to DS-issued pubkeys only                        |
| External broadcast | Live        | every post + profile metadata also pushed to `relay.damus.io`, `relay.primal.net`, `nos.lol` — see `../nostr-integration.md` |

Key paths on RN1:
- Binary: `/opt/strfry/strfry`
- Config: `/opt/strfry/strfry.conf` (bind `127.0.0.1:7777`)
- Write policy: `/opt/strfry/write-policy.py`
- DB: `/var/lib/strfry/db/`
- Systemd: `/etc/systemd/system/strfry.service`

## History

- Originally planned as a dedicated box (es8, EthernetServers, $5.97/mo) —
  cancelled mid-2025 for budget reasons. See
  `/home/andy/work/servers/es8-cancelled/SERVER_CONTEXT.md` (the fuller old doc
  `docs/infrastructure/servers/es8-cancelled.md` is in
  `~/work/archive-old-zips/old-docs-system-mkdocs-ARCHIVED-2026-10-25.zip`).
- Revived later as a `strfry` instance co-located on the existing RN1 server
  instead of a dedicated machine — no extra hosting cost.

## Scope: closed relay, decentralization via cloning (not opening up)

This relay will **never** accept public/open writes. Reach into the wider
Nostr network happens via outbound broadcast + inbound reply-fetch against
big public relays (already implemented, see `../nostr-integration.md`), not
by letting strangers write here.

The longer-term "decentralized" vision is: other communities/projects clone
this exact closed-relay setup and each run their own independent closed node
for their own users — many small closed relays, not one big open one. Not
designed yet (discovery/cross-posting between sibling nodes, packaging an
installable "relay-in-a-box"), just the direction. Full context in
`../nostr-integration.md` → "Scope decision" section.

## Planned write-policy direction
- Chat-type events (kind 42 or similar) restricted to an allowlist of pubkeys
- All other kinds open
- No NIP-42 auth, no encrypted DMs — considered over-engineering for current needs
- Longer-term: tighten write policy to DS-issued pubkeys only (see `../nostr-integration.md` roadmap)

## Related

- DirectSponsor's use of this relay (per-user keypairs, NIP-05, zaps, reply
  visibility, broadcast-to-public-relays): `../nostr-integration.md`
- Zap/LNURL spec: `../lnurl-zap-integration.md`
- Older, broader "decentralized relay network" strategy doc — superseded by
  the "clone, don't open up" framing above, kept for historical reference:
  `/home/andy/work/projects/DIRECTSPONSOR_DECENTRALIZED_NETWORK.md`
- Nostr signer browser extension (separate project, NIP-07 key manager, not
  the relay itself): `../../nostr-signer-plugin/`
