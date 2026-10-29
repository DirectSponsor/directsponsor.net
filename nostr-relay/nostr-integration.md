# DirectSponsor.net - NOSTR Integration
---

## ✅ Infrastructure Status (as of 2026-04-13)

| Component | Status | Detail |
|-----------|--------|--------|
| strfry relay | **Running** | systemd service on RN1, boot-enabled |
| Public URL | **Live** | `wss://relay.directsponsor.net` |
| SSL cert | **Valid** | Let's Encrypt via acme.sh; covers `directsponsor.net`, `www.directsponsor.net`, `relay.directsponsor.net` |
| Apache proxy | **Working** | Dedicated vhost at `/etc/apache2/sites-available/relay.directsponsor.net.conf`; HTTP/2 disabled for WS compatibility |
| Write policy | **Open (temp)** | `/opt/strfry/write-policy.py` — accepts all events; to be tightened once DS keypairs are in use |
| End-to-end test | **Passed** | Iris.to → WSS → Apache → strfry confirmed; event count verified via `strfry scan --count` |
| NIP-11 relay info | **Working** | `curl -H "Accept: application/nostr+json" https://relay.directsponsor.net/` returns valid JSON |

### Key paths on RN1
- Binary: `/opt/strfry/strfry`
- Config: `/opt/strfry/strfry.conf` (bind `127.0.0.1:7777`)
- Write policy: `/opt/strfry/write-policy.py`
- DB: `/var/lib/strfry/db/`
- Systemd: `/etc/systemd/system/strfry.service`

### Useful commands
```bash
# Check event count
ssh RN1 "/opt/strfry/strfry --config /opt/strfry/strfry.conf scan --count '{}'"
# Service status
ssh RN1 "systemctl status strfry"
# Live logs
ssh RN1 "journalctl -u strfry -f"
```

### Next steps
- [x] Generate per-user Nostr keypairs in `save-post.php` and store in profile files
- [x] Sign and publish events to relay when posts are saved
- [x] **NIP-05 + display names** — both parts live:
  1. `site/.well-known/nostr.php` serves `/.well-known/nostr.json` mapping usernames → pubkeys (NIP-05 verification — gives `username@directsponsor.net` with green tick in clients)
  2. `save-post.php` publishes a **kind 0 metadata event** on a user's first post: `{"name":"<username>","nip05":"<username>@directsponsor.net"}` — replaces the random generated name (e.g. "adequate vicuna") with the real DS username in all Nostr clients. Gated by `nostr_metadata_hash` in the profile file so it only re-fires if the content changes.
- [x] **Reply visibility via shared relays (outbox model)** — `save-post.php` broadcasts every new post (kind 1) and the kind 0 metadata event to `relay.damus.io`, `relay.primal.net`, and `nos.lol` in addition to the local strfry relay, via `nostr_publish_ws()`. Fully automatic — no user action needed; this is how we get propagation/reach on the wider network without opening our own relay to public writes. `posts.html` queries those same three relays client-side to pull in replies by `nostr_event_id` and display them under each post.
  - **Intentional scope boundary**: DS is not a Nostr client and will not become one. We show replies *to DS posts* from the wider network — that's it. Users who want full Nostr participation (browsing feeds, following people, cross-client messaging) should use a dedicated client. This boundary keeps DS simple and avoids infinite scope creep.
  - Relay list is currently hardcoded identically in both `save-post.php` and `simple-profile.php` (`$external_relays` / `$nostr_external_relays`) — worth extracting to one shared constant if a fourth relay is ever added.
- [ ] **Video embeds in posts** — support YouTube and Odysee URLs pasted into post body; auto-convert to `<iframe>` embeds on display. Both support standard embed URLs. Keep it opt-in (only embed if URL is on its own line) so plain text links still work.
- [ ] Tighten write policy to allowlist DS-issued pubkeys only
- [ ] **User-facing Nostr docs** — explain that replies from Nostr users are visible on the site, but replying *back* to a Nostr user requires a Nostr client (Iris, Damus, Amethyst etc.) using the user's own key. DS users who want to engage in those threads will need their npub exported. Keep it simple — most users won't care, but power users will want to know.
- [ ] **LNURL-pay / NIP-57 zap support** — see `lnurl-zap-integration.md` for full spec

