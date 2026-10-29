# Agency ontology contract

Status: draft v0.1.0. This is the canonical, tenant-neutral model Agency
owns. Later slices implement a subset. It is not evidence that a proposed
type, submodule or action exists in code.

The machine-readable contract is [`ontology/ontology.yml`](../ontology/ontology.yml).
JSON Schemas in `ontology/` validate object, link and action lists.
[`OntologyContractTest`](../tests/src/Unit/OntologyContractTest.php) checks
those schemas, the markings and permission rules, and the genericity rule.

This document does not replace the implemented Discovery service contract.
Where a fact is already specified for Discovery, that contract wins.

## Implemented versus proposed

| Status | Meaning |
| --- | --- |
| Implemented | Present in `agency_staff` and must match current storage and commands. |
| Proposed | Design for a later slice. Not an installed module, route or API. |

Implemented object types are the five internal Discovery records:
`agency_person`, `agency_principal`, `agency_discovery`, `agency_invitation`
and `agency_entitlement`. Implemented actions are the Discovery commands,
invitation issue/resend/accept/revoke/inspect, and expired-record removal.

Expense, Credential, Availability and Campaign stay proposed for slice 5.
Agreement is also proposed for that slice. Organization and Membership are
proposed for slice 2. Opportunity, Activity and IntakeSubmission are
proposed for slice 3. The proposed transfer design is
[CRM transfer](CRM_TRANSFER.md). Engagement, assignment, time and
statement types are proposed for slice 4.

## Model

The contract has three catalogs:

- **Object types** hold durable business records. Each type has a stable
  `agency_*` machine name, identifier flags, a lifecycle, marked properties,
  tenant scope and optional `legacy_sources` for lineage.
- **Link types** name a directed association, its cardinality and which side
  owns the reference. A join table is allowed when neither side can own an
  N:M pair.
- **Action types** are the only writes. Each action has an input shape,
  validation rules, a required permission, optional approval, minimized
  evidence, an idempotency key and a deny-first flag.

Identifiers are an integer id, a UUID and, when the type is revisionable, a
revision id. Migration must copy those values. Host-named legacy type ids
are lineage, not Agency type ids.

### Relationship kinds

Relationship is one conceptual type with kinds: discovery, workforce,
client, partner and supplier. Discovery is implemented today as
`agency_discovery`. Other kinds have proposed machine names. Workspace
projections emit `kind: discovery` only.

Invitation lifecycle is separate from relationship state. Entitlement
status is separate from both. Application and recruiting labels are not
Discovery states.

### Markings

Every property has one marking:

| Marking | Use |
| --- | --- |
| public | May appear in an audience projection when that field is allowlisted. |
| internal | Coordinator or operator use. Not a participant field. |
| confidential | Restricted coordinator use. Notes, tokens and raw details. |
| personal | Personally identifying fields. Field Guard applies at write boundaries. |
| financial | Amounts, rates, billable flags and currency. |

An unmapped property blocks migration. Raw `details` blobs are confidential
even when some decoded fields are public.

### Tenant scope

Tenant identity is derived by the host, never from a caller-supplied type
argument. Every object denies cross-tenant reads. Person, principal and
organization rows may be shared within one tenant when more than one
relationship still needs them.

Tenant display names, policy periods, retained-information statements,
fallback contact routes and close reasons are host configuration. They are
not package defaults and are not ontology properties.

### Projections

Reads expose allowlisted projections, not entity objects. Current audiences
are participant workspace, participant summary, coordinator queue and
evidence metadata. Unknown fields are ignored. Evidence metadata is limited
to opaque identifiers, operation, state, waiting side, timestamps and an
actor hash. Contacts, notes, tokens, credentials and file data are
forbidden there.

### Actions

A write is a named action. Positive Discovery writes share a transaction
with the required outbox insert. Access-reducing actions
(`withdrawRelationship`, `revokeInvitation`, `suspendAccess`) commit denial
and immutable local intent together before drain. A failed drain never
reopens access.

Idempotency keys are one user intent. The published pattern is
`^[A-Za-z0-9_.:-]{8,128}$`. The same key and payload replay the original
minimal receipt while the actor still has authority. A different payload
with the same key is refused. Terminal participant retries are
`not_found`.

Participant actions authorize an entitled exact issuer/subject pair. They
do not use a Drupal permission name. Coordinator actions require the
listed Drupal permission, host eligibility and, except recovery, assignment.

## Compatibility

The contract version is semantic. Adding a proposed type is compatible.
Promoting a proposed type to implemented is compatible when the published
shape is unchanged. After the first beta, implemented S1 and S2 types are
frozen. A breaking change needs a deprecation cycle: announce the
replacement, keep the old name readable for one minor, then remove it no
earlier than the next major.

## Open questions for the operator

These are the decisions this draft needs before later slices treat the
model as frozen.

1. Approve `agency_*` entity types with identifier-preserving copy and a
   lineage map, instead of moving host-named providers into this package.
2. Keep Discovery on `agency_discovery` and give other relationship kinds
   their own machine names, or later unify kinds under one type id.
3. Approve the proposed submodule names: `agency_workforce`,
   `agency_commercial`, `agency_intake`, `agency_engagement`,
   `agency_agreement`, `agency_expense` and `agency_campaign`.
4. Approve the marking vocabulary and the rule that an unmapped property
   stops migration.
5. Approve participant authorization as an entitled principal rather than
   a Drupal permission.
6. Approve the draft as ontology v0 for the ontology approval gate, with
   slice-five types remaining proposed until that slice is designed.

## What this version does not do

- It does not install modules, create records or change permissions.
- It does not move host adapters or legacy tables.
- It does not define host API paths or a public JSON:API surface.
- It does not authorize a real invitation, cutover or release tag.
- Proposed actions are placeholders for slice design. Their permission
  machine names are not shipped permissions.
