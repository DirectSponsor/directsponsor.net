**Direct Sponsor**

Regular Sponsorship Groups

Internal Reference Document • April 2026 via https://claude.ai/chat/d6cf4beb-f7ae-4b73-a11c-27bee791da96

**Overview**

The Direct Sponsor system has two main mechanisms for financial support. The first --- **fundraisers** --- allows anyone to contribute to a specific campaign at any time. The second --- **regular sponsorship groups** --- is the more important of the two, and the focus of this document. Fundraisers are straightforward and familiar. Sponsorship groups are less familiar but more significant: they provide recipients with a reliable monthly income and, crucially, an ongoing human relationship with a small group of people who have made a personal commitment to their project.

A sponsorship group is not a charity arrangement. It is closer to a small patronage circle: a defined group of people who believe in a recipient\'s work and have committed to supporting it regularly. The number of places in any group is limited, which gives the arrangement its meaning. This document captures the current thinking on how sponsorship groups work, how they are structured, and how they fit into the wider Direct Sponsor network.

**Purpose and Rationale**

The core insight behind the sponsorship model is simple: in many parts of the world, a modest but *reliable* monthly income --- even something like €60--€100 --- can be enough to free a person from work that consumes their time without advancing their goals. Once freed from that pressure, a recipient can focus on a project that will lead toward longer-term independence and, often, benefit to their community.

The emphasis on regularity is important. A single fundraiser might solve a specific problem. A sponsorship group changes a person\'s situation. The recipient is not alone; they have people who know them, who receive updates from them, and who are committed for the long term rather than in response to a single campaign.

This is also why the human relationship element is designed in from the start, not added as a feature. Sponsors are not anonymous donors; they are known to the recipient, and the expectation is that real interaction will take place over time.

**Sponsor Tiers**

There are three categories of sponsor within the system, each with a different role:

**Active Sponsors**

Active sponsors are the core members of a sponsorship group. They have made a monthly commitment to a specific recipient and are expected to fulfil it each month. The system sends reminders at the appropriate time, and sponsors have a defined window in which to respond before action is taken.

Sponsorship is voluntary and not enforced by automated billing. The commitment is honoured in the way any meaningful personal commitment is honoured --- by the person\'s own sense of responsibility, supported by the reminder system.

**Standby Sponsors**

Standby sponsors have specifically volunteered to fill gaps when an active sponsor lapses. They are an important part of the system\'s resilience. When an active sponsor does not respond within the allowed window, a standby sponsor is brought in to cover the gap, maintaining the regularity that the recipient depends on.

Non-responsive active sponsors are removed from the group and replaced. This is not punitive; it simply reflects that the commitment was not being met, and the recipient\'s stability takes priority.

The standby role is explicitly voluntary --- people self-select into it by indicating their willingness when they sign up. It requires a different kind of readiness than active sponsorship.

**Queued Sponsors**

