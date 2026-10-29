# Workforce transfer (Slice 2)

Status: **proposed design ready for operator gate G1**. This is not an
implementation contract and does not register Slice 2 record types.
Package 01 published the caller ledger as draft
[webcms#1900](https://github.com/Wilkes-Liberty/webcms/pull/1900). Slice 2
citations below pin webcms `master` at
[`be5a1962`](https://github.com/Wilkes-Liberty/webcms/commit/be5a1962)
(2026-10-29 sweep of `docs/agency-extraction/ledger.json` / `LEDGER.md`).
Ontology draft v0.1.1 in this change adds the recruiting relationship
kind, a proposed `agency_recruiting` boundary, and proposed S2
application, posting, profile-evidence, staff-invitation and
staff-onboard types. Package 05 shipped the authority register in
[agency#27](https://github.com/Wilkes-Liberty/agency/pull/27) with
machine names `legacy`, `shadow`, `agency` and `removed` (see
[Migration](MIGRATION.md)). [DEV-992](https://wilkesliberty.atlassian.net/browse/DEV-992)
Option A (2026-10-26) is incorporated below. Slice 2 expand still waits
on an explicit operator G1 GO.

The document distinguishes **observed** Agency Discovery behavior in this
package from **proposed** Slice 2 transfer. Slice 2 ledger rows below
cite that `be5a1962` ledger, including tokens/locks, services, routes,
queues and test classes. Draft ontology names are in
`ontology/ontology.yml`; they are not an implementation contract until
G1. Host-tree class names that the ledger inventories but this checkout
still cannot open remain **unverified** for expand.

No tenant name, policy period, operating record or credential belongs in
this package. Host identity, File Gate, Field Guard and Audit Chain
remain independent dependencies. Enabling `agency_staff` still creates no
workforce relationship, account, role or invitation, and sends no
message.

## G1 decisions

Check each item to approve G1. A checked item is an operator decision,
not evidence that expand has started. This list is the gate; §10 restates
the same questions with context.

Already recorded (design incorporates; do not re-decide unless reversing):

- 2026-10-26 [DEV-992](https://wilkesliberty.atlassian.net/browse/DEV-992)
  Option A: ID-preserving copy into tenant-neutral `agency_*` types plus
  a lineage map, with the five conditions in §7.
- 2026-10-22 operator direction on
  [DEV-987](https://wilkesliberty.atlassian.net/browse/DEV-987): career
  postings **and** the résumé/application workflow move to Agency. That
  closes the former §10.4 split (host editorial / Agency rules only).

Approve all of the following for G1 GO:

1. [ ] This document and ontology draft v0.1.1 are the Slice 2 design.
   Expand, entity registration and host copiers stay blocked until GO.
2. [ ] Confirm DEV-992 Option A for every S2 source in §6: copy into the
   named `agency_*` type with the same integer id, UUID, revision ids,
   timestamps and authorship; refuse collisions; do not move host-named
   providers into this package.
3. [ ] Authority register: keep one `workforce` row for staff invite,
   staff onboard, `chronicle_personnel` and profile evidence, and a
   separate `recruiting` row for career postings, applications and
   résumé File Gate references (recommended, so posting close and staff
   cutover can move independently). Posting and application types are
   owned by proposed `agency_recruiting`; staff types stay on proposed
   `agency_workforce`. Alternative: one shared `workforce` row. Machine
   names stay `legacy` / `shadow` / `agency` / `removed`.
4. [ ] Classification allowlist stays `employee` and `contractor` only.
   Scaffolding already refuses Discovery, client, partner and supplier
   kinds.
5. [ ] Recruiting placement is Slice 2 expand (this ticket). Agency owns
   posting records, application/résumé workflow and recruiting rules.
   The host keeps public rendering transport (`/careers` and
   `/careers/{slug}` projections, JSON-LD). See §6 and §8.
6. [ ] Client membership and eligibility policy remain a later slice
   unless moved now. Dormant `chronicle_client_access` stays disabled.
7. [ ] Own-profile writes during overlap: host Profile remains the
   writer until `agency`, with Agency shadow writes (recommended),
   versus an earlier Agency-authoritative evidence path.
8. [ ] Public refusal strings for wrong-account and closed staff
   invitations: preserve host strings via the host adapter, or adopt
   Discovery `wrong_account` / `invitation_closed` / `already_used` /
   `not_found`.
9. [ ] `resend` is a follow-up, not Slice 2 expand. Scaffolding has no
   resend input.
10. [ ] Workforce relationship lifecycle is `invited`, `active`,
    `revoked`, `suspended`, `ended`. Those states are not Discovery
    states. Application and posting use the recruiting kind and the
    Application / CareerPosting objects.
11. [ ] Career posting close dates: extend `valid_through` past
    2026-10-31 until migration, or let the 13 postings close and keep
    the general (no-role) application. That is an editorial host edit,
    not a package change.
12. [ ] Posting editorial body stays host-rendered through a host
    adapter while Agency owns the posting record and rules
    (recommended), versus Agency-rendered body in this slice.
13. [ ] File Gate, Field Guard and Audit Chain carry over unchanged as
    specified in §8. Those engines are not reimplemented.
14. [ ] Network and coordinator profile audiences stay out of this
    expand. Slice 2 audiences remain `own` and `account_summary`.

G-ONT (ontology v0, [DEV-1000](https://wilkesliberty.atlassian.net/browse/DEV-1000))
is a separate operator gate. Approving G1 on this design does not close
G-ONT.

## 1. Responsibilities in scope

Slice 2 rows from `docs/agency-extraction/ledger.json` at webcms `master`
`be5a1962`. The 2026-10-29 sweep of that pin reported **580** Slice 2
responsibilities still `legacy`, **677** leftover host callers and
**27** test classes to port, across `chronicle_staff_invite`,
`chronicle_personnel`, dormant `chronicle_client_access`, the person
side of `chronicle_api`, the `career` node bundle, and the
`career_application` / `staff_onboard` webforms. **0** rows were
`shadow`, `agency` or `removed`. Row ids S2-L1–L5 are this design's
labels, not ontology machine names.

Ledger categories at that pin (host PHP remains **unverified** from this
checkout; expand re-reads the ledger rather than this table):

| Category | Cited at `be5a1962` | Slice 2 disposition |
| --- | --- | --- |
| Tokens / locks | Staff-invite bearer hashes and generation row locks; `/invite` inspect door; career-upload claim tokens | Copy staff-invite hashes onto `agency_staff_invitation` (plaintext never copied). Career-upload tokens are File Gate carry-over. |
| Services | `chronicle_staff_invite`, `chronicle_personnel`, person/profile projections on `chronicle_api`, `CareerResumeUploadService`, career validators | Copy/adapt into `agency_workforce` and `agency_recruiting` after G1. Scan job store stays File Gate. |
| Routes | `/invite`, own-profile, account-summary, `/careers`, `/careers/{slug}`, `/careers/apply` | Host transport retained. Agency owns the records after `agency` authority. |
| Queues | Staff-invite delivery; résumé quarantine/scan jobs | Invite capture is Agency after cutover. Scan jobs stay File Gate. |
| Test classes | 27 S2 classes in the ledger | Port map in §11. |

| Proposed row | Current authority (ledger) | Target | Notes |
| --- | --- | --- | --- |
| S2-L1 Staff invitation and onboarding | Host `chronicle_staff_invite` | Agency Staff | Ledger: staff onboard Webform. Issuer-minted single-use token; the invitee does not choose classification. After Agency commits acceptance, the host adapter mints uid, fail-closed role and matching Profile. Keycloak remains the IdP; not a password signup. |
| S2-L2 Invitation protocol and `/invite` door | Host `chronicle_client_access` (not enabled) plus host `Query.wlInviteStatus` | Agency Staff, adapted | Ledger: Keycloak invitation protocol adapts into Agency Staff (issuer/subject, POST-only accept, per-read entitlement). Do not enable the dormant module. Host inspect door is `wlInviteStatus` on `/invite`; GET never consumes. |
| S2-L3 Personnel and profile evidence | Host `chronicle_personnel` plus profile configuration | Agency workforce | Ledger person/account/profile split: Profile holds marked evidence, not identity. Private files stay under File Gate. |
| S2-L4 Person / account / profile projection | Host `chronicle_api` person, account and profile projections | Agency projection; host keeps transport | Ledger S2 split: person (IdP), account (host uid/role), profile (evidence projection). Own-profile and account-summary contracts stay unchanged. |
| S2-L5 Recruiting postings and application/résumé | Host `career` node, `career_application` webform, résumé private files | Agency recruiting | **Resolved 2026-10-22.** Postings and the résumé/application workflow both move to Agency. Host keeps public rendering transport. File Gate, Field Guard and Audit Chain carry over (§8). |

Out of Slice 2: Discovery relationships (already Agency), CRM, intake
other than recruiting, engagement/time, client membership policy
(placement reviewed in the Slice 2 threat model; target remains a later
slice unless the operator moves it), marketing, credentials as a
standalone capability, roster availability and expenses. Client profile
bundles stay with later commercial work.

Observed: `agency_staff` already owns invitation-only Discovery.
Discovery commands do not create a user, role, Profile, employment
classification, roster membership or assignment. Slice 2 must preserve
that wall.

## 2. Target object types and actions

Proposed ontology subset. Draft v0.1.1 machine names are in
`ontology/ontology.yml`. They remain draft: G1 is still required before
expand uses them as an implementation contract. Existing Discovery
storage names are observed and must not be reused as staff provisioning
or recruiting paths.

| Proposed object | Observed related storage | Slice 2 role |
| --- | --- | --- |
| Person | Internal `agency_person` is a thin Discovery resolver (display name and contact) | Canonical person in the ontology. Discovery, workforce and recruiting relationships may link to the same person; that link is not an entitlement. Ledger S2 split: person is not the Drupal account and not the Profile. |
| Principal binding | Internal `agency_principal` | Exact issuer/subject from the Keycloak invitation protocol. Contact never selects a principal. Reuse the host `IdentityProviderInterface`. |
| Organization / Membership | none in this package | Workforce membership is distinct from Discovery participation and from client membership. Draft ontology proposes `agency_organization` and `agency_membership` for S2; expand still waits on G1. |
| Relationship (workforce) | Internal `agency_discovery` | New workforce relationship type (`agency_workforce`). Never the Discovery entity. |
| Relationship (recruiting) | none | Proposed kind `recruiting` (`agency_recruiting`). Not a Discovery or workforce state. Application and posting records may link to it. |
| Invitation (staff) | Internal `agency_invitation` (Discovery generations) | New staff invitation type `agency_staff_invitation`. Never share the Discovery generation table or command class. |
| Entitlement (workforce) | Internal `agency_entitlement` (Discovery grants) | New workforce entitlement. Never share the Discovery grant table or command class. |
| Profile evidence | none | `agency_profile_evidence` under proposed `agency_workforce`. Marked fields plus File Gate references. Not a public Profile entity. Copy sources are profile bundles `employee` and `contractor` and `chronicle_personnel` evidence. Client profile bundles are host-stay with fail-closed collision preflight. |
| Staff onboard | none | `agency_staff_onboard`. ID-preserving copy of the `staff_onboard` webform submission. Classification is issuer-supplied. |
| Career posting | none | `agency_career_posting` under proposed `agency_recruiting`. ID-preserving copy of the `career` node. Host adapter renders the public list and detail. |
| Application | none | `agency_application` under proposed `agency_recruiting`. ID-preserving copy of the `career_application` webform. Résumé bytes stay in File Gate; the row stores the File Gate UUID. Distinct from Slice 3 `agency_intake_submission`. |

Proposed actions (input schemas in scaffolding or ontology; no service
yet):

| Action | Actor | Effect |
| --- | --- | --- |
| `inviteStaff` | Eligible coordinator | Creates a workforce relationship in an invited state, pending workforce entitlement and a captured staff invitation. Classification is an input from the issuer. No uid, role, Profile or message send. |
| `acceptStaffInvitation` | Authenticated intended principal, POST only | Consumes one generation under a row lock, activates workforce entitlement, then asks the host adapter to mint the host account/role/profile bundle. Wrong account, reused token and GET are refusals. |
| `revokeStaffInvitation` | Assigned coordinator | Revokes generations and workforce entitlement. Stale expected version still applies, matching Discovery denial. Does not reopen Discovery state. |
| `updateProfileEvidence` | Authorized coordinator or the entitled person for own-profile fields | Writes own-profile evidence. Field names must match the server marking map; caller-chosen markings that disagree are refused. Account-summary is a read projection, not a write audience. File bytes are not accepted; only File Gate references. |
| `createCareerPosting` / `publishCareerPosting` / `closeCareerPosting` | Eligible coordinator | Writes posting records. Publish still refuses an open role with no close date or a past one. |
| `submitApplication` | Applicant through the host form door | Records an application against a posting or the general (no-role) door. Stores File Gate résumé references only. Does not mint a principal, uid or workforce classification. |

Proposed reads (host transport, Agency projection):

- Inspect a staff invitation token: status only, matching the host
  `wlInviteStatus` `/invite` door. GET never consumes or binds.
- Own-profile projection for the current principal.
- Account-summary projection for the current principal.
- Coordinator read of one workforce relationship.
- Public career list and detail: host-rendered from Agency posting
  records after `agency` authority.

Lifecycle states proposed for the workforce relationship: `invited`,
`active`, `revoked`, `suspended`, `ended`. Application states are on
`agency_application` (`received`, `withdrawn`, `accepted`, `declined`).
Posting states are on `agency_career_posting` (`draft`, `published`,
`closed`). Those labels are not Discovery relationship states.

## 3. Separation rule

Staff and contractor provisioning and Discovery entitlements never share
a code path and never grant each other's privileges. Recruiting
application does not grant workforce classification or Discovery
authority.

Observed Discovery wall (`docs/DISCOVERY_CONTRACT.md`): no Discovery
command creates employment classification, roster membership or
assignment; CRM, staff, client, partner, pending and machine credentials
do not acquire Discovery authority through role names.

Slice 2 adds the converse:

- `inviteStaff` / `acceptStaffInvitation` / `revokeStaffInvitation` live
  under `Drupal\agency_staff\Workforce\` (proposed `agency_workforce`
  submodule) and must not call
  `RelationshipCommands`, `InvitationCommands`, `RelationshipReads` or
  write `agency_discovery` / `agency_invitation` / `agency_entitlement`.
- `createCareerPosting` / `publishCareerPosting` / `closeCareerPosting` /
  `submitApplication` live under `Drupal\agency_recruiting\` and must not
  call Workforce or Discovery command classes.
- Discovery commands must not call workforce or recruiting interfaces or
  write those types.
- A workforce entitlement never authorizes a Discovery workspace,
  summary or queue row.
- A Discovery entitlement never authorizes staff invitation, host role
  provisioning or personnel evidence.
- An application row never authorizes staff invitation, host role
  provisioning or a Discovery workspace.
- Classification values `discovery`, `client`, `partner` and `supplier`
  are refused on staff invite input.
- Shared Person or Principal rows, when introduced, are links only.
  Authorization is recomputed from the relationship-scoped entitlement
  on every read.

The existing Discovery mutex and outbox remain Discovery's. Workforce
and recruiting expand need their own transaction boundaries so a staff
or application write cannot take the Discovery fence, and a Discovery
write cannot take a workforce or recruiting fence. That storage work is
expand, not this scaffolding.

## 4. Client-access invitation protocol

The dormant host client-access module is not a dependency and must not
be enabled by this transfer. Slice 2 adapts the protocol into Agency
Staff.

Observed Discovery ceremony that Slice 2 reuses as rules, not as
classes:

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
  refuses Discovery/client kinds unless G1 adds further kinds.
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
  (operator decision G1 item 6).

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
G1 reviews the network profile contract (item 14: out of this expand).

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

## 6. Source map and data transfer

Lineage, copy and reconciliation tooling belong to `agency_migrate`
(package 05). Slice 2 specifies what that tool must preserve. Copier
plugin ids below are the host-adapter names expand will register; this
package does not ship those plugins.

| Source | Disposition | Target `agency_*` | Copier plugin | Field Guard marking | Authority domain |
| --- | --- | --- | --- | --- | --- |
| Profile bundle `employee` | copy | `agency_profile_evidence` | `profile_employee` | `personal` on evidence fields; `internal` on classification and lineage | workforce |
| Profile bundle `contractor` | copy | `agency_profile_evidence` | `profile_contractor` | `personal` on evidence fields; `internal` on classification and lineage | workforce |
| `chronicle_personnel` person rows | copy | `agency_person` | `chronicle_personnel` | `personal` on display/contact; `internal` on lineage | workforce |
| `chronicle_personnel` evidence fields | copy | `agency_profile_evidence` | `chronicle_personnel_evidence` | `personal` on evidence; `internal` on classification | workforce |
| Profile bundle `client` | host-stay | none | none | n/a | n/a; collision preflight `fail_closed` and must not skip these rows |
| Staff invite state (`chronicle_staff_invite`) | copy | `agency_staff_invitation` | `staff_invite_state` | `personal` on contact routes; `confidential` on token hash | workforce |
| `staff_onboard` webform | copy | `agency_staff_onboard` | `staff_onboard` | `personal` on payload; `internal` on issuer classification | workforce |
| `career` node | copy | `agency_career_posting` | `career_node` | `public` on title, hiring status, valid-through and body; `internal` on eligibility/clearance/engagement flags | recruiting |
| `career_application` webform | copy | `agency_application` | `career_application` | `personal` on applicant payload and geo; `internal` on posting binding and status | recruiting |
| Résumé private files | copy (UUID only) | File Gate UUID on `agency_application` (`resume_file_uuid`) | `career_resume_file` | `personal` (or `confidential` when the field map says so) | recruiting |
| Career-upload tokens | File Gate carry-over | none | none | n/a | recruiting files stay on File Gate |
| `CareerResumeUploadService` job store | File Gate carry-over | none | none | n/a | quarantine/scan jobs stay on File Gate |
| Claim-scoped résumé access | File Gate carry-over | none | none | n/a | File Gate remains the delivery authority |
| Geo handlers (`CountryResolver`, `UsGeoRestrictWebformHandler`) | host-stay | none | none | n/a | Host form mechanics. Applicant geo payload copies with `career_application`. |

The same map is machine-readable as `s2_transfer_map` in
`ontology/ontology.yml` and is schema-checked. Ontology contract tests
fail when a required source, 10/22 pipeline row, or client-profile
exclusion is missing.

Host uid, role and the Drupal account remain host-owned adapter
bindings, not Agency user entities. Person/profile API consumers stay
host transport over an Agency projection. Those are not copy sources.

Rules:

- Agency defines `agency_*` types. Migration copies rows with the same
  numeric id, UUID, revision ids, timestamps and revision authorship, and
  writes a lineage map. Moving a host-named entity provider into this
  package is rejected.
- Token plaintext is never copied; re-hash if a hash already exists.
- File bytes are never copied. Résumé quarantine, scan and claim-scoped
  access stay on File Gate. No public or derivative URLs are created.
- Historical Audit Chain events that name a legacy type stay untouched.
  One minimized lineage event per migrated batch links legacy references
  to Agency references; the verifier may resolve either.
- Markings travel with data. An unmapped property stops migration.
- Synthetic fixtures only. No live personnel, receipts, résumés or
  credentials in this repository.
- Slice 3 `agency_intake_submission` is business intake. It does not
  own `career_application`.

Exact table, field, route, service, queue and test inventory is
`docs/agency-extraction/ledger.json` at webcms `master` `be5a1962`. Host
PHP class files remain **unverified** from this checkout; expand reads
the ledger rather than re-inventorying. Host copier plugins and
`agency_migrate` enablement are webcms work after G1.

## 7. DEV-992 Option A conditions per S2 entity

[DEV-992](https://wilkesliberty.atlassian.net/browse/DEV-992) Option A
(approved 2026-10-26) applies to every **copy** row in §6. Host-stay and
File Gate carry-over rows are not copiers. Preserving numbers alone does
not preserve references. Each copier must satisfy all five conditions
before that source may leave `legacy`. Client profile bundles
(`profile_client`) are host-stay: collision preflight is `fail_closed`
and must not skip those rows.

Workforce copy copiers:

| Condition | `profile_employee` / `profile_contractor` | `chronicle_personnel` | `chronicle_personnel_evidence` | `staff_invite_state` | `staff_onboard` |
| --- | --- | --- | --- | --- | --- |
| Collision-free preflight | Fail closed if `agency_profile_evidence` already holds the source integer id, UUID or revision id for a different UUID. Do not renumber. | Fail closed if `agency_person` already holds that integer id or UUID for a different UUID. Do not merge into a Discovery resolver by guessing. | Fail closed on `agency_profile_evidence` id/UUID/revision collisions from personnel evidence rows. | Same on `agency_staff_invitation`. Pending token hashes compared by hash, never plaintext. | Same on `agency_staff_onboard` webform submission ids/UUIDs. |
| Reference retargeting | Retarget profile → user/person and file-field references. Serialized profile ids in own-profile and account-summary routes become Agency type + same number. | Retarget personnel → person UUID, membership, and profile evidence. Own-profile routes resolve the Agency person. Serialized class names in queues/state are listed and remapped. | Retarget evidence → person and File Gate refs. Unmapped personnel fields fail the batch. | Retarget generation → relationship/person/principal. `/invite` and `wlInviteStatus` keep host routes; inspect reads Agency after `agency`. | Retarget submission → invitation and classification. Staff onboard confirmation routes resolve through lineage. |
| History and access reconciliation | Count parity for employee vs contractor bundles; per-field digests for marked evidence; own-profile and account-summary allow/deny parity; revisions and authorship match. Historical Audit Chain rows untouched. | Count parity for personnel rows vs `agency_person`; access-decision parity (personnel read is not a Discovery workspace). | Per-field digests for marked personnel evidence; evidence → person integrity. | Count parity for generations; token ceremony parity (GET inspect does not consume; POST accept consumes once); access-decision parity for wrong subject. | Count parity for onboard submissions; classification digest; invitation → onboard integrity. |
| Resumable, idempotent copy | Resume from last committed source id. Second complete run is a no-op. Retry must not duplicate or overwrite a divergent Agency row. | Same. A later Discovery resolver row with a different UUID is a collision, not an overwrite. | Same. | Same. Consumed/revoked status is not reset by resume. | Same. |
| Authority cutover and rollback | Domain `workforce`. Shadow writes after legacy profile commits. Rollback replays Agency evidence onto the profile bundle through lineage. Host uid already minted is not deleted. | Domain `workforce`. Shadow after legacy personnel commits. Rollback replays onto personnel tables through lineage. Discovery person rows that existed before copy stay. | Domain `workforce`. Tied to personnel cutover. | Domain `workforce`. Shadow after legacy invite commit. Rollback restores legacy generation as reader; a sent message is not unsent. | Domain `workforce`. Tied to invitation cutover. Rollback replays onboard answers onto the webform submission. |

Recruiting copy copiers:

| Condition | `career_node` | `career_application` | `career_resume_file` |
| --- | --- | --- | --- |
| Collision-free preflight | Fail closed on `agency_career_posting` (node nid/uuid/vid). | Fail closed on `agency_application` (webform submission id/UUID). | Same File Gate UUID must not bind to a different application row. Occupied destination file UUID with a different source is a refusal. |
| Reference retargeting | Retarget node → application, menu, path alias `/careers/{slug}`, JSON-LD JobPosting id, and ui career queries. Host renderer keeps the public path. | Retarget submission → posting (including null/general door), person, and résumé File Gate UUID. `/careers/apply` stays a host door. Geo handler classes stay host; geo payload is a field on the application. | Retarget file UUID and access flags. Quarantine, scan jobs, upload tokens and claim-scoped access stay File Gate ids. No new public URL. |
| History and access reconciliation | Count parity for postings; valid-through and hiring-status digests; public list membership vs closed roles. | Count parity for applications; payload digests without logging PII; posting and person integrity; access-decision parity (applicant cannot read another applicant). | Count parity for résumé File Gate refs; access-decision parity (claim-scoped read only); no public URL appears on either side. |
| Resumable, idempotent copy | Resume from last committed source id. Editorial edits during a run are a reconciliation exception, not a silent overwrite. | In-flight submissions after the watermark wait for the next batch. | Scan-state changes during the run are exceptions. Bytes are never copied, so resume cannot create a second file body. |
| Authority cutover and rollback | Domain `recruiting`. Shadow after legacy node commit. Rollback replays Agency posting fields onto the `career` node. Public renderer flips with the flag. | Domain `recruiting`. Shadow after legacy submit. Rollback replays onto the webform submission. Applicant-visible status follows the flag. | Domain `recruiting`. File Gate remains the file authority in every state. Rollback unlinks the Agency UUID binding; it does not delete the private file. |

Shared Option A rules for every copier:

- Preflight runs read-only and fails the batch before any write.
- Historical Audit Chain events are never re-chained. One minimized
  lineage event per batch lets a verifier resolve either reference.
- Unmapped markings fail the run before any write.
- Rollback is replay through the lineage map, not a database restore.
  Rehearse on a staging clone before production cutover.
- `removed` has no rollback edge.

## 8. File Gate, Field Guard and Audit Chain carry-over

These engines stay independent. Slice 2 consumes their contracts.

**File Gate**

- Résumés remain private files. Quarantine, scan and claim-scoped
  access are preserved. Agents may read scan status only.
- Profile and application evidence store File Gate UUIDs and access
  flags. Value objects refuse bytes, data URIs and remote file bodies.
- No public or derivative résumé URLs. Delivery authorization stays
  with File Gate.
- Identity deactivation and rebinding continue to deny staff and
  applicant reads.

**Field Guard**

- Every migrated property has one marking (`public`, `internal`,
  `confidential`, `personal`, `financial`). An unmapped property stops
  the run.
- `personal` fields use Field Guard at coordinator write boundaries.
- Projection allowlists are the intersection of audience, marking,
  entitlement and Field Guard.
- Applicant payload, geo and résumé references are `personal` until a
  reviewed field map says otherwise.

**Audit Chain**

- Historical submission, scan, invitation and personnel events are
  never rewritten.
- One minimized lineage event per batch is recorded through Agency's
  outbox and drained with `logKeyed()`.
- Pre-cutover verification still passes after cutover.
- Interview, decision and credential events belong in that minimized
  design; they do not carry contacts, notes, tokens, credentials or
  file bytes.
- Applicant data is not copied into issue trackers, logs or agent
  tools.

## 9. Authority, shadow-write, reconciliation and rollback

Authority states match the `agency_migrate` register. Whether workforce
and recruiting are one row or two is G1 item 3. English labels such as
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
5. Career posting create/publish/close after the legacy `career` node
   commit.
6. `submitApplication` after the legacy `career_application` submission
   and File Gate claim commit.

Reconciliation checks specific to Slice 2 (clean report required before
cutover):

- Count parity: invitations, onboard submissions, workforce
  relationships, persons, evidence fields, postings, applications, File
  Gate references.
- Per-field digests for marked personnel and applicant properties
  (digests only; no PII in reports).
- Reference integrity: invitation → relationship → person → principal;
  onboard → invitation; evidence → person; application → posting
  (optional) and person; file ref → File Gate id.
- Access-decision parity: a principal entitled only for staff cannot
  read Discovery; a Discovery participant cannot accept a staff
  invitation issued to a different subject; an applicant cannot read
  another application; own-profile and account-summary allowlists match
  the frozen host fixtures.
- Token ceremony parity: GET inspect does not consume; POST accept
  consumes once; wrong issuer/subject is `wrong_account` or equivalent
  without leaking whether the token existed beyond existing host
  behavior. Public reason strings stay an operator decision (G1 item 8);
  host inspect uses `wlInviteStatus` and must not be treated as a
  consume or bind.
- Posting ceremony parity: an open role with no close date or a past
  close date cannot publish; closed roles refuse role-bound submit;
  the general application door stays available.
- No unexplained drift over the rehearsal window.

Rollback:

- Flip the affected domain flag to `legacy`.
- Replay Agency writes to legacy through the lineage map. Do not restore
  a database backup as the rollback procedure.
- Limits: a message the host already sent is not unsent; capture versus
  send stay distinct. A host uid already minted is not deleted by
  rollback; the host adapter records that the Agency relationship is no
  longer authoritative and legacy remains the reader. Historical
  evidence is never re-chained. A private résumé is not deleted.
- Rehearse on a staging clone before any production cutover. Production
  cutover is not authorized by this document.

## 10. Threat-model inputs

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
| S2-T10 | Inline file bytes in profile or application evidence | File Gate | Value objects refuse bytes, data URIs and remote file bodies; File Gate UUID only |
| S2-T11 | Client membership policy smuggled through staff accept | Placement | Out of Slice 2 expand until the operator places client membership |
| S2-T12 | Shadow-state Agency records used for authorization | Authority flag | Host adapter is the only reader of the flag; Agency reads stay off until `agency` |
| S2-T13 | Application grants workforce classification or Discovery access | Recruiting | Application is not an invite and not a classification; denial tests |
| S2-T14 | Public career page exposes applicant or résumé data | Projection | Public posting projection is allowlisted; résumé and payload are `personal` and never public |

Residuals for 08: host message delivery, identity-provider outage during
accept, résumé scan outage during submit, and mixed coordinator
credentials (already refused by Discovery eligibility; staff expand must
keep that adapter).

## 11. Test-parity list

Test classes per module are inventoried in `ledger.json` at webcms `master`
[`be5a1962`](https://github.com/Wilkes-Liberty/webcms/commit/be5a1962)
(27 Slice 2 classes; category counts in §1). The class-level port map is
the assurance kit (package 08, webcms#1897). Removal of a legacy module
is blocked by any unmapped test without a reviewed reason. Names below
are ledger module scopes and behavioral cases; this checkout still cannot
open the host PHP files.

| Legacy scope | Behavioral cases to port | Agency or host-adapter destination |
| --- | --- | --- |
| `chronicle_staff_invite` | Staff onboard Webform: token works once; invitee cannot choose type; fail-closed role; matching profile bundle; not a password signup; private files remain File Gate-protected; languages stay in the host catalog | Workforce actions + host adapter tests |
| `chronicle_personnel` | Personnel field access; evidence update; unmarked field refused; revision preservation; Profile is not the person or the account | `updateProfileEvidence` + migrate reconciliation |
| `chronicle_client_access` | Keycloak invitation protocol tests only: issuer/subject, POST-only accept, per-read entitlement. Module enablement tests must not start passing because of this slice | Workforce invitation ceremony; no host module enable |
| Host `wlInviteStatus` / `/invite` | GET inspect does not consume; wrong subject shares the closed/not-found shape the host already exposes | Workforce inspect read + host transport |
| `chronicle_api` person/profile and account summary | Person/account/profile split; own-profile and account-summary fixtures stay green; allowlisted audiences; denied cross-person | Workforce reads + host transport |
| Host identity / private files | Identity deactivation and rebinding deny staff reads; File Gate UUID still authorizes delivery | Existing identity adapter + File Gate, not copied |
| `career` node / publication constraint | Open role without a close date or with a past date cannot publish; closed role drops from the public list and refuses role-bound submit | Career posting actions + host renderer |
| `career_application` / résumé pipeline | Role binding; general (no-role) door; File Gate-only résumé; scan/claim scope; cross-applicant denial; no public URL | Application action + File Gate + host form door |

Discovery tests in this package remain the Slice 1 suite. Slice 2
scaffolding must not change their outcomes. Ontology contract tests
must keep passing, including the recruiting kind and the S2 source map.

## 12. Open operator decisions

The numbered G1 checklist at the top is authoritative. This section
keeps the narrative form.

1. Approve `agency_*` workforce and recruiting types with ID-preserving
   copy and lineage, rather than moving host-named providers into this
   package. DEV-992 Option A already chose the mechanism.
2. Approve that staff invite, onboard and profile evidence share one
   `workforce` authority row, and that postings, applications and
   résumé references use a separate `recruiting` row (or one shared
   row). Machine names are already shipped by agency#27; do not fork
   them.
3. Workforce classification allowlist: keep `employee` and `contractor`
   only in the package, matching the ledger's staff onboard Webform, or
   add further kinds by operator decision. Scaffolding currently
   allowlists those two and refuses Discovery/client kinds.
4. **Resolved 2026-10-22.** Career postings and the résumé/application
   workflow both move to Agency in Slice 2. Remaining choices are G1
   items 5, 11 and 12 (placement confirmation, close-date extension,
   host-rendered body).
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
`modules/agency_staff/src/Workforce/` for staff invitation and profile
evidence (proposed `agency_workforce` submodule after G1):

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

Posting and application types are owned by proposed `agency_recruiting`
(`Drupal\agency_recruiting\`). That namespace is not registered in this
change. Do not hang those types on `agency_workforce`.

Tests instantiate workforce value objects directly. There is no service
registration, no schema change and no Discovery behavior change.
Expand implements the interfaces after G1. This G1-ready update does
not register Slice 2 record types.