### Scope decision: this relay stays closed; decentralization happens by cloning, not by opening up
`relay.directsponsor.net` itself stays **write-restricted to DS-issued keys** — no public/open writes, ever. That's final.

The "decentralized network" idea is not dead, but it's a different shape than the old aspirational docs below describe. Instead of *one* platform running both a private relay and a public relay that strangers write to, the actual direction is:
- Other communities/projects **clone this exact closed-relay setup** (same strfry config, write policy, broadcast-to-public-relays pattern) and run their *own* independent, equally closed relay for their own users
- Reach into the wider Nostr ecosystem still happens the same way DS already does it — each node's own posts get broadcast outward to the big public relays (damus, primal, nos.lol, etc.) and replies get pulled back in — not by any node accepting outside writes
- Longer-term, "networked up" could mean these sibling closed relays becoming aware of each other (e.g. a shared allowlist/discovery doc, or cross-posting between known node operators) — not yet designed, just the direction
- A packaged "relay-in-a-box" (install script + config) is the natural artifact for this, if/when there's appetite to publish it — see the `Community Relay Package` sketch further down, which predates this clarification but is still roughly the right shape

This supersedes the old "es6 public relay" plan specifically (a single open-write relay bolted onto DS) — that one part is dropped. The broader multi-relay vision in `projects/DIRECTSPONSOR_DECENTRALIZED_NETWORK.md` and the speculative sections further down this file are being reframed around "many closed clones," not "one public relay" — treat the Phase/Scenario content below as directional inspiration, not a literal spec.

---

## ⚠️ Architectural Decisions (read first)

### Zaps — planned LNURL-pay integration
Nostr zaps (NIP-57) allow Lightning payments from any Nostr client to a recipient's Lightning
address. DS will support this via a thin LNURL-pay adapter that routes zap payments through the
existing Coinos invoice flow — no node required, full DS accounting preserved.

**Key design decisions:**
- DS generates the invoice (via Coinos API), so every zap is tracked in the audit ledger
- Zaps are recorded as **general recipient income**, not against a specific fundraiser — fundraiser goal bars still count only modal-generated invoices, preserving exact accountability
- Recipients get a Lightning Address at `username@directsponsor.net` usable in any Nostr client
- On payment, `webhook.php` publishes a NIP-57 zap receipt (kind 9735) to the strfry relay

**Full spec**: `lnurl-zap-integration.md`

### Auto-generated Nostr keypairs (future)
When a user registers, we plan to generate a Nostr keypair server-side and store it in their profile file. Posts they publish on DirectSponsor will be broadcast to Nostr relays transparently — users don't need to know or care. If they later want to claim their Nostr identity with their own key (e.g. they open a Damus account), they can link it via signature verification. This keeps the "zero Nostr complexity" promise for regular users while still putting content into the Nostr ecosystem.

## Server Allocation Plan

| Server | Specs | Role |
|--------|-------|------|
| **RN1** (`104.168.38.197`) | 4GB RAM, 62GB disk, 3 cores | directsponsor.net + **private Nostr relay** (write-restricted to DS-issued keys) |
| **dr1** | 2GB RAM, 30GB disk, 1 core | Browser bookmarks sync server (separate project, `browser-sync/`) |

**Rationale:**
- Private relay on RN1 — low risk, plenty of headroom (3.5GB RAM free, 58GB disk free), source of truth for our users' content
- dr1 is overkill for bookmarks sync (tiny payloads, low frequency) but that's fine — it's cheap
- There is no second, open-write relay planned for DirectSponsor itself (see "Scope decision" above). Outward reach to the Nostr network is handled entirely by broadcasting our own signed posts to public relays (`relay.damus.io`, `relay.primal.net`, `nos.lol`) — already implemented in `save-post.php`.
- es6 was earlier earmarked for a public open-write relay; that plan is dropped. If a sibling closed relay is ever stood up by another community cloning this setup, it would run on its own infrastructure, not es6-as-"the" public relay.

---

DirectSponsor.net becomes a **dual-interface content platform** with **decentralized relay network**:

