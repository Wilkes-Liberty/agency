# Workforce transfer (Slice 2)

Status: design and unregistered scaffolding. This is not an implementation
contract and is not ready for gate G1. Package 01 published the caller
ledger as draft [webcms#1900](https://github.com/Wilkes-Liberty/webcms/pull/1900)
(`docs/agency-extraction/ledger.json` at
`2216df30c3af736bd7f8b47832c2ea8572c1fd2b`). Ontology draft v0.1.0 landed
in [agency#25](https://github.com/Wilkes-Liberty/agency/pull/25). Package
05 shipped the authority register in
[agency#27](https://github.com/Wilkes-Liberty/agency/pull/27) with machine
names `legacy`, `shadow`, `agency` and `removed` (see
[Migration](MIGRATION.md)). Slice 2 expand still waits on operator G1.

The document distinguishes **observed** Agency Discovery behavior in this
package from **proposed** Slice 2 transfer. Slice 2 ledger rows below cite
that `ledger.json`. Draft ontology names are in `ontology/ontology.yml`;
they are not an implementation contract until G1. Host-tree class names
that the ledger inventories but this checkout still cannot open remain
**unverified** for expand.

No tenant name, policy period, operating record or credential belongs in
this package. Host identity, File Gate, Field Guard and Audit Chain remain
independent dependencies. Enabling `agency_staff` still creates no
workforce relationship, account, role or invitation, and sends no message.

## 1. Responsibilities in scope

Slice 2 rows from `docs/agency-extraction/ledger.json` at webcms#1900
(`2216df30c3af736bd7f8b47832c2ea8572c1fd2b`). Row ids S2-L1–L5 are this
design's labels, not ontology machine names.

| Proposed row | Current authority (ledger) | Target | Notes |
| --- | --- | --- | --- |
| S2-L1 Staff invitation and onboarding | Host `chronicle_staff_invite` | Agency Staff | Ledger: staff onboard Webform. Issuer-minted single-use token; the invitee does not choose classification. After Agency commits acceptance, the host adapter mints uid, fail-closed role and matching Profile. Keycloak remains the IdP; not a password signup. |
| S2-L2 Invitation protocol and `/invite` door | Host `chronicle_client_access` (not enabled) plus host `Query.wlInviteStatus` | Agency Staff, adapted | Ledger: Keycloak invitation protocol adapts into Agency Staff (issuer/subject, POST-only accept, per-read entitlement). Do not enable the dormant module. Host inspect door is `wlInviteStatus` on `/invite`; GET never consumes. |
| S2-L3 Personnel and profile evidence | Host `chronicle_personnel` plus profile configuration | Agency workforce | Ledger person/account/profile split: Profile holds marked evidence, not identity. Private files stay under File Gate. |
| S2-L4 Person / account / profile projection | Host `chronicle_api` person, account and profile projections | Agency projection; host keeps transport | Ledger S2 split: person (IdP), account (host uid/role), profile (evidence projection). Own-profile and account-summary contracts stay unchanged. |
| S2-L5 Recruiting editorial versus recruiting rules | Host editorial content and application form | Split: host keeps editorial content; Agency recruiting rules | Slice 2/3 boundary. **Open operator decision** (§10.4). |

Out of Slice 2: Discovery relationships (already Agency), CRM, intake,
engagement/time, client membership policy (placement reviewed in the
Slice 2 threat model; target remains a later slice unless the operator
moves it), marketing, credentials as a standalone capability, roster
availability and expenses.

Observed: `agency_staff` already owns invitation-only Discovery. Discovery
commands do not create a user, role, Profile, employment classification,
roster membership or assignment. Slice 2 must preserve that wall.

## 2. Target object types and actions

Proposed ontology subset. Draft v0.1.0 machine names are in
`ontology/ontology.yml` after agency#25. They remain draft: G1 is still
required before expand uses them as an implementation contract.
Existing Discovery storage names are observed and must not be reused as
staff provisioning paths.

| Proposed object | Observed related storage | Slice 2 role |
| --- | --- | --- |
| Person | Internal `agency_person` is a thin Discovery resolver (display name and contact) | Canonical person in the ontology. Discovery and workforce relationships may link to the same person; that link is not an entitlement. Ontology notes that workforce profile fields remain proposed. Ledger S2 split: person is not the Drupal account and not the Profile. |
| Principal binding | Internal `agency_principal` | Exact issuer/subject from the Keycloak invitation protocol. Contact never selects a principal. Reuse the host `IdentityProviderInterface`. |
| Organization / Membership | none in this package | Workforce membership is distinct from Discovery participation and from client membership. Draft ontology v0 proposes `agency_organization` and `agency_membership` for S2; expand still waits on G1. |
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
| `updateProfileEvidence` | Authorized coordinator or the entitled person for own-profile fields | Writes own-profile evidence. Field names must match the server marking map; caller-chosen markings that disagree are refused. Account-summary is a read projection, not a write audience. File bytes are not accepted; only File Gate references. |

Proposed reads (host transport, Agency projection):

- Inspect a staff invitation token: status only, matching the host
  `wlInviteStatus` `/invite` door. GET never consumes or binds.
- Own-profile projection for the current principal.
- Account-summary projection for the current principal.
- Coordinator read of one workforce relationship.

Lifecycle states proposed for the workforce relationship: `invited`,
`active`, `revoked`, `suspended`, `ended`. Draft ontology v0 currently
lists Discovery-oriented Relationship states; workforce-specific states
remain an operator G1 decision. Application/recruiting labels remain out
of this enum.

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
  workforce kind. The ledger's staff onboard Webform records employee
  and contractor provisioning; scaffolding allowlists those two and
  refuses Discovery/client kinds until the operator adds further kinds
  (§10.3).
- Acceptance may trigger host-side uid/role/profile provisioning through
  the host adapter **after** the Agency workforce commit. Agency does
  not mint a user. That is the ledger's account side of the
  person/account/profile split. Host provisioning failure must not be
  repaired by silently creating a second Agency relationship; it is an
  `uncertain` host follow-up against the committed receipt.
- Staff invitation copy and languages stay in the host catalog. This
  package ships no invitation wording.
- Host inspect for invitation doors is `Query.wlInviteStatus` on
  `/invite`. Inspect is read-only. GET never consumes or binds.
- Client-access remainders that would grant client-portal membership are
  not activated. The Keycloak invitation protocol adapts into Agency
  Staff without enabling `chronicle_client_access`. A membership grant,
  if present on the dormant module, stays out of Slice 2 expand
  (operator decision §10.5, reviewed with client-membership placement).

## 5. Person/profile API

Ledger S2 person/account/profile split: Agency owns the projection. The
host keeps HTTP transport, authentication session plumbing, CSRF, cache
headers and translation. Person identity stays with the IdP. The Drupal
account (uid/role) stays on the host adapter. Profile is evidence and an
allowlisted projection, not a second identity.

Allowlisted audiences for Slice 2:

| Audience | Writer | Reader | Contract to preserve |
| --- | --- | --- | --- |
| `own` | Entitled person, for fields marked writable on that audience | The same person | Existing own-profile contract. Fields, names and error shape stay host-compatible. |
| `account_summary` | Not a staff-invite side effect | The entitled person | Existing account-summary contract. Discovery, CRM and payment facts are not smuggled in. |

Coordinator and network audiences remain out of this scaffolding until
G1 reviews the network profile contract.

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

Exact table, field, route, service and test inventory is
`docs/agency-extraction/ledger.json` at webcms#1900 (`2216df30`).
**Pending 05** for the copy implementation. Host PHP class files remain
**unverified** from this checkout; expand reads the ledger rather than
re-inventorying.

## 7. Authority, shadow-write, reconciliation and rollback

Authority states match the `agency_migrate` register (one state per
domain; whether workforce is one row is §10.2). English labels such as
"legacy-authoritative" stay in prose:

| State | Agency writes | Agency reads | Legacy writes | Legacy reads |
| --- | --- | --- | --- | --- |
| `legacy` | no | no | yes | yes |
| `shadow` | yes, parallel, never used for authorization | no | yes | yes |
| `agency` | yes | yes | no (fail closed and alert) | read-only overlap |
| `removed` | yes | yes | no | no; lineage remains for audit verification |

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
  behavior. Public reason strings stay an operator decision (§10.7);
  host inspect uses `wlInviteStatus` and must not be treated as a
  consume or bind.
- No unexplained drift over the rehearsal window.

Rollback:

- Flip the workforce flag to `legacy`.
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
| S2-T12 | Shadow-state Agency records used for authorization | Authority flag | Host adapter is the only reader of the flag; Agency reads stay off until `agency` |

Residuals for 08: host message delivery, identity-provider outage during
accept, and mixed coordinator credentials (already refused by Discovery
eligibility; staff expand must keep that adapter).

## 9. Test-parity list

Test classes per module are inventoried in `ledger.json` at webcms#1900
(`2216df30`). The class-level port map is the assurance kit (package 08,
webcms#1897). Removal of a legacy module is blocked by any unmapped test
without a reviewed reason. Names below are ledger module scopes and
behavioral cases; this checkout still cannot open the host PHP files.

| Legacy scope | Behavioral cases to port | Agency or host-adapter destination |
| --- | --- | --- |
| `chronicle_staff_invite` | Staff onboard Webform: token works once; invitee cannot choose type; fail-closed role; matching profile bundle; not a password signup; private files remain File Gate-protected; languages stay in the host catalog | Workforce actions + host adapter tests |
| `chronicle_personnel` | Personnel field access; evidence update; unmarked field refused; revision preservation; Profile is not the person or the account | `updateProfileEvidence` + migrate reconciliation |
| `chronicle_client_access` | Keycloak invitation protocol tests only: issuer/subject, POST-only accept, per-read entitlement. Module enablement tests must not start passing because of this slice | Workforce invitation ceremony; no host module enable |
| Host `wlInviteStatus` / `/invite` | GET inspect does not consume; wrong subject shares the closed/not-found shape the host already exposes | Workforce inspect read + host transport |
| `chronicle_api` person/profile and account summary | Person/account/profile split; own-profile and account-summary fixtures stay green; allowlisted audiences; denied cross-person | Workforce reads + host transport |
| Host identity / private files | Identity deactivation and rebinding deny staff reads; File Gate UUID still authorizes delivery | Existing identity adapter + File Gate, not copied |

Discovery tests in this package remain the Slice 1 suite. Slice 2
scaffolding must not change their outcomes.

## 10. Open operator decisions

1. Approve `agency_*` workforce types with ID-preserving copy and lineage,
   rather than moving host-named providers into this package (extraction
   plan decision 1, still open).
2. Approve that workforce is one row in the existing `legacy` /
   `shadow` / `agency` / `removed` register (plan decision 2). Machine
   names are already shipped by agency#27; do not fork them.
3. Workforce classification allowlist: keep `employee` and `contractor`
   only in the package, matching the ledger's staff onboard Webform, or
   add further kinds by operator decision. Scaffolding currently
   allowlists those two and refuses Discovery/client kinds.
4. Recruiting: host editorial content stays host; Agency recruiting rules
   wait for Slice 3 versus a later Slice 2 expand. The proposed Slice 3
   design is [CRM transfer](CRM_TRANSFER.md) §10.6.
5. Client membership and eligibility policy: remain a later slice, or
   move after the Slice 2 threat-model review.
6. Own-profile writes during overlap: host Profile entity remains the
   writer until `agency`, with Agency shadow writes, versus an earlier
   Agency-authoritative evidence path.
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
- `AuthorityState` ids `legacy`, `shadow`, `agency` and `removed`,
  matching `agency_migrate`
- `AcceptStaffInvitation` carries the bearer only; POST stays a
  command-layer request-stack check, matching Discovery
- `updateProfileEvidence` writes `own` only and applies a server
  marking map (`display_name` is `personal`)

Tests instantiate value objects directly. There is no service
registration, no schema change and no Discovery behavior change.
Expand implements the interfaces after G1.