Queued sponsors are people who want to support a recipient but for whom there is currently no open place in the group. Because sponsorship groups are deliberately kept small (see [[Small Groups --- nimno.net]{.underline}](https://nimno.net/notes/small-groups/)), there will often be more people who wish to sponsor than there are places available.

This is a feature, not a problem. A waiting list signals that the recipient and their work are valued, and it gives the arrangement a quality that a charity donation page cannot replicate. When a place opens --- because an active sponsor has lapsed and no standby is available, or because the group has agreed to expand slightly --- the next person in the queue is invited in.

**Group Size**

Groups are kept small by design. Twelve is treated as a maximum, and in practice groups will often be smaller. The reasons for this are discussed in depth at [[nimno.net/notes/small-groups/]{.underline}](https://nimno.net/notes/small-groups/), but the short version is: small groups are where genuine human cooperation happens. Large groups tend toward impersonal dynamics, power concentration, and the loss of individual accountability. The Direct Sponsor system is designed explicitly to avoid all of those outcomes.

A recipient with seven sponsors contributing €10/month has €70/month --- a small but real and dependable income. The size of the group is part of what makes the relationship meaningful for both sides.

**How Sponsorship Groups Form**

Groups form initially through the main directsponsor.net site. A recipient sets up a profile describing their project and situation, and sponsors can then express interest in supporting them. The system manages the group formation process, including the queue of waiting sponsors.

At this stage the site acts as a proof of concept and coordination point. The long-term model is not for the central site to grow into a large platform but for successful groups to give rise to independent nodes --- separate instances of the same system, run by different communities, linked together via Nostr. This is described further in the Network Architecture section below.

**Recipients: Individuals and Groups**

A recipient can be an individual or a small group of people cooperating on a shared project. The system is designed primarily with small groups in mind --- humans tend to do better in cooperation than in isolation --- but it accommodates solo projects. An individual who starts alone may later form or join a group.

Where recipients do form groups, they can pool a percentage of their incomes into a common fund for shared expenses --- equipment, materials, a hired skill they collectively need. Crucially, the money stays under the control of the recipient group itself. There is no organisation above them deciding how it is spent. This is a deliberate reversal of the NGO model, where the organisation typically holds the funds and the recipients are dependent on its decisions. Further detail on group projects is at [[nimno.net/system/group-projects/]{.underline}](https://nimno.net/system/group-projects/).

> *The system must provide: an accounting tool for the common fund, a way to record coordinator actions, and a way to document group decisions. These are planned features.*

**Network Architecture and Decentralisation**

**Nodes**

The long-term vision for Direct Sponsor is not a single growing platform but a network of independent nodes --- separate installations of the same system, each serving its own community of recipients and sponsors. A node might serve a group of projects in a particular region, or a community organised around a particular kind of work. There is no requirement for a node to be large; it could serve a handful of recipients.

This design is intentional. A large centralised platform would recreate the institutional dynamics the system is designed to avoid: concentration of control, dependency on a third party, the risk of the platform\'s interests diverging from those of its users.

**Nostr Integration**

Nodes are linked via Nostr, a decentralised protocol that provides identity and communication infrastructure without requiring central control. Nostr\'s features allow cross-node identity verification and make impersonation significantly harder.

Current status: Nostr is partially integrated. Posts on the site are currently published as notes via the Direct Sponsor relay. Deeper integration --- including cross-node identity, the flagging system, and fraud prevention --- is on the roadmap but not yet built.

**Trust and Accountability**

Because there is no central authority with enforcement power, the system\'s integrity depends on transparency and on the reputational value of being part of the network. The accountability model works as follows:

- All transactions and commitments happen within the public system. Sponsors can see what is happening with their contributions.

- If a recipient solicits money from sponsors outside the system --- bypassing the public record --- sponsors can flag this.

- A recipient who acts outside the system\'s definition loses the benefit of being part of the Direct Sponsor network: the brand, the waiting queue of sponsors, the infrastructure, and the credibility that comes with network membership.

- There is no legal enforcement. The recipient can continue doing what they are doing if their sponsors are satisfied. They simply are no longer operating within the Direct Sponsor framework.

This is a reputational model, not a punitive one. The value of the network is the incentive for staying within it.

**How This Differs from Fundraisers**

For reference, the key differences between the two mechanisms on the site:

- **Fundraisers** are for specific items or goals. Anyone can contribute at any time. They have a defined target and a natural endpoint.

- **Sponsorship groups** are ongoing. They have a fixed, small membership. They involve a personal commitment rather than a one-off contribution. They send reminders and manage membership actively. They are designed to produce a relationship, not just a transaction.

Both exist on the site, but the sponsorship group model is the more important of the two. It is where the long-term vision of the project lives.

**Documentation Architecture**

This document is the internal reference layer --- it captures the full thinking without worrying about overwhelming a newcomer. The intention is for public-facing documentation to be built on top of it: simpler, shorter, and linking back here for those who want depth.

The principle is layered discovery rather than either information overload or opacity. A person landing on the site for the first time needs one paragraph. A potential sponsor needs a page. A node operator needs the full picture. Each layer links to the next.

> *One ongoing challenge: people arrive with a mental model of either charity (donate → organisation redistributes) or crowdfunding (campaign → goal → done). Direct Sponsor fits neither model. Public-facing documentation will need to address this directly rather than assuming the model is self-evident.*

**Current Status and Roadmap**

**Live**

- Fundraiser campaigns on directsponsor.net

- Basic Nostr integration --- posts published as notes via the Direct Sponsor relay

- Core site infrastructure

**In Development / Planned**

- Regular sponsorship group functionality (the subject of this document)

- Sponsor tier management --- active, standby, and queue

- Reminder and response-window system

- Deeper Nostr integration --- cross-node identity, flagging, fraud prevention

- Node infrastructure --- independent installations linked via Nostr

- Group fund accounting tools for recipient groups

- Coordinator activity recording and group decision documentation

**Related reading:** [[directsponsor.net]{.underline}](https://directsponsor.net/) • [[Small Groups (nimno.net)]{.underline}](https://nimno.net/notes/small-groups/) • [[Group Projects (nimno.net)]{.underline}](https://nimno.net/system/group-projects/)