- **Web interface** for traditional users (zero Nostr complexity)
- **Native Nostr relay** for decentralized ecosystem integration
- **Bitcoin Lightning integration** for direct project monetization
- **Identity bridging** between traditional and cryptographic systems
- **Decentralized network** of community relays sharing content

---

## Hybrid Architecture

### Domain Structure

directsponsor.net → Primary Nostr relay + content hub
clickforcharity.net → Charity content (shares via primary)
roflfaucet.com → Promotional content (shares via primary)
auth.directsponsor.org → Centralized authentication service
community-relays.* → Decentralized network relays

### Enhanced Technical Stack

directsponsor.net ├── Primary Nostr Relay (strfry/nostream)
                 ├── Dual Web Interface (traditional + Nostr)
                 ├── Lightning Integration (NIP-57 Zaps)
                 ├── Authentication Bridge (web ↔ Nostr)
                 ├── Relay Directory Service
                 ├── Content Sharing Protocol
                 └── Network Management Tools

---

## User Experience Scenarios

### Scenario 1: Regular Network Users
**Goal**: Zero Nostr complexity, familiar web experience

```
User Journey:
1. Visits directsponsor.net → sees normal blog/website
2. Reads posts, browses projects, views profiles
3. Wants to comment/tip → simple email signup
4. Behind scenes: auto-generates Nostr keypair
5. Uses platform normally with traditional UI
6. Benefits from Nostr features transparently
7. Sees content from entire decentralized network
```

**Implementation**:
- Traditional web interface (HTML/CSS/JS)
- Hidden Nostr integration
- Managed cryptographic keys
- JWT-based network authentication
- Lightning wallet integration
- **Network content aggregation** (from all relays)

### Scenario 2: Existing Nostr Users**
**Goal**: Native Nostr experience, full interoperability

```
User Journey:
1. Discovers content via Nostr client (Damus, Amethyst, etc.)
2. Connects to relay: wss://directsponsor.net
3. Comments/tips using existing Nostr identity
4. Content appears on both Nostr network AND web interface
5. Full native Nostr experience
6. Sees content from all network relays
```

**Implementation**:
- Standard Nostr relay protocols
- NIP-57 Zaps for Lightning tips
- Cross-client compatibility
- **Cross-relay content sharing**
- **Network discovery protocol**

### Scenario 3: Nostr User Wants Network Account
**Goal**: Preserve existing Nostr identity while gaining network access

```
User Journey:
1. "I want network access with my existing Nostr identity"
2. Provides npub (public key)
3. System generates challenge message
4. Signs challenge with nsec (in their client)
5. System verifies signature
6. Links npub to network account
7. Same identity across both systems
8. Access to all network relays
```

**Implementation**:
- Cryptographic signature verification
- Identity linking system
- Account merging capabilities
- Cross-platform authentication
- **Network-wide identity**

---

## Enhanced Content Flow Architecture

### Decentralized Content Flow
```
Author creates post
    ↓
Published to DirectSponsor relay
    ↓
┌──────────────────────────────────────────────┐
│       ↓              ↓              ↓        │
│  Web Interface    Community     External Nostr│
│  (Network users)   Relays        (Global users)│
│       ↓              ↓              ↓        │
│  Comments/Tips  Comments/Tips  Comments/Tips │
└──────────────────────────────────────────────┘
    ↓
All events shared via same relay event store
```

### Network Content Sharing
```
DirectSponsor Relay (Primary)
    ↓
┌─────────────────────────┐
    ↓                    ↓
Community Relay A      Community Relay B
    ↓                    ↓
Local Users             Local Users
    ↓                    ↓
    ←←← Content Sync →→→→→
```

## Authentication Bridge

> Note: the code blocks below are pseudocode concept sketches, not working implementations.

```javascript
// Hybrid authentication system with network support
class NostrAuthBridge {
    // For web users
    createWebAccount(email, password) {
        const nostrKeypair = generateKeypair();
        const networkAccount = createAccount(email, password);
        linkAccounts(networkAccount, nostrKeypair);
        return { 
            networkToken, 
            managedNostrKey,
            networkAccess: true 
        };
    }

    // For Nostr users
    linkNostrIdentity(npub, signature, challenge) {
        if (verifySignature(npub, signature, challenge)) {
            return linkToNetworkAccount(npub);
        }
    }

    // Network relay access
    grantRelayAccess(userAccount, relayList) {
        return {
            primaryRelay: "wss://directsponsor.net",
            communityRelays: relayList,
            networkToken: userAccount.networkToken
        };
    }
}
```

