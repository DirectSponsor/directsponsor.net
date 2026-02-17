# DirectSponsor.net - Site Overview

## Role in Ecosystem
**"Connect & Sponsor"** - Social network for direct sponsor-to-recipient relationships and ecosystem hub

## Primary Purpose
Central platform for recipients, projects, and direct sponsorships, serving as the social network and information hub for the entire ecosystem.

## Core User Journey
1. **Discovery:** Browse recipients and their projects
2. **Connection:** Social network features for communication
3. **Sponsorship:** Direct monthly/regular sponsorships
4. **Impact:** Recipients receive reliable income for their projects

## Key Features (Planned)

### 🚧 Core Features (To Be Built)
- **Recipient Profiles:** Detailed information about recipients and their projects
- **Project Management:** Project descriptions, targets, and progress tracking
- **Social Network:** Communication between sponsors and recipients
- **Chat & Tipping System:** Recipient username autocomplete and coin tipping
- **Direct Sponsorships:** Regular payments from sponsors to recipients
- **Payment Processing:** Handle recurring sponsorships and reminders
- **Nostr Relay:** Decentralized communication and identity
- **API Endpoints:** For ClickForCharity and RoflFaucet integration

### 🔗 Integration Features
- **API Endpoints:** For ClickForCharity and RoflFaucet integration
- **Cross-Site Authentication:** Shared login system
- **Project Data Hub:** Central database for all recipient/project information
- **Ecosystem Coordination:** Orchestrate cross-site functionality

## Technical Architecture

### Planned Structure
```
site/
├── Recipients/
│   ├── Profiles/           # Recipient information and stories
│   ├── Projects/           # Project details and progress
│   └── Applications/       # New recipient applications
├── Social/
│   ├── Network/            # Social features and messaging
│   ├── Chat/               # Chat system and tipping
│   ├── Updates/            # Progress posts and announcements
│   └── Community/          # Group features and discussions
├── Sponsorships/
│   ├── Payments/           # Recurring payment processing
│   ├── Reminders/          # Payment notifications
│   └── Management/        # Sponsor-recipient relationships
├── Nostr/
│   ├── Relay/              # Decentralized communication
│   ├── Identity/           # Nostr integration
│   └── Events/             # Cross-site coordination
└── API/
    ├── Projects/           # For other sites to query
    ├── Authentication/     # Shared login system
    └── Allocation/         # Coin allocation processing
```

### Dependencies
- **ClickForCharity.net:** Receives coin allocations
- **RoflFaucet.com:** Receives coin allocations
- **Shared Auth:** Provides authentication for ecosystem
- **Lightning Network:** Payment processing for sponsorships

## Revenue Model
- **Primary:** Small platform fee on sponsorships (minimal)
- **Secondary:** Premium features for recipients (optional)

## What Belongs Here vs Other Sites

### ✅ Belongs on DirectSponsor
- Recipient project information and progress tracking
- Social network features and communication
- Project management and updates
- Blog posts and content creation
- Nostr-based social features and relay
- Direct sponsorships and payment processing
- Cross-site API integration
- Ecosystem information hub

### 🔄 Shared Across Sites
- Recipient profiles (synced across ecosystem)
- User authentication and identity
- Basic recipient information and status

### ❌ Belongs on ClickForCharity
- Task-based earning systems
- Banner ad management
- Action-oriented contribution methods

### ❌ Belongs on RoflFaucet
- Gaming and entertainment features
- Faucet systems and rewards
- Direct donations to site fund

## Current Status
**Phase:** Planning and development phase
**Priority:** High - central hub for entire ecosystem

## Development Priorities

### Phase 1: Foundation
- Set up basic site structure and database
- Create recipient profile system
- Build project management features
- Implement basic chat and tipping system
- Add recipient username autocomplete

### Phase 2: Integration
- Build API endpoints for other sites
- Implement shared authentication
- Add payment processing for sponsorships
- Set up Nostr relay functionality
- Enhance chat with cross-site integration

### Phase 3: Ecosystem Integration
- Connect ClickForCharity for recipient data in chat
- Connect RoflFaucet for recipient data in chat
- Implement cross-site features
- Launch full ecosystem coordination
- Optimize chat for ecosystem-wide coin allocation

## Success Metrics
- **Active Recipients:** Number of recipients with profiles
- **Sponsorship Amount:** Total monthly sponsorship income
- **Chat Activity:** Volume of coin tipping and social interaction
- **Cross-Site Integration:** API usage and recipient data queries
- **Nostr Activity:** Decentralized communication volume
- **Coin Allocation:** Amount of coins tipped via chat system

## Migration Notes
- This is the new central hub - no existing systems to migrate
- Will inherit project data from other sites during integration
- Must be built with scalability for ecosystem growth
- API design should support future site additions

## Implementation Strategy
- Start with MVP focusing on recipients and projects
- Build social features as community grows
- Add sponsorship system when recipient base established
- Implement Nostr integration for decentralization
- Design APIs with future ecosystem expansion in mind

---

*This site is the ecosystem hub - connecting sponsors with recipients while coordinating the entire network of contribution platforms.*
