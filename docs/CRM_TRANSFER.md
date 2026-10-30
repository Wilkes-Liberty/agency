# CRM transfer (Slice 3)

Status: **proposed design**. This is not an implementation contract, not a
Slice 3 G-gate, and not operator approval. Package 01 published the caller
ledger as draft [webcms#1900](https://github.com/Wilkes-Liberty/webcms/pull/1900)
(`docs/agency-extraction/ledger.json` at
`2216df30c3af736bd7f8b47832c2ea8572c1fd2b`). The citations below use the
live host ledger on webcms `master` at
[`be5a1962`](https://github.com/Wilkes-Liberty/webcms/commit/be5a1962)
(2026-10-29 operator sweep: `scripts/agency-ledger/generate.py --check`
passed). Ontology draft v0.1.0 landed in
[agency#25](https://github.com/Wilkes-Liberty/agency/pull/25). DEV-992
Option A (ID-preserving copy plus lineage) is recorded as decided; it
does not approve this slice. Slice 3 expand still waits on a later
operator G1 after Slice 2.

The document distinguishes **observed** Agency Discovery and host-adapter
behavior in this package from **proposed** Slice 3 transfer. Slice 3
ledger rows below cite that `be5a1962` ledger. Row ids S3-L1–L6 are this
design's labels, not ontology machine names. Draft ontology names are in
`ontology/ontology.yml`; they are not an implementation contract until a
Slice 3 G1. Host-tree class names that the ledger inventories but this
checkout still cannot open remain **unverified** for expand.

No tenant name, policy period, operating record or credential belongs in
this package. Host identity, File Gate, Field Guard and Audit Chain remain
independent dependencies. Enabling `agency_staff` still creates no
relationship, account, role or invitation, and sends no message. This
change adds no entity type, service, schema or copier.

## 1. Responsibilities in scope

Slice 3 rows from `docs/agency-extraction/ledger.json` at webcms `master`
`be5a1962`. The 2026-10-29 sweep of that pin reported 350 S3
responsibilities still `legacy`, 510 leftover host callers, and 29 test
classes to port, across `chronicle_crm`, `chronicle_forms` and
`chronicle_service_intake`. Slice 1 still lists `chronicle_agency` as
`record_only` (20 items, 8 leftover callers) because its contact policy
depends on `chronicle_crm`. Exact table, service, route and test rows
live in that ledger; this checkout cannot open the host PHP.

| Proposed row | Current authority (ledger) | Target | Notes |
| --- | --- | --- | --- |
| S3-L1 CRM contact | Host `chronicle_crm` entity `crm_contact` | Agency Person and Organization | Ledger stored type, not a User and not a Discovery grant. Ontology draft lists `crm_contact` as a legacy source on both `agency_person` and `agency_organization`. Split rule is §6 and §10.1. |
| S3-L2 CRM opportunity | Host `chronicle_crm` entity `crm_opportunity` | Agency Opportunity (`agency_opportunity`) | Proposed commercial record. Host originally shipped no public canonical route, JSON:API or GraphQL dump; commercial scalars still ride host `clientProfile` / `clientDeliverables` / `clientInvoices` transport ([webcms#1900](https://github.com/Wilkes-Liberty/webcms/pull/1900)). |
| S3-L3 CRM activity | Host `chronicle_crm` entity `crm_activity` | Agency Activity (`agency_activity`) | Proposed activity log linked to an opportunity. Ontology draft is not revisionable; host revision shape is **unverified** from this checkout (§10.2). |
| S3-L4 Business intake | Host `chronicle_forms` (business policy and outbox) plus `chronicle_service_intake` (routing, mapping, queue/retry) | Agency Intake (`agency_intake_submission` plus intake orchestration) | Generic form validation and provenance stay on the host. Tenant field mappings stay in the host overlay ([webcms#1623](https://github.com/Wilkes-Liberty/webcms/issues/1623)). Vendor JSM (`wl_service_intake.gateway`) stays host. |
| S3-L5 Intake webform submissions | Host Webform submissions used as business intake | Agency IntakeSubmission | Bytes and generic Webform mechanics stay host. Agency owns the business payload, dispatch key and accept/decline/withdrawn states. Recruiting `career_application` copies onto Slice 2 `agency_application`, not IntakeSubmission (§10.6). |
| S3-L6 Slice 1 contact-lookup port | Host `chronicle_agency` CRM contact policy (depends on `chronicle_crm`) | Existing `agency_host.contact_policy` port, later backed by Agency CRM | Observed Discovery already has a replaceable policy. S1 cannot reach authority `removed` while this leftover CRM dependency keeps `chronicle_agency` installed. See §4. |

Out of Slice 3: Discovery relationships (already Agency), workforce
provisioning (Slice 2), engagement/time and client membership (Slice 4),
marketing campaigns (Slice 5), payment rails, and the disabled `wl_*`
twins ([DEV-801](https://wilkesliberty.atlassian.net/browse/DEV-801) /
[webcms#1681](https://github.com/Wilkes-Liberty/webcms/issues/1681)).
`crm_client` / `wl_crm_client_profile` are host selection plugins, not
stored entity types (webcms#1900); they are not copy sources.

Observed: `agency_staff` already owns invitation-only Discovery.
Discovery commands do not create a CRM contact, opportunity, activity or
intake submission. `createOpportunity` in the ontology draft already
states that Discovery explorers never become commercial contacts through
that action. Slice 3 must keep that wall.

## 2. Target object types and actions

Proposed ontology subset. Draft v0.1.0 machine names are in
`ontology/ontology.yml` after agency#25. They remain draft: a Slice 3 G1
is still required before expand uses them as an implementation contract.
Existing Discovery storage names are observed and must not be reused as
commercial write paths.

| Proposed object | Observed related storage | Slice 3 role |
| --- | --- | --- |
| Person | Internal `agency_person` is a thin Discovery resolver (display name and contact) | Shared within a tenant. A copied CRM person-contact may link to the same person; that link is not an entitlement and is not a Discovery workspace. |
| Organization | none in this package | Proposed in Slice 2 for workforce; commercial reuse is the same type. A CRM organization-contact copies here, not into Person. |
| Opportunity | none | Proposed `agency_opportunity` under proposed `agency_commercial`. Lifecycle `open` → `won` / `lost`. |
| Activity | none | Proposed `agency_activity` under proposed `agency_commercial`. Lifecycle `recorded` (cancel is terminal). |
| IntakeSubmission | none | Proposed `agency_intake_submission` under proposed `agency_intake`. Lifecycle `received` → `accepted` / `declined` / `withdrawn`. The ontology draft leaves IntakeSubmission `legacy_sources` empty. Career applications copy onto `agency_application`. Business intake sources remain a proposed addition (§10.6). |

Proposed actions (ontology placeholders; no service in this change):

| Action | Actor | Effect |
| --- | --- | --- |
| `createOpportunity` | Eligible commercial coordinator | Opens an opportunity against an organization. Does not create a Discovery relationship or mint a contact from a Discovery explorer. |
| `closeOpportunity` | Eligible commercial coordinator | Terminal `won` or `lost`. Does not grant client membership, engagement or settlement. |
| `recordActivity` | Eligible commercial coordinator | Records a bounded activity against an opportunity. Summary is confidential plain text. |
| `submitIntake` | Entitled principal or host-adapted public submitter | Creates a received intake submission. Dispatch is idempotent on `dispatch_key`. Does not create an opportunity by itself. |
| Contact-policy read | Discovery identity adapter | `mayContact()` allow/deny only. Never writes CRM. Never selects a principal. |

Proposed reads (host transport, Agency projection after cutover):

- Coordinator read of one contact, opportunity or activity.
- Coordinator list filtered by organization and opportunity state.
- Intake dispatch status by `dispatch_key` (idempotent replay of the
  original receipt).
- Discovery `mayContact` against the CRM-backed policy (§4).

Commercial GraphQL/JSON:API dumps are not proposed. Host presentation
that already projects commercial scalars keeps that transport.

## 3. Separation rule

CRM writes and Discovery entitlements never share a code path and never
grant each other's privileges.

Observed Discovery wall (`docs/DISCOVERY_CONTRACT.md`): CRM, staff,
client, partner, pending and machine credentials do not acquire
Discovery authority through role names. Contact data never selects or
merges a principal.

Slice 3 adds the converse:

- `createOpportunity` / `closeOpportunity` / `recordActivity` /
  `submitIntake` live under a proposed commercial or intake boundary
  (`Drupal\agency_commercial\` / `Drupal\agency_intake\`, names still
  an ontology open question) and must not call `RelationshipCommands`,
  `InvitationCommands`, `RelationshipReads` or write `agency_discovery`
  / `agency_invitation` / `agency_entitlement`.
- Discovery commands must not call commercial or intake interfaces or
  write those types.
- A CRM role never authorizes a Discovery workspace, summary or queue
  row.
- A Discovery entitlement never authorizes CRM create/close, activity
  write or intake accept/decline.
- Looking up a contact for Discovery (`mayContact`, console find) does
  not create, merge or upgrade a CRM contact.
- A Discovery explorer never becomes a commercial contact as a side
  effect of invitation, accept or console lookup.
- Shared Person or Organization rows, when introduced, are links only.
  Authorization is recomputed from the relationship-scoped entitlement
  or commercial permission on every read.

The existing Discovery mutex and outbox remain Discovery's. Commercial
and intake expand need their own transaction boundaries so a CRM write
cannot take the Discovery fence.

## 4. Contact-lookup port (Slice 1 residue)

This is the Slice 1 leftover that keeps `chronicle_agency` from reaching
authority `removed`.

Observed in this package:

- `agency_host.contact_policy` is a replaceable port
  (`ContactPolicyInterface::mayContact()`).
- The default `EmailContactPolicy` permits a well-formed email and
  refuses every other kind. `AgencyIdentityProvider::mayContact()`
  additionally requires `kind=email` before delegating.
- Console lookup (`IdentitySelection::find()`) checks `mayContact()`
  first, then calls the IdP directory (`IdentityLookupInterface::findEmail()`).
  Those are different ports. IdP exact-contact lookup is not CRM.
- Invitation issue and relationship start also call `mayContact()`.
- Delivery audit is a separate replaceable port
  (`agency_host.delivery_audit`) with a database default. It is not
  this contact-lookup port.

Observed on the host (ledger `be5a1962`, unverified class files from
this checkout):

- `chronicle_agency` remains a W&L adapter after
  [webcms#1899](https://github.com/Wilkes-Liberty/webcms/pull/1899)
  enabled `agency_host`. The 2026-10-29 sweep still records it as
  `record_only` (20 items) with 8 leftover callers.
- The remaining adapter still depends on `chronicle_crm` for contact
  policy. That is why Slice 1 Discovery authority cannot drain to
  `removed` until Slice 3 ports the policy.
- Three empty update hooks and a delivery-audit bridge are also listed
  on that remnant. The audit bridge is out of Slice 3 unless expand
  proves it still calls `chronicle_crm` (§10.5).

Proposed port, so `chronicle_agency` can later be uninstalled:

1. Keep `ContactPolicyInterface` as the only Discovery entry. Do not
   add a second CRM lookup inside `agency_staff`.
2. While CRM authority is `legacy` or `shadow`, a host replacement may
   still resolve `mayContact()` against `chronicle_crm`. Agency CRM
   rows are not used for Discovery authorization in those states.
3. When CRM authority becomes `agency`, the replacement reads Agency
   Person/Organization contact routes (or a host adapter over those
   types). It must not load `chronicle_crm` or `chronicle_agency`.
4. `mayContact()` stays allow/deny. It does not return CRM entities,
   tax identifiers, opportunities or activities. Ambiguous or multiple
   matches fail closed, matching today's console "none" / refused
   shape rather than leaking existence beyond current host behavior.
5. Contact data still never selects a principal. A CRM hit does not
   bind issuer/subject.
6. Slice 1 may move to `removed` only after: this policy no longer
   lives in `chronicle_agency`; leftover S1 callers are gone; the
   delivery-audit bridge does not require that module; and the empty
   update hooks are retired or proven no-op.

This port is a service replacement, not an `agency_migrate` copier.
Copying `crm_contact` does not by itself remove the Discovery
dependency.

## 5. Intake policy and host form mechanics

Ledger S3 split: Agency owns business intake policy, mapping,
orchestration and dispatch. The host keeps generic Webform mechanics,
validation widgets, provenance, authored locale, mail-subject strings
and staff-notification language (webcms#1900 closures).

| Concern | Writer after cutover | Stays host |
| --- | --- | --- |
| Business payload and markings | Agency IntakeSubmission | Generic form schema and widgets |
| Dispatch key and outbox | Agency intake orchestration | Transport adapters |
| Accept / decline / withdraw | Agency named actions | Editorial confirmation copy |
| Tenant field map | Host overlay configuration | The map values themselves |
| Vendor JSM gateway | Host `wl_service_intake.gateway` | Agency keeps a vendor-neutral gateway interface only |
| Recruiting application | Slice 2 `agency_application` | Career editorial content stays host |

Rules:

- Dispatch stays idempotent on `dispatch_key`. A cutover or rollback
  must not send a second external request for the same key. Uncertain
  delivery is recorded and retried as the same intent.
- Generic noncommercial forms keep working when CRM/intake service IDs
  are remapped in the transfer table. An unmapped dispatcher ID fails
  closed.
- Intake accept does not create an opportunity, membership or
  Discovery relationship. A later coordinator action may.
- File bytes stay in File Gate when an intake field is a private
  document. Agency stores the File Gate UUID and marking only.

## 6. Data transfer

Lineage, copy and reconciliation tooling belong to `agency_migrate`
([Migration](MIGRATION.md)). Slice 3 specifies what that tool must
preserve. DEV-992 Option A is decided: copy into tenant-neutral
`agency_*` types with the same integer id, UUID, revision ids,
timestamps and revision authorship, plus a lineage map. The five
approval conditions apply per type below.

| Legacy store (plan) | Proposed Agency type | Copier plugin (proposed name) | Marking default until field map exists |
| --- | --- | --- | --- |
| `crm_contact` person-shaped rows | Person (`agency_person`) | `crm_contact_person` | `personal` for contact routes; unmapped field fails the batch |
| `crm_contact` organization-shaped rows | Organization (`agency_organization`) | `crm_contact_organization` | `public` / `confidential` per ontology draft; tax identifiers `confidential` |
| `crm_opportunity` | Opportunity (`agency_opportunity`) | `crm_opportunity` | `internal`; amount `financial` if that property is in the approved field map (§10.3) |
| `crm_activity` | Activity (`agency_activity`) | `crm_activity` | `confidential` for summary |
| Business intake Webform submissions | IntakeSubmission (`agency_intake_submission`) | `intake_submission` | `personal` for payload |
| Private file fields on any of the above | File Gate reference | (same copier, file property) | `personal` or `confidential` as mapped |

`crm_contact` is one host type and two Agency types. Copy is not
"move the entity provider." Each row needs a reviewed discriminator
before the batch starts (§10.1). A row that matches both targets, or
neither, fails preflight.

### DEV-992 conditions per type

Every copier below is proposed. None exists in this package. Host
adapters will supply `EntityCopierBase` subclasses after G1.

| Type | Collision preflight | Reference retargeting | History and access reconciliation | Resumable, idempotent copy | Authority cutover and rollback |
| --- | --- | --- | --- | --- | --- |
| Person from `crm_contact` | Fail closed if the destination `agency_person` integer id, UUID or (if later revisioned) revision id is occupied by a different UUID. Discovery already stores thin person rows: a colliding Discovery person is a reviewed merge-or-refuse, never a silent renumber. | Retarget host type name `crm_contact`, entity references, routes and serialized ids that mean the person-shaped row. Preserving the number alone is not enough. Do not retarget organization-shaped rows onto Person. | Count parity for person-shaped contacts; per-field digests for marked personal properties; access-decision parity (CRM reader cannot read Discovery; Discovery participant cannot read CRM). Historical Audit Chain rows stay untouched. | Resume from last committed source id. Second complete run is a no-op. Do not overwrite a divergent Discovery person. | CRM domain flag. Replay Agency person writes through lineage. Do not delete a Discovery person that other relationships still need. |
| Organization from `crm_contact` | Same id/UUID/revision collision check on `agency_organization`. Workforce Slice 2 may already have created organization ids; collisions fail closed. | Retarget organization-shaped `crm_contact` references, including opportunity `organization_uuid` after that copy. Selection plugins `crm_client` / `wl_crm_client_profile` are not rows; their config references must be listed and remapped or left host. | Count parity for organization-shaped contacts; legal-name and kind digests; no cross-read into personnel or Discovery. | Same resume/no-op rules. | Same CRM domain flag. Rollback does not invent a host `crm_contact` kind the discriminator rejected. |
| Opportunity | Collision check on `agency_opportunity` id, UUID and revision id. | Retarget `crm_opportunity` type name, canonical (private) routes, activity `opportunity_uuid`, and any serialized host references. Host `won_client` account-number derivation stays a host Profile rule until Slice 4/membership placement says otherwise. | Count parity; stage/status digest; financial properties only if mapped; activity references resolve both directions. Access: commercial permission, not Discovery entitlement. | Same resume/no-op rules. Stage constraints stay enforced; copy must not bypass required-by-stage rules by writing a later stage without its prerequisites. | Same CRM domain flag. Closing an opportunity after cutover replays to legacy on rollback. |
| Activity | Collision check on `agency_activity` id and UUID. If the host type is revisionable and the ontology draft stays non-revisionable, that mismatch is a G1 blocker (§10.2), not a silent drop of revision ids. | Retarget `crm_activity` type name and opportunity references. | Count parity; summary digest; every activity points at a copied opportunity. | Same resume/no-op rules. | Same CRM domain flag. |
| IntakeSubmission | Collision check on `agency_intake_submission` id, UUID and revision id. `dispatch_key` uniqueness is a second preflight: two source rows with the same key fail closed. | Retarget dispatcher service IDs, queue item class names, and serialized webform submission ids that mean business intake. Generic form ids stay host. | Count parity; payload digest without printing PII; dispatch-key uniqueness; access-decision parity for submit vs coordinate. Outbox age and duplicate-send checks are rehearsal gates. | Resume must not enqueue a second external dispatch for a key already sent. Uncertain receipts retry the same key. | Intake may be its own authority row (§10.4). Rollback replays Agency accept/decline/withdrawn onto legacy without resending a completed dispatch. |

Shared copy rules (observed `agency_migrate` behavior, proposed here as
the Slice 3 contract):

- Agency defines `agency_*` types. Moving a host-named entity provider
  into this package is rejected (DEV-992 Option B).
- Historical Audit Chain events that name a legacy type stay untouched.
  One minimized lineage event per migrated batch links legacy
  references to Agency references.
- Markings travel with data. An unmapped property stops the run before
  any write.
- Synthetic fixtures only. No live contacts, tax identifiers, intake
  payloads or credentials in this repository.

Exact table, field, route, service and test inventory is
`docs/agency-extraction/ledger.json` at webcms `be5a1962`. Host PHP
class files remain **unverified** from this checkout; expand reads the
ledger rather than re-inventorying.

## 7. Authority, shadow-write, reconciliation and rollback

Authority states match the `agency_migrate` register (whether CRM and
intake are one row or two is §10.4):

| State | Agency writes | Agency reads | Legacy writes | Legacy reads |
| --- | --- | --- | --- | --- |
| `legacy` | no | no | yes | yes |
| `shadow` | yes, parallel, never used for authorization | no | yes | yes |
| `agency` | yes | yes | no (fail closed and alert) | read-only overlap |
| `removed` | yes | yes | no | no; lineage remains for audit verification |

The host adapter reads this flag. This package ships no default and no
configuration schema for a CRM or intake domain in this change. A later
expand may document the host key; the operator still approves each
transition as a Decision log line.

Shadow-write points for Slice 3:

1. CRM contact create/update after the legacy `crm_contact` row
   commits, split to Person or Organization by the reviewed
   discriminator.
2. `createOpportunity` / `closeOpportunity` after the legacy
   opportunity write.
3. `recordActivity` after the legacy activity write.
4. `submitIntake` and accept/decline/withdraw after the legacy
   submission and dispatch intent commit, or in the same host unit of
   work with a recorded uncertain recovery if dispatch fails.
5. Contact-policy decisions do not shadow-write. Policy is a read of
   whichever CRM side is authoritative (§4).

Reconciliation checks specific to Slice 3 (clean report required before
cutover):

- Count parity: person-shaped contacts, organization-shaped contacts,
  opportunities, activities, intake submissions, File Gate references,
  dispatch keys.
- Per-field digests for marked personal, confidential and financial
  properties.
- Reference integrity: activity → opportunity → organization;
  opportunity → organization; person-contact → person; intake →
  organization when present.
- Access-decision parity: a principal entitled only for Discovery
  cannot read CRM; a CRM coordinator cannot open a Discovery
  workspace; `mayContact` allow/deny matches the frozen host fixtures
  on synthetic addresses.
- Dispatch parity: each `dispatch_key` has one external send or one
  recorded uncertain receipt; no duplicate send across the rehearsal
  window.
- No unexplained drift over the rehearsal window.

Proposed SLOs (from [DEV-988](https://wilkesliberty.atlassian.net/browse/DEV-988),
not approved by this document): 99.5% monthly API availability, p95
read under 300 ms, outbox age under 150 s, zero unexplained
reconciliation drift, batch restart within 5 minutes.

Rollback:

- Flip the CRM (and intake, if separate) flag to `legacy`.
- Replay Agency writes to legacy through the lineage map. Do not
  restore a database backup as the rollback procedure.
- Limits: an external intake dispatch already sent is not unsent;
  capture versus send stay distinct. A Discovery person that existed
  before the copy is not deleted. Historical evidence is never
  re-chained. Contact-policy flips with CRM authority, not with a
  second flag.
- Rehearse on a staging clone before any production cutover.
  Production cutover is not authorized by this document.

Slice 1 `removed` is a later Discovery-domain transition. It is
unblocked by §4, not performed by this design.

## 8. Threat-model inputs

A Slice 3 threat model is not in this change. Inputs from this design:

| ID | Abuse case | Boundary | Proposed mitigation |
| --- | --- | --- | --- |
| S3-T1 | Discovery explorer becomes a CRM contact | Contact policy / createOpportunity | Separate types and commands; lookup is allow/deny only; denial tests both directions |
| S3-T2 | CRM role reads a Discovery workspace | Entitlement | Separate permissions; every read recomputes; shared Person is not a grant |
| S3-T3 | Contact policy leaks whether a CRM row exists | mayContact | Fail closed to the existing refused/none shape; no entity payload |
| S3-T4 | Contact data selects a principal | Identity | Exact issuer/subject from the IdP; CRM hit cannot bind |
| S3-T5 | `crm_contact` copied onto the wrong Agency type | Discriminator | Preflight refuse; no silent person/organization swap |
| S3-T6 | Id collision with existing Discovery person | Collision preflight | Fail closed; reviewed merge only; never renumber |
| S3-T7 | Duplicate intake dispatch across cutover or rollback | dispatch_key | Idempotent send; uncertain recovery; rehearsal proof |
| S3-T8 | Unmapped personal, tax or financial field copied | Markings | Unmapped property fails the batch |
| S3-T9 | Shadow Agency CRM used for Discovery mayContact | Authority flag | Policy reads legacy until CRM `agency`; host adapter is the only flag reader |
| S3-T10 | Generic noncommercial form breaks when CRM IDs move | Dispatcher map | Unmapped service ID fails closed; host-stays forms keep host IDs |
| S3-T11 | Cross-tenant CRM or intake read | Tenant | Tenant from the authenticated principal, never from a tool argument |
| S3-T12 | Leaving contact policy in `chronicle_agency` after CRM cutover | S1 residue | §4 drain checklist; S1 `removed` blocked until the port is gone |

Residuals for a later threat-model write-up: host message delivery for
intake, overlay field-map mistakes, and mixed coordinator credentials
(already refused by Discovery eligibility; commercial expand must keep
that adapter).

## 9. Test-parity list

Test classes per module are inventoried in `ledger.json` at webcms
`be5a1962`. The 2026-10-29 sweep counted 29 S3 classes to port. The
assurance kit ([webcms#1897](https://github.com/Wilkes-Liberty/webcms/pull/1897))
scanned 19 `chronicle_crm`, 10 `chronicle_forms` and 6
`chronicle_service_intake` classes at an earlier pin; some are
`host-stays`. [webcms#1937](https://github.com/Wilkes-Liberty/webcms/pull/1937)
mapped `CrmBreadcrumbTest` `to-port` on S3. Removal of a legacy module
is blocked by any unmapped test without a reviewed reason. Names below
are ledger module scopes and behavioral cases; this checkout still
cannot open the host PHP files.

| Legacy scope | Behavioral cases to port | Agency or host-adapter destination |
| --- | --- | --- |
| `chronicle_crm` | Private contact/opportunity/activity access; query filtering; no public canonical dump; stage constraints; email as duplicate hint not identity; breadcrumb; tax-id field permission; unmarked field refused | Commercial actions + migrate reconciliation + Field Guard |
| `chronicle_forms` (business) | Intake policy; outbox dispatch; idempotent dispatch_key; uncertain delivery; remapped dispatcher IDs | Intake actions + host dispatcher adapter |
| `chronicle_forms` (generic) | GraphQL checkbox, mail-subject, authored-locale, staff-notification language | Host-stays (webcms#1900) |
| `chronicle_service_intake` | Routing, mapping, queue/retry; vendor-neutral gateway | Intake orchestration; JSM gateway stays host |
| `chronicle_agency` contact policy | `mayContact` allow/deny against CRM; Discovery still starts only after policy and IdP subject checks; no CRM write on lookup | `agency_host.contact_policy` replacement + existing DiscoveryHostTest contact-policy cases |
| Host identity / private files | Identity deactivation denies CRM reads; File Gate UUID still authorizes intake/résumé delivery | Existing identity adapter + File Gate, not copied |

Discovery tests in this package remain the Slice 1 suite. Slice 3
design must not change their outcomes. Observed
`DiscoveryHostTest::testContactPolicyFailsClosed` pins the default
email policy only; a CRM-backed replacement needs its own synthetic
fixtures.

## 10. Open operator decisions

These are questions for a later Slice 3 G1. This document does not
record answers and is not that gate.

1. Approve the `crm_contact` split: which host field (or reviewed
   mapping) distinguishes person-shaped rows from organization-shaped
   rows, and what happens to a row that matches both or neither.
2. Approve whether `agency_activity` stays non-revisionable, or whether
   the ontology draft must gain revision ids to match a revisionable
   host `crm_activity`.
3. Approve the opportunity field map, including whether amount/currency
   / forecast enter Agency. The host CRM originally excluded financial
   forecast fields; the ontology draft currently lists `amount` as
   `financial`. An unmapped financial property cannot be invented at
   copy time.
4. Approve that CRM is one row in the `legacy` / `shadow` / `agency` /
   `removed` register, and whether intake is the same row or a second
   domain.
5. Confirm whether the `chronicle_agency` delivery-audit bridge still
   depends on `chronicle_crm`. If it does not, it leaves with Slice 1
   residue rather than this slice.
6. **Resolved by the Slice 2 design
   ([DEV-987](https://wilkesliberty.atlassian.net/browse/DEV-987)).**
   Career postings and the résumé/application workflow move to Agency
   in Slice 2 under proposed `agency_recruiting`. `career_application`
   copies onto `agency_application`, not `agency_intake_submission`.
   File Gate, Field Guard and Audit Chain carry over on that Slice 2
   path. Host editorial rendering stays host. Close-date extension
   stays a Slice 2 G1 item in
   [Workforce transfer](WORKFORCE_TRANSFER.md), not a Slice 3 decision.
7. Public commercial projections: keep host `clientProfile` /
   `clientDeliverables` / `clientInvoices` transport, or add Agency
   allowlists in this slice.
8. Whether `agency_commercial` and `agency_intake` are the submodule
   names (ontology open question 3), or whether commercial types wait
   under `agency_staff` until those modules exist.

## This change

Documentation only. No `agency_*` CRM entity type, no copier plugin, no
service registration, no schema change and no Discovery behavior
change. Expand implements the types and the contact-policy replacement
after a Slice 3 G1.