---

## ⚡ **Enhanced Lightning Integration**

```javascript
// Native Bitcoin monetization with network support
class LightningTips {
    // For web users
    showTipInterface(authorPubkey) {
        const invoice = generateInvoice(authorLightningAddress);
        displayQRCode(invoice);
        // Or connect to web wallet
    }

    // For Nostr users
    nativeZaps(event, amount) {
        // Standard NIP-57 implementation
        return createZapEvent(event, amount);
    }

    // Network-wide tipping
    networkTip(authorPubkey, amount, acrossRelays) {
        if (acrossRelays) {
            return distributeTipAcrossNetwork(authorPubkey, amount);
        }
        return createZapEvent(authorPubkey, amount);
    }
}
```

## Implementation Phases

### Phase 1: Foundation (Weeks 1-6)
```
Core Infrastructure:
├── Deploy primary Nostr relay on directsponsor.net
├── Create dual web interface (traditional + Nostr)
├── Implement authentication bridge
├── Basic Lightning tips integration
├── Network authentication connection
└── Initial content sharing protocol
```

### Phase 2: Network Building (Weeks 7-12)
```
Decentralized Network:
├── Develop "relay-in-a-box" package
├── Create relay directory service
├── Implement cross-relay content sharing
├── Add community relay discovery
├── Test network effects

```

### **Phase 3: Enhanced Features (Months 4-6)**
```
Advanced Capabilities:
├── Nostr identity linking for existing users
├── Cross-relay broadcasting for wider reach
├── Enhanced tip features (recurring, splits)
├── Advanced moderation tools
└── Network analytics dashboard
```

## Strategic Benefits

### For Regular Users
- **Zero Learning Curve**: Familiar web experience
- **Rich Content**: Access to entire network from any site
- **Modern Features**: Tips, comments, social interaction
- **Future-Proof**: Automatic Nostr benefits
- **Network Effects**: See content from all community relays

### For Nostr Ecosystem
- **Native Compatibility**: Works with all Nostr clients
- **Identity Preservation**: Existing users keep their identity
- **Network Effects**: Content distributed across relays
- **Lightning Integration**: Native Bitcoin payments
- **Decentralization**: True distributed network
---

## Enhanced Technical Specifications

### Primary Relay Requirements
- **Software**: strfry (recommended for initial deployment — battle-tested, well-documented); [WISP](https://github.com/privkeyio/wisp) is a future candidate (Zig-based, claims 2x throughput / 10x lower latency vs strfry, also uses LMDB) — worth revisiting once it matures
- **NIPs Support**: NIP-01 (basic), NIP-57 (zaps), NIP-23 (long-form)
- **Storage**: LMDB (strfry's embedded store — no separate database needed)
- **Performance**: Optimized for network user base
- **Network Features**: Cross-relay sharing, discovery protocol

### Community Relay Package
```bash
directsponsor-relay-kit/
├── install.sh                    # One-click setup
├── config/
│   ├── relay.conf               # Basic relay config
│   ├── network.conf             # Network integration
│   └── content-sharing.conf     # Cross-promotion rules
├── scripts/
│   ├── auto-upgrade.sh          # Maintenance
│   ├── health-check.sh          # Monitoring
│   └── network-sync.sh          # Content sharing
├── templates/
│   ├── relay-homepage.html      # Default relay page
│   └── community-guidelines.md  # Network standards
└── README.md                    # Setup guide
```

### Web Interface Requirements
- **Framework**: Custom PHP/JS (no framework)
- **Database**: Shared with relay or separate for web features
- **Authentication**: JWT integration with network auth
- **Lightning**: LNbits, BTCPay, or native integration
- **Network Features**: Content aggregation from all relays

### Security Considerations
- **Key Management**: Secure storage of managed Nostr keys
- **Authentication**: Multi-factor support for high-value accounts
- **Content Moderation**: Spam and abuse prevention across network
- **Privacy**: User data protection and GDPR compliance
- **Network Security**: Relay authentication, content validation

---

