# Workforce transfer (Slice 2)

Status: design and unregistered scaffolding. This is not an implementation
contract and is not ready for gate G1. Slice 2 expand waits on ledger
completion (package 01), ontology v0 (package 02) and operator G1 approval.

The document distinguishes **observed** Agency Discovery behavior in this
package from **proposed** Slice 2 transfer. Rows that depend on the caller
ledger or ontology v0 are marked **pending**. Legacy host source listed in
the extraction plan was not readable from this checkout; mappings that
need that source are **unverified** until reconciled against the ledger
and the host tree.

No tenant name, policy period, operating record or credential belongs in
this package. Host identity, File Gate, Field Guard and Audit Chain remain
independent dependencies. Enabling `agency_staff` still creates no
workforce relationship, account, role or invitation, and sends no message.

## 1. Responsibilities in scope

Proposed ledger rows for Slice 2. Identifiers are placeholders until
package 01 publishes the canonical ledger.

| Proposed row | Current authority (plan) | Target | Notes |
| --- | --- | --- | --- |
| S2-L1 Staff invitation and onboarding | Host `chronicle_staff_invite` | Agency Staff | Employee/contractor invitation, single-use token, classification chosen by the issuer not the invitee. Account, role and profile-bundle minting stay on the host adapter after Agency commits acceptance. **Pending 01.** |
| S2-L2 Client-access invitation protocol (dormant) | Host `chronicle_client_access` (not enabled) | Agency Staff, adapted | Issuer/subject binding, POST-only acceptance, per-read entitlement. Do not enable or depend on the dormant host module. **Pending 01.** |
| S2-L3 Personnel and profile evidence | Host `chronicle_personnel` plus profile configuration | Agency workforce | Personnel facts and marked profile evidence. Private files stay under File Gate. **Pending 01.** |
| S2-L4 Person/profile API projection | Host `chronicle_api` person/profile and account projections | Agency projection; host keeps transport | Existing own-profile and account-summary response contracts stay unchanged. **Pending 01.** |
| S2-L5 Recruiting editorial versus recruiting rules | Host editorial content and application form | Split: host keeps editorial content; Agency recruiting rules | Slice 2/3 boundary. **Open decision.** **Pending 01.** |

Out of Slice 2: Discovery relationships (already Agency), CRM, intake,
engagement/time, client membership policy (placement reviewed in the
Slice 2 threat model; target remains a later slice unless the operator
moves it), marketing, credentials as a standalone capability, roster
availability and expenses.

Observed: `agency_staff` already owns invitation-only Discovery. Discovery
commands do not create a user, role, Profile, employment classification,
roster membership or assignment. Slice 2 must preserve that wall.

## 2. Target object types and actions

Proposed ontology subset. Machine names and property lists are **pending
02**. Existing Discovery storage names are observed and must not be
reused as staff provisioning paths.

| Proposed object | Observed related storage | Slice 2 role |
| --- | --- | --- |
| Person | Internal `agency_person` is a thin Discovery resolver (display name and contact) | Canonical person in the ontology. Discovery and workforce relationships may link to the same person; that link is not an entitlement. Whether expand extends the thin row or adds a personnel record type is **pending 02**. |
| Principal binding | Internal `agency_principal` | Exact issuer/subject. Contact never selects a principal. Reuse the host `IdentityProviderInterface`. |
| Organization / Membership | none in this package | Workforce membership is distinct from Discovery participation and from client membership. **Pending 02.** |
| Relationship (workforce) | Internal `agency_discovery` | New workforce relationship type. Never the Discovery entity. |
| Invitation (staff) | Internal `agency_invitation` (Discovery generations) | New staff invitation type. Never share the Discovery generation table or command class. |
| Entitlement (workforce) | Internal `agency_entitlement` (Discovery grants) | New workforce entitlement. Never share the Discovery grant table or command class. |
| Profile evidence | none | Marked fields plus File Gate references. Not a public Profile entity. |

Proposed actions (input schemas in scaffolding; no service yet):

| Action | Actor | Effect |
| --- | --- | --- |
| `inviteStaff` | Eligible coordinator | Creates a workforce relationship in an invited state, pending workforce entitlement and a captured staff invitation. Classification is an input from the issuer. No uid, role, Profile or message send. |
| `acceptStaffInvitation` | Authenticated intended principal, POST only | Consumes one generation under a row lock, activates workforce entitlement, then asks the host adapter to mint the host account/role/profile bundle. Wrong account, reused token and GET are refusals. |
| `revokeStaffInvitation` | Assigned coordinator | Revokes generations and workforce entitlement. Stale expected version still applies, matching Discovery denial. Does not reopen Discovery state. |
| `updateProfileEvidence` | Authorized coordinator or the entitled person for own-profile fields | Writes marked evidence. Unmapped markings refuse. File bytes are not accepted; only File Gate references. |

