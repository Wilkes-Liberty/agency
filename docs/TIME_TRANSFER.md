# Time transfer (Slice 4)

Status: **proposed design**. This is not an implementation contract and is
not a slice-design (G1) approval. No `agency_*` record types, services,
routes or copier plugins are registered by this change. Package 01
published the caller ledger as draft
[webcms#1900](https://github.com/Wilkes-Liberty/webcms/pull/1900)
(`docs/agency-extraction/ledger.json`). Ontology draft v0.1.0 landed in
[agency#25](https://github.com/Wilkes-Liberty/agency/pull/25). Package 05
shipped the authority register in
[agency#27](https://github.com/Wilkes-Liberty/agency/pull/27) with machine
names `legacy`, `shadow`, `agency` and `removed` (see
[Migration](MIGRATION.md)). [DEV-992](https://wilkesliberty.atlassian.net/browse/DEV-992)
Option A (2026-10-26) decided ID-preserving copy into tenant-neutral
`agency_*` types with a lineage map. Slice 4 expand still waits on Slice 2
and Slice 3 cutover and on a later operator G1 for this slice.

The document distinguishes **observed** Agency behavior in this package
from **proposed** Slice 4 transfer. Slice 4 ledger citations below pin
webcms `master` at `be5a1962` (2026-10-29 status sweep of
`docs/agency-extraction/ledger.json` / `LEDGER.md`). Draft ontology names
are in `ontology/ontology.yml`; they are not an implementation contract
until G1. Host-tree class names that the ledger inventories but this
checkout still cannot open remain **unverified** for expand.

No tenant name, policy period, operating record or credential belongs in
this package. Host identity, File Gate, Field Guard and Audit Chain remain
independent dependencies. Payment-engine ownership is unchanged. Enabling
`agency_staff` still creates no engagement, assignment, time record,
statement, client membership or feed, and sends no message.

## 1. Responsibilities in scope

Slice 4 rows from `docs/agency-extraction/ledger.json` at webcms `master`
`be5a1962` (ledger of record: DEV-999 / webcms#1900). That pin's live
ledger run reported **120** Slice 4 responsibilities still `legacy`,
**174** leftover host callers and **13** test classes to port; **0**
rows were `shadow`, `agency` or `removed`. Row ids S4-L1–L8 are this
design's labels, not ontology machine names.

| Proposed row | Current authority (ledger) | Target | Notes |
| --- | --- | --- | --- |
| S4-L1 Engagement | Host `chronicle_time` content entity `wl_time_engagement` | Agency engagement (`agency_engagement`, proposed `agency_engagement` submodule) | Agreed work container. Links to a client organization (Slice 3). Not a Discovery relationship and not a CRM opportunity. |
| S4-L2 Assignment | Host `chronicle_time` content entity `wl_time_assignment` | Agency assignment (`agency_assignment`) | Per-engagement assignment decides who may record versus review. Observed host rule: an administrator flag does not shortcut those checks. Assignment is not roster membership and not an access grant. |
| S4-L3 Time entry | Host `chronicle_time` content entity `wl_time_entry` | Agency time entry (`agency_time_entry`) | Minutes and billable flags are financial. An entry belongs to one assignment. |
| S4-L4 Time session | Host `chronicle_time` content entity `wl_time_session` | Agency time session (`agency_time_session`) | Open/closed/void timer. Draft ontology marks this type non-revisionable. |
| S4-L5 Time submission | Host `chronicle_time` content entity `wl_time_submission` | Agency time submission (`agency_time_submission`) | Bundles entries for review. Approval history is preserved exactly. Acceptance is not settlement. |
| S4-L6 Statement snapshots and invoice drafts | Host `chronicle_time` (pinned/approved snapshots) plus merchant invoice drafts | Agency statement (`agency_statement`) for snapshots; invoice drafts stay with the payment engine until the operator places them | Ontology `agency_statement` has no `legacy_sources` yet. Payment engine ownership is unchanged. |
| S4-L7 Client membership and eligibility policy | Host `chronicle_client_auth` | Agency policy; authentication and tenant identity wiring stay shared | Depends on Slice 3 client organizations and on engagement/assignment access. Placement was reviewed in the Slice 2 threat model and left open in [Workforce transfer](WORKFORCE_TRANSFER.md) §10.5. Distinct from Slice 2 roster `agency_membership`. |
| S4-L8 Business feeds and commercial-account projections | Host `chronicle_api` (business part) | Agency projections; host keeps transport | These feeds read across staff, CRM and engagement records, so they move after their sources. |

Out of Slice 4: Discovery relationships (already Agency), Slice 2
workforce/personnel, Slice 3 CRM and intake, expenses, credentials,
roster availability, marketing, contracts/agreements (Slice 5), the
disabled `wl_*` twin modules (DEV-801 / webcms#1681), and the payment
engine (orders, payment intents, provider settlement).

Observed: `agency_staff` already owns invitation-only Discovery.
Discovery commands do not create an engagement, assignment, time record
or statement. Slice 4 must preserve that wall and the converse: time
commands do not create Discovery, workforce or CRM authority.

## 2. Target object types and actions

Proposed ontology subset. Draft v0.1.0 machine names are in
`ontology/ontology.yml` after agency#25. They remain draft: Slice 4 G1
is still required before expand uses them as an implementation contract.
Existing Discovery storage names are observed and must not be reused as
engagement or time paths. This document does not add record types.

| Proposed object | Observed related storage | Slice 4 role |
| --- | --- | --- |
| Engagement (`agency_engagement`) | Host `wl_time_engagement` | Agreed work container. Organization link is required. Lifecycle proposed: `agreed`, `active`, `completed`, `cancelled`. |
| Assignment (`agency_assignment`) | Host `wl_time_assignment` | Offer/accept against one engagement and one person. Not roster membership. Not an access grant. Lifecycle proposed: `offered`, `accepted`, `declined`, `completed`. |
| Time entry (`agency_time_entry`) | Host `wl_time_entry` | Assignment-scoped minutes and billable flag. Lifecycle proposed: `drafted`, `submitted`, `accepted`, `rejected`. |
| Time session (`agency_time_session`) | Host `wl_time_session` | Open timer against an assignment. Draft ontology: not revisionable. Lifecycle proposed: `open`, `closed`, `void`. |
| Time submission (`agency_time_submission`) | Host `wl_time_submission` | Review bundle for a period. Approval of a submission is not settlement. Lifecycle proposed: `submitted`, `accepted`, `rejected`. |
| Statement (`agency_statement`) | Host pinned/approved snapshots (store **unverified** from this checkout) | Snapshot of accepted submissions. Payment stays outside Agency. Lifecycle proposed: `issued`, `accepted`, `disputed`, `settled`. |
| Client membership (name open, §10.3) | Host `chronicle_client_auth` membership/eligibility (stores **unverified**) | Eligibility to see or act on a client organization. Not Slice 2 roster `agency_membership` (legacy source `chronicle_personnel`). Not a Drupal role grant. |
| Commercial feed projections | Host `chronicle_api` business feeds | Allowlisted reads. No durable Agency entity. Host keeps HTTP transport. |

Proposed actions (ontology placeholders; no service yet):

| Action | Actor | Effect |
| --- | --- | --- |
| `offerAssignment` | Eligible coordinator (`agency_engagement.coordinate`) | Creates an assignment in `offered`. Creates no access grant, uid, role or Discovery entitlement. |
| `acceptAssignment` | Entitled principal | Moves `offered` → `accepted`. Acceptance is not settlement and not a roster write. |
| `submitTime` | Entitled principal | Submits the actor's own entries for a period. Entries in the submission must belong to the actor. |
| `acceptTimeSubmission` | Eligible coordinator | Accepts or rejects a submission. Optional host reviewer-approval policy. Does not move money. |
| `issueStatement` | Eligible coordinator | Snapshots accepted submissions. Payment stays outside Agency. |

Proposed reads (host transport, Agency projection):

- Contributor time workspace for assignments the principal may record.
- Reviewer workspace for assignments the principal may review.
- Coordinator read of one engagement and its assignments.
- Statement snapshot read for an entitled reviewer or coordinator.
- Commercial-account feed projections after their source domains are
  Agency-authoritative.

Lifecycle walls this slice must keep:

- Assignment accepted ≠ roster membership ≠ access grant.
- Time submission accepted ≠ statement issued ≠ payment initiated.
- Client membership ≠ employee/contractor classification ≠ Discovery
  participation.

## 3. Separation rule

Engagement/time records, workforce entitlements, Discovery entitlements
and payment-engine records never share a code path and never grant each
other's privileges.

Observed Discovery wall (`docs/DISCOVERY_CONTRACT.md`): no Discovery
command creates employment classification, roster membership or
assignment; CRM, staff, client, partner, pending and machine credentials
do not acquire Discovery authority through role names.

Slice 4 adds the converse and the settlement wall:

- Engagement, assignment, time and statement writes live under a later
  `Drupal\agency_engagement\` (or the operator-approved submodule name
  from [Ontology](ONTOLOGY.md) question 3). They must not call
  `RelationshipCommands`, `InvitationCommands`, `RelationshipReads`,
  workforce interfaces, or write `agency_discovery` /
  `agency_invitation` / `agency_entitlement`.
- Discovery and workforce commands must not call engagement interfaces
  or write `agency_engagement` / `agency_assignment` / `agency_time_*` /
  `agency_statement`.
- An assignment never authorizes a Discovery workspace, summary or
  queue row, and never writes Slice 2 roster membership.
- A Discovery or workforce entitlement never authorizes time record,
  review, statement issue or client-membership grant.
- Client membership never mints a broad employee or client Drupal role.
  Subject-binding and deny semantics stay exact.
- Statement `settled` is a snapshot state. The payment engine remains
  the authority for payment requests, provider acceptance and confirmed
  settlement.
- Shared Person or Organization rows, when introduced by earlier
  slices, are links only. Authorization is recomputed from the
  assignment-scoped or membership-scoped entitlement on every read.

The existing Discovery mutex and outbox remain Discovery's. Workforce
expand (Slice 2) needs its own transaction boundary. Engagement expand
needs a third. A time write cannot take the Discovery or workforce
fence. That storage work is expand, not this document.

## 4. Client membership and eligibility

The host `chronicle_client_auth` module stays enabled as the
authentication and tenant-identity adapter. Slice 4 proposes to move
**membership and eligibility policy** only.

This is not the dormant `chronicle_client_access` invitation protocol
(Slice 2 adapts that protocol into Agency Staff and must not enable the
dormant module). It is not Slice 2 roster `agency_membership`.

Rules:

- Tenant identity is derived by the host, never from a caller-supplied
  type argument. Cross-tenant denial is a slice gate.
- Eligibility is recomputed on every read from the Agency policy plus
  the current membership row. A cached feed or session flag is not
  authority.
- Granting or ending client membership does not create a user, role,
  Profile, engagement, assignment or Discovery relationship.
- A client-membership grant is not permission to record time. Recording
  still requires an accepted assignment on that engagement.
- A client-membership grant is not permission to read personnel,
  Discovery or financial records outside the allowlisted projection.
- Field Guard remains on coordinator grant/end paths. Person references
  are `personal`; eligibility flags are `internal` until a field map
  exists.

Observed host constraint to preserve (ledger / DEV-989
slice-specific acceptance; host PHP **unverified** from this checkout):
no broad employee or client role is granted by the membership move.
Tests must prove the existing subject-binding and deny semantics.

## 5. Business feeds and commercial projections

Ledger S4 split: Agency owns the projection. The host keeps HTTP
transport, authentication session plumbing, CSRF, cache headers and
translation.

These feeds read staff, CRM and engagement records. They therefore
cut over only after the source domains they project have left `legacy`.
A feed adapter may exist in shadow while sources are still host-owned;
it must not become the authority for any source record.

Rules:

- A feed is an audience-specific projection, not permission to read
  underlying personnel, client or financial records.
- Each projected property carries a marking. The projection allowlist
  is the intersection of audience, marking, entitlement and Field Guard
  where the host still enforces field permission.
- Host transport must use private no-store caching, matching Discovery
  reads.
- Identity is resolved before protected identifier lookup so missing
  and existing identifiers stay observably identical.
- Discovery, workforce invitation and payment-engine facts are not
  smuggled into a commercial-account feed.

Allowlisted audiences proposed for Slice 4 feeds remain an operator
decision (§10.5). Until that list is approved, expand ships no feed
route.

## 6. Data transfer

Lineage, copy and reconciliation tooling belong to `agency_migrate`
(package 05). Slice 4 specifies what that tool must preserve. Copier
plugins are host-supplied or a later capability-module concern; this
package does not ship them here.

| Legacy store (plan) | Proposed Agency type | Proposed copier plugin id | Preservation | Marking default until field map exists |
| --- | --- | --- | --- | --- |
| `wl_time_engagement` | `agency_engagement` | `time_engagement` | Integer id, UUID, revision ids, timestamps, revision authorship, organization reference, status, title | `internal`; unmapped field fails the batch |
| `wl_time_assignment` | `agency_assignment` | `time_assignment` | Same identity fields; engagement and person references; record-versus-review capability | `internal` |
| `wl_time_entry` | `agency_time_entry` | `time_entry` | Same identity fields; assignment reference; minutes; billable; status | `financial` for minutes and billable |
| `wl_time_session` | `agency_time_session` | `time_session` | Integer id, UUID, timestamps, assignment reference, started/ended, status. Draft ontology: no revision id to preserve | `internal` |
| `wl_time_submission` | `agency_time_submission` | `time_submission` | Same identity fields; period bounds; status; **approval history exactly** | `internal`; reviewer notes `confidential` if present |
| Pinned/approved statement snapshots | `agency_statement` | `time_statement` only if a durable host source exists | Snapshot totals, currency, linked accepted submissions | `financial` for total and currency |
| Merchant invoice drafts | none in this package | none | Stay on the payment engine / host | `financial` if a later slice copies them |
| Client membership / eligibility rows | Client-membership object or policy store (§10.3) | `client_membership` only if durable rows exist | id/UUID/revision if the host store is an entity; subject binding; organization; eligibility | `personal` for person; `internal` for eligibility |
| Business feed consumers | Host transport over Agency projection | none | Response contracts unchanged | Per property |

Rules:

- Agency defines `agency_*` types. Migration copies rows with the same
  numeric id, UUID, revision ids (when the type is revisionable),
  timestamps and revision authorship, and writes a lineage map. Moving
  a host-named entity provider (`wl_time_*`) into this package is
  rejected (DEV-992 Option A; Option B was declined).
- Historical Audit Chain events that name a legacy type stay untouched.
  One minimized lineage event per migrated batch links legacy references
  to Agency references; the verifier may resolve either.
- Markings travel with data. An unmapped property stops migration.
- Private files stay under File Gate. File bytes are not copied.
- Synthetic fixtures only. No live engagements, time rows, statements
  or credentials in this repository.

Exact table, field, route, service and test inventory is
`docs/agency-extraction/ledger.json` at webcms `master` `be5a1962`.
Host PHP class files remain **unverified** from this checkout; expand
reads the ledger rather than re-inventorying.

### 6.1 DEV-992 Option A conditions per type

Operator approval on 2026-10-26 (DEV-992) requires five conditions on
every copied type. They apply to Slice 4 as follows. Expand must fail
closed on any type that cannot meet all five.

#### Collision-free preflight

Before the first write, each copier checks destination integer ids,
UUIDs and (when revisionable) revision ids. A collision with a
different UUID fails the run; the copier does not renumber.

| Source | Extra preflight |
| --- | --- |
| `wl_time_engagement` | Destination `agency_engagement` id/UUID/revision free or already the same lineage pair. |
| `wl_time_assignment` | Same, plus the referenced engagement and person ids already have lineage or are in the same run's planned set. |
| `wl_time_entry` | Same, plus the referenced assignment is planned or already copied. |
| `wl_time_session` | Same on id/UUID only (not revisionable). Overlapping open sessions for one assignment fail closed rather than merge. |
| `wl_time_submission` | Same, plus every bundled entry id is planned or already copied. |
| Statement snapshots | Same if a source exists; refuse if a snapshot total cannot be reproduced from linked accepted submissions. |
| Client membership | Same if the host store is an entity. Duplicate subject+organization active rows fail closed. |

#### Reference retargeting

Preserving numbers alone does not preserve references. Each copier
must retarget entity-type names, routes and serialized references from
`wl_time_*` / host membership types to `agency_*` names.

| Source | References that must move |
| --- | --- |
| `wl_time_engagement` | Organization id/UUID (Slice 3 `agency_organization` or host CRM contact until S3 cutover); routes that embed the engagement id; serialized entity-type strings in queues, state and config. |
| `wl_time_assignment` | Engagement and person references; record/review capability fields; routes and deep links that start a timer from an engagement. |
| `wl_time_entry` | Assignment reference; submission join rows; any serialized field that names `wl_time_entry`. |
| `wl_time_session` | Assignment reference; tracker attribution tokens (opaque; do not decode into identity). |
| `wl_time_submission` | N:M entry join; reviewer/actor hashes; approval-history rows that name the legacy type. |
| Statement snapshots | N:M submission join; engagement grouping; currency/total fields. |
| Client membership | Person/subject and organization references; eligibility policy keys; authmap remains host-owned and is not retargeted to an Agency user. |
| Feeds | Projection property paths; not a row copy. Host routes stay; payload type names change when sources are Agency-authoritative. |

A leftover serialized `wl_time_*` class name in a queue, state or
config item is a drain blocker (G7), not something the copier silently
rewrites outside the lineage map.

#### History and access reconciliation

Reconciliation verifies revisions, references, approval snapshots and
access-decision parity. Historical Audit Chain evidence is never
rewritten.

| Source | Reconciliation extras |
| --- | --- |
| `wl_time_engagement` | Count parity; status digest; organization-reference integrity. |
| `wl_time_assignment` | Count parity; engagement and person integrity; record-versus-review access-decision parity for the same principal token. |
| `wl_time_entry` | Count parity; minutes/billable financial digest; assignment integrity. |
| `wl_time_session` | Count parity; open/closed/void digest; no two open sessions for the same assignment after copy. |
| `wl_time_submission` | Count parity; **approval-history exactness**; period bounds; entry-join integrity. |
| Statement snapshots | Snapshot byte-or-field digest; linked submission set; totals. |
| Client membership | Count parity; subject-binding parity; deny-semantics parity (missing and existing identifiers stay identical). |
| Feeds | Frozen host fixture parity after sources cut over; not a row digest. |

#### Resumable, idempotent copying

Retries must not duplicate records or overwrite divergent data. Observed
`agency_migrate` behavior: each record shares a transaction with its
lineage row; resume continues from the last committed source id; a
second complete run is a no-op; an occupied id with a different UUID is
refused.

Slice 4 adds: an interrupted submission copy must not leave a
submission without its entry-join rows. Session copy must not close or
void a source session. Approval-history rows copy in the same
transaction as the submission.

#### Authority cutover and rollback

One authoritative writer per domain. Host adapters read the
`agency_migrate` register. Shadow Agency rows are never used for
authorization. Rollback is replay through the lineage map, not a
database restore. Rehearse on a staging clone before any production
cutover. Production cutover is not authorized by this document.

Whether engagement/time, client membership and feeds share one register
row is §10.2.

## 7. Authority, shadow-write, reconciliation and rollback

Authority states match the `agency_migrate` register. English labels
such as "legacy-authoritative" stay in prose:

| State | Agency writes | Agency reads | Legacy writes | Legacy reads |
| --- | --- | --- | --- | --- |
| `legacy` | no | no | yes | yes |
| `shadow` | yes, parallel, never used for authorization | no | yes | yes |
| `agency` | yes | yes | no (fail closed and alert) | read-only overlap |
| `removed` | yes | yes | no | no; lineage remains for audit verification |

The host adapter reads this flag. This package ships no default and no
configuration schema for a Slice 4 domain in this change. The operator
still approves each transition as a Decision log line.

Shadow-write points for Slice 4 (after the legacy row commits, or in
the same host unit of work with a recorded uncertain recovery if one
side fails):

1. Engagement create/update after the legacy `wl_time_engagement` write.
2. `offerAssignment` / `acceptAssignment` after the legacy assignment
   write.
3. Time-session open/close/void after the legacy session write.
4. Time-entry draft/update after the legacy entry write.
5. `submitTime` after the legacy submission and entry-status write.
6. `acceptTimeSubmission` after the legacy approval-history write.
7. `issueStatement` after the legacy snapshot write, if that store
   exists.
8. Client-membership grant/end after the legacy membership write.

Feeds are reads. They do not shadow-write source records.

Reconciliation checks specific to Slice 4 (clean report required before
cutover):

- Count parity: engagements, assignments, entries, sessions,
  submissions, statement snapshots, membership rows, File Gate
  references if any.
- Per-field digests for financial properties (minutes, billable, totals,
  currency).
- Reference integrity: engagement → organization; assignment →
  engagement + person; entry/session → assignment; submission ↔
  entries; statement ↔ submissions; membership → person + organization.
- Access-decision parity: a principal entitled only to record cannot
  review; a reviewer cannot record on an assignment they do not hold;
  an administrator flag does not shortcut assignment checks; a
  Discovery participant cannot read time; a client member cannot read
  another organization's engagements; own-entry submits refuse foreign
  entries.
- Approval-history and statement-snapshot exactness.
- Token/session ceremony: voiding a session does not delete history; a
  closed session does not reopen on replay; submit is consume-once for
  drafted entries under the submission lock.
- No unexplained drift over the rehearsal window.

Proposed SLOs (DEV-989; not approved): 99.5% monthly API availability,
p95 read under 300 ms, outbox age under 150 s, zero unexplained
reconciliation drift, batch restart within 5 minutes.

Rollback:

- Flip the Slice 4 domain flag(s) to `legacy`.
- Replay Agency writes to legacy through the lineage map. Do not
  restore a database backup as the rollback procedure.
- Limits: a statement already shown to a reviewer is not unissued; a
  payment-engine invoice draft already created is not deleted by this
  rollback; capture versus send stay distinct. Historical evidence is
  never re-chained. Authority `removed` has no rollback edge.
- Rehearse on a staging clone before any production cutover.

Gates proposed by DEV-989 (operator approves G1, G4, G5 and G7):
G1 design → G2 expand → G3 shadow (clean reconciliation for 3
consecutive days, proposed) → G4 rehearsal → G5 cutover → G6 one
overlap release → G7 removal. This document is proposed G1 material
only.

## 8. Threat-model inputs

A dedicated Slice 4 threat model is later work. Inputs from this
design:

| ID | Abuse case | Boundary | Proposed mitigation |
| --- | --- | --- | --- |
| S4-T1 | Assignment grants a Discovery workspace or roster membership | Entitlement | Separate types, commands and tables; denial tests both directions |
| S4-T2 | Accepted time is treated as settlement or moves money | Settlement wall | `acceptTimeSubmission` and `issueStatement` do not call the payment engine; statement `settled` is a snapshot state |
| S4-T3 | Cross-person time read or submit | Assignment | Fresh assignment entitlement on every read/write; foreign entries refused on submit |
| S4-T4 | Cross-tenant engagement or membership read | Tenant | Tenant from the authenticated principal, never from a tool argument |
| S4-T5 | Administrator role shortcuts record/review checks | Assignment | Preserve the host rule that admin is not a bypass; parity tests |
| S4-T6 | Client membership mints an employee or client role | Membership | Policy-only move; no role grant; subject-binding deny tests |
| S4-T7 | Privilege inheritance through shared Person or Organization | Links | Links are not grants; Field Guard remains on coordinator paths |
| S4-T8 | Migration-time exposure of financial or personal fields | Markings | Unmapped marking fails the batch; minutes/billable/totals are `financial` |
| S4-T9 | Replay or double submit creates a second submission | Ceremony | Idempotency key plus consume-once under row lock; second run is a no-op |
| S4-T10 | Statement rewrite of historical approval | Snapshots | Approval history and snapshots copy exactly; historical Audit Chain rows are never rewritten |
| S4-T11 | Commercial feed smuggles Discovery, invitation or payment facts | Projection | Allowlist intersection; unknown fields ignored |
| S4-T12 | Shadow-state Agency records used for authorization | Authority flag | Host adapter is the only reader of the flag; Agency reads stay off until `agency` |
| S4-T13 | Enabling dormant `chronicle_client_access` as a shortcut | Host | Explicit non-dependency; Slice 2 already forbids enablement |
| S4-T14 | Payment-engine invoices moved into Agency as `agency_statement` | Payment boundary | Invoice drafts stay out until the operator places them (§10.4) |

Residuals for the later threat model: host timer-attribution tokens,
identity-provider outage during accept, mixed coordinator credentials
(already refused by Discovery eligibility), and feed consumers that
still serialize `wl_time_*` type names.

## 9. Test-parity list

Test classes per module are inventoried in `ledger.json` at webcms
`master` `be5a1962`. That pin's sweep counted **13** Slice 4 classes to
port. The class-level port map is the assurance kit (DEV-1006 /
webcms#1897). Removal of a legacy module is blocked by any unmapped
test without a reviewed reason. Names below are ledger module scopes
and behavioral cases; this checkout still cannot open the host PHP
files.

| Legacy scope | Behavioral cases to port | Agency or host-adapter destination |
| --- | --- | --- |
| `chronicle_time` engagement | Create/update preserves id/UUID/revision; organization link required; cancelled/completed are terminal | Engagement actions + migrate reconciliation |
| `chronicle_time` assignment | Record versus review is assignment-scoped; administrator flag does not shortcut; offer creates no access grant | `offerAssignment` / `acceptAssignment` + access-decision parity |
| `chronicle_time` entry/session | Timer open/close/void; drafted entry minutes/billable; session is not a submission | Time session/entry writes + migrate |
| `chronicle_time` submission | Submit consumes drafted entries once; foreign entries refused; approval history exact; acceptance is not settlement | `submitTime` / `acceptTimeSubmission` |
| `chronicle_time` statements | Pinned/approved snapshot grouped by engagement; totals match accepted unbilled work; snapshot is not an invoice | `issueStatement` + payment-engine boundary tests |
| `chronicle_client_auth` membership | Subject-binding; deny semantics for missing and existing identifiers; no broad employee or client role grant | Client-membership policy + host auth adapter |
| `chronicle_api` business feeds | Allowlisted commercial-account fixtures stay green; Discovery and payment facts absent | Host transport + Agency projection |
| Host identity / private files | Identity deactivation and rebinding deny time reads; File Gate UUID still authorizes delivery | Existing identity adapter + File Gate, not copied |

Discovery and workforce tests in this package remain the Slice 1 and
Slice 2 suites. Slice 4 design must not change their outcomes.

## 10. Open operator decisions

This list is proposed, not a G-gate checklist that records approval.

1. Confirm `agency_*` engagement/time types with ID-preserving copy and
   lineage (DEV-992 Option A already decided the copy method; G1 still
   chooses the type set and submodule name).
2. Whether Slice 4 is one authority-register row (`engagement`) or
   three (`engagement`, `client_membership`, `commercial_feed`).
   Machine names `legacy` / `shadow` / `agency` / `removed` are already
   shipped by agency#27; do not fork them.
3. Client membership representation: a new Agency object distinct from
   Slice 2 roster `agency_membership`, a membership kind on that type,
   or policy-plus-entitlement with no extra entity. This document does
   not add a record type.
4. Statement versus invoice-draft placement: `agency_statement` copies
   pinned/approved snapshots only, and merchant invoice drafts stay on
   the payment engine, or a later decision moves drafts.
5. Commercial-feed audiences and cutover: wait until Slice 2, Slice 3
   and engagement sources are `agency`, or allow a read adapter earlier
   that still treats host rows as authority.
6. Time-session revision policy: keep the draft ontology's
   non-revisionable session, or preserve host revisions if the ledger
   shows `wl_time_session` is revisionable (host PHP unverified).
7. Public refusal strings for cross-person submit and closed
   assignments: preserve host strings via the host adapter, or adopt
   Discovery's closed/`not_found` shape.
8. Proposed window in DEV-989 (design Nov 9–20 2026, expand Nov 23 –
   Dec 18, shadow Dec 14 – Jan 6 with a proposed change freeze Dec 21 –
   Jan 4, rehearsal Jan 6, cutover Jan 7–8) remains a schedule
   proposal, not an authorization.

## Out of this change

No record types, services, routes, permissions, configuration schema,
copier plugins or unregistered PHP scaffolding. Expand implements the
ontology subset after Slice 4 G1 and after the source domains this
slice depends on (workforce identity/person links and client
organizations) are designed.