Proposed reads (host transport, Agency projection):

- Inspect a staff invitation token: status only. GET never consumes or binds.
- Own-profile projection for the current principal.
- Account-summary projection for the current principal.
- Coordinator read of one workforce relationship.

Lifecycle states proposed for the workforce relationship, **pending 02**:
`invited`, `active`, `revoked`, `suspended`, `ended`. These are not
Discovery states. Application/recruiting labels remain out of this enum.

## 3. Separation rule

Staff and contractor provisioning and Discovery entitlements never share a
code path and never grant each other's privileges.

Observed Discovery wall (`docs/DISCOVERY_CONTRACT.md`): no Discovery
command creates employment classification, roster membership or
assignment; CRM, staff, client, partner, pending and machine credentials
do not acquire Discovery authority through role names.

Slice 2 adds the converse:

- `inviteStaff` / `acceptStaffInvitation` / `revokeStaffInvitation` live
  under `Drupal\agency_staff\Workforce\` and must not call
  `RelationshipCommands`, `InvitationCommands`, `RelationshipReads` or
  write `agency_discovery` / `agency_invitation` / `agency_entitlement`.
- Discovery commands must not call workforce interfaces or write
  workforce types.
- A workforce entitlement never authorizes a Discovery workspace,
  summary or queue row.
- A Discovery entitlement never authorizes staff invitation, host role
  provisioning or personnel evidence.
- Classification values `discovery`, `client`, `partner` and `supplier`
  are refused on staff invite input.
- Shared Person or Principal rows, when introduced, are links only.
  Authorization is recomputed from the relationship-scoped entitlement
  on every read.

The existing Discovery mutex and outbox remain Discovery's. Workforce
expand needs its own transaction boundary so a staff write cannot take
the Discovery fence, and a Discovery write cannot take a workforce fence.
That storage work is expand, not this scaffolding.

## 4. Client-access invitation protocol

The dormant host client-access module is not a dependency and must not be
enabled by this transfer. Slice 2 adapts the protocol into Agency Staff.

Observed Discovery ceremony that Slice 2 reuses as rules, not as classes:

- Intended identity is an exact issuer/subject pair from the host
  identity provider. Contact data never selects or merges a principal.
- Invitation capture stores only a hash of a cryptographically random
  bearer token. Capture is not sending.
- `inspect` is read-only. GET never consumes or binds.
- `accept` requires POST inside the domain service, not only at the
  HTTP route. Acceptance locks the generation row, checks expiry,
  pending entitlement and exact issuer/subject, consumes once, and
  records evidence in the same transaction.
- Used token cannot bind again. A bound subject may open an already
  authorized workspace without rebinding.
- Every read revalidates identity, binding and entitlement. Missing,
  unrelated, revoked, suspended, deactivated and rebound contexts share
  the same `not_found` refusal.

Adaptations for staff, distinct from Discovery:

- The invitee does not choose classification. The issuer supplies a
  workforce kind. Scaffolding allowlists `employee` and `contractor`
  until the ledger lists any further kinds.
- Acceptance may trigger host-side uid/role/profile provisioning through
  the host adapter **after** the Agency workforce commit. Agency does
  not mint a user. Host provisioning failure must not be repaired by
  silently creating a second Agency relationship; it is an `uncertain`
  host follow-up against the committed receipt.
- Staff invitation copy and languages stay in the host catalog. This
  package ships no invitation wording.
- Client-access remainders that would grant client-portal membership are
  not activated. If the ledger shows the dormant module also encoded a
  client membership grant, that grant stays out of Slice 2 expand
  (**pending 01**, reviewed with client-membership placement).

## 5. Person/profile API

Agency owns the projection. The host keeps HTTP transport, authentication
session plumbing, CSRF, cache headers and translation.

Allowlisted audiences for Slice 2:

| Audience | Writer | Reader | Contract to preserve |
| --- | --- | --- | --- |
| `own` | Entitled person, for fields marked writable on that audience | The same person | Existing own-profile contract. Fields, names and error shape stay host-compatible. |
| `account_summary` | Not a staff-invite side effect | The entitled person | Existing account-summary contract. Discovery, CRM and payment facts are not smuggled in. |

Coordinator and network audiences are **pending 02** and the network
profile contract. They are not part of this scaffolding.

Rules:

- A shared profile is an audience-specific projection, not permission to
  read underlying personnel, client or financial records.
- Each projected property carries a marking. The projection allowlist is
  the intersection of audience, marking, entitlement and Field Guard
  where the host still enforces field permission.
- Host transport must use private no-store caching, matching Discovery
  reads.
- Identity is resolved before protected identifier lookup so missing and
  existing identifiers stay observably identical.

## 6. Data transfer

Lineage, copy and reconciliation tooling belong to `agency_migrate`
(package 05). Slice 2 specifies what that tool must preserve.

| Legacy store (plan) | Proposed Agency type | Preservation | Marking default until field map exists |
| --- | --- | --- | --- |
| Staff invitation / generation rows | Workforce invitation | Numeric id, UUID, timestamps, expiry, consumed/revoked status. Token plaintext is never copied; re-hash if a hash already exists. | `personal` for contact routes |
| Staff classification / type on the user | Workforce relationship property plus host adapter binding | Exact stored kind; do not infer from role name | `internal` |
| Host uid, role, Profile bundle | Host adapter binding, not an Agency user entity | uid remains host-owned; lineage maps person UUID ↔ host uid | `internal` |
| Personnel records | Person / profile evidence | id, UUID, revision ids, revision authorship, created/changed | Field-level; unmapped field fails the batch |
| Private file fields | File Gate reference | File UUID and access flags only. Bytes stay in File Gate. | `personal` or `confidential` as mapped |
| Person/profile API consumers | Host transport over Agency projection | Response contracts unchanged | Per property |

Rules:

- Agency defines `agency_*` types. Migration copies rows with the same
  numeric id, UUID, revision ids, timestamps and revision authorship, and
  writes a lineage map. Moving a host-named entity provider into this
  package is rejected.
- Historical Audit Chain events that name a legacy type stay untouched.
  One minimized lineage event per migrated batch links legacy references
  to Agency references; the verifier may resolve either.
- Markings travel with data. An unmapped property stops migration.
- Synthetic fixtures only. No live personnel, receipts or credentials in
  this repository.

**Pending 01** for the exact table/field inventory. **Pending 05** for
the copy implementation. **Unverified** against the host tree from this
checkout.

## 7. Authority, shadow-write, reconciliation and rollback

Authority states (one state per domain; workforce is one domain):

| State | Agency writes | Agency reads | Legacy writes | Legacy reads |
| --- | --- | --- | --- | --- |
| `legacy_authoritative` | no | no | yes | yes |
| `shadow` | yes, parallel, never used for authorization | no | yes | yes |
| `agency_authoritative` | yes | yes | no (fail closed and alert) | read-only overlap |
| `legacy_removed` | yes | yes | no | no; lineage remains for audit verification |

The host adapter reads this flag. This package ships no default and no
configuration schema for it in this change. A later expand may document
the host key; the operator still approves each transition as a Decision
log line.

Shadow-write points for Slice 2:

1. `inviteStaff` after the legacy invitation row commits.
2. `acceptStaffInvitation` after legacy acceptance and host provisioning
   commit, or in the same host unit of work with a recorded uncertain
   recovery if one side fails.
3. `revokeStaffInvitation` after legacy revocation.
4. `updateProfileEvidence` after the legacy personnel/profile write.

Reconciliation checks specific to Slice 2 (clean report required before
cutover, tooling in package 05):

- Count parity: invitations, workforce relationships, persons, evidence
  fields, File Gate references.
- Per-field digests for marked personnel properties.
- Reference integrity: invitation → relationship → person → principal;
  evidence → person; file ref → File Gate id.
- Access-decision parity: a principal entitled only for staff cannot
  read Discovery; a Discovery participant cannot accept a staff
  invitation issued to a different subject; own-profile and
  account-summary allowlists match the frozen host fixtures.
- Token ceremony parity: GET inspect does not consume; POST accept
  consumes once; wrong issuer/subject is `wrong_account` or equivalent
  without leaking whether the token existed beyond existing host
  behavior. **Pending 01** to confirm the public reason strings the host
  already exposes.
- No unexplained drift over the rehearsal window.

Rollback:

- Flip the workforce flag to `legacy_authoritative`.
- Replay Agency writes to legacy through the lineage map. Do not restore
  a database backup as the rollback procedure.
- Limits: a message the host already sent is not unsent; capture versus
  send stay distinct. A host uid already minted is not deleted by
  rollback; the host adapter records that the Agency relationship is no
  longer authoritative and legacy remains the reader. Historical
  evidence is never re-chained.
- Rehearse on a staging clone before any production cutover. Production
  cutover is not authorized by this document.

## 8. Threat-model inputs

Package 08 writes the Slice 2 threat model. Inputs from this design:

| ID | Abuse case | Boundary | Proposed mitigation |
| --- | --- | --- | --- |
| S2-T1 | Staff invitation grants a Discovery workspace | Entitlement | Separate types, commands and tables; denial tests both directions |
| S2-T2 | Discovery participant accepts a staff token | Identity | Exact issuer/subject; classification not self-selected |
| S2-T3 | GET or replay accepts an invitation | Ceremony | POST-only inside the service; consume-once under row lock; idempotent replay does not rebind |
| S2-T4 | Cross-person own-profile or account-summary read | Projection | Fresh entitlement and audience allowlist on every read |
| S2-T5 | Cross-tenant read or invite | Tenant | Tenant from the authenticated principal, never from a tool argument; cross-tenant denial is a slice gate |
| S2-T6 | Privilege inheritance through shared Person | Person link | Links are not grants; Field Guard remains on coordinator paths |
| S2-T7 | Migration-time exposure of personal or financial fields | Markings | Unmapped marking fails the batch; evidence metadata stay allowlisted |
| S2-T8 | Enabling the dormant client-access module as a shortcut | Host | Explicit non-dependency; no module enablement in Agency or host expand for this slice |
| S2-T9 | Agency mints a uid and becomes a second account authority | Host adapter | Agency commits workforce state first; host mints uid; uncertain recovery if the host step fails |
| S2-T10 | Inline file bytes in profile evidence | File Gate | Value objects refuse bytes, data URIs and remote file bodies; File Gate UUID only |
| S2-T11 | Client membership policy smuggled through staff accept | Placement | Out of Slice 2 expand until the operator places client membership |
| S2-T12 | Shadow-state Agency records used for authorization | Authority flag | Host adapter is the only reader of the flag; Agency reads stay off until `agency_authoritative` |

Residuals for 08: host message delivery, identity-provider outage during
accept, and mixed coordinator credentials (already refused by Discovery
eligibility; staff expand must keep that adapter).

## 9. Test-parity list

Class-level inventory is **pending 01** and the assurance kit (package
08). Removal of a legacy module is blocked by any unmapped test without
a reviewed reason. This checkout could not read the host tree, so names
below are module scopes and behavioral cases, not claimed class names.

| Legacy scope | Behavioral cases to port | Agency or host-adapter destination |
| --- | --- | --- |
| `chronicle_staff_invite` | Token works once; invitee cannot choose type; fail-closed role; matching profile bundle; not a password signup; private files remain File Gate-protected; languages stay in the host catalog | Workforce actions + host adapter tests |
| `chronicle_personnel` | Personnel field access; evidence update; unmarked field refused; revision preservation | `updateProfileEvidence` + migrate reconciliation |
| `chronicle_client_access` | Protocol tests only: issuer/subject, POST-only accept, per-read entitlement. Module enablement tests must not start passing because of this slice | Workforce invitation ceremony; no host module enable |
| `chronicle_api` person/profile and account summary | Own-profile and account-summary fixtures stay green; allowlisted audiences; denied cross-person | Workforce reads + host transport |
| Host identity / private files | Identity deactivation and rebinding deny staff reads; File Gate UUID still authorizes delivery | Existing identity adapter + File Gate, not copied |

Discovery tests in this package remain the Slice 1 suite. Slice 2
scaffolding must not change their outcomes.

## 10. Open operator decisions

1. Approve `agency_*` workforce types with ID-preserving copy and lineage,
   rather than moving host-named providers into this package (extraction
   plan decision 1, still open).
2. Approve the four authority states and that workforce is one register
   row (plan decision 2).
3. Workforce classification allowlist: keep `employee` and `contractor`
   only in the package, or add further kinds after the ledger lists them.
   Scaffolding currently allowlists those two and refuses Discovery/client
   kinds.
4. Recruiting: host editorial content stays host; Agency recruiting rules
   wait for Slice 3 versus a later Slice 2 expand.
5. Client membership and eligibility policy: remain a later slice, or
   move after the Slice 2 threat-model review.
6. Own-profile writes during overlap: host Profile entity remains the
   writer until `agency_authoritative`, with Agency shadow writes, versus
   an earlier Agency-authoritative evidence path.
7. Public refusal strings for wrong-account and closed staff invitations:
   preserve host strings via the host adapter, or adopt Discovery's
   `wrong_account` / `invitation_closed` / `already_used` / `not_found`.
8. Whether `resend` is in Slice 2 expand or a follow-up. Scaffolding
   does not include a resend input.

## Scaffolding in this change

Unregistered, tenant-neutral PHP under
`modules/agency_staff/src/Workforce/`:

- `WorkforceCommandsInterface` and `WorkforceReadsInterface`
- `WorkforceHostAdapterInterface` for authority, post-accept host
  provisioning and captured-invitation delivery
- Value objects for the four actions' inputs, classification, audience,
  marking, File Gate reference, idempotency key and host account binding

Tests instantiate value objects directly. There is no service
registration, no schema change and no Discovery behavior change.
Expand implements the interfaces after G1.
