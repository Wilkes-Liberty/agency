# Architecture

Agency is a reusable Drupal module package for agency and business operations.
The base module provides shared configuration. `agency_staff` implements the
initial invitation-only Discovery domain. Host APIs and user interfaces consume
its [service contract](DISCOVERY_CONTRACT.md) through explicit adapters.

## Package structure

| Location | Responsibility |
| --- | --- |
| `agency.info.yml`, `agency.module`, `agency.install` | Base module and shared configuration guard |
| `config/schema` | Typed tenant-configuration contract |
| `modules/agency_staff` | Initial workforce capability boundary |
| `modules/agency_workforce` | Transactional staff invitations with explicit host ports |
| `modules/agency_recruiting` | Career posting and application storage; deny-by-default; no copiers or authority |
| `modules/agency_campaign` | Marketing campaign storage and launchCampaign; deny-by-default; no copiers or authority |
| `modules/agency_expense` | Expense storage and recordExpense; deny-by-default; no copiers or authority |
| `modules/agency_agreement` | Agreement storage; deny-by-default; no write action, copiers or authority |
| `modules/agency_host` | Generic Discovery host adapter |
| `modules/agency_migrate` | Identity-preserving copy, lineage and authority |
| `ontology/` | Draft object, link and action contract |
| `tests/src/Functional` | Installed-host package verification |
| `tests/src/Kernel`, `tests/fixtures` | Domain authorization, rollback and independent database writers |
| `tests/src/Unit` | Ontology contract and tenant-string guard |
| `docs` | Product direction, boundaries and development guidance |

The four product loops are journeys across domains. Final package boundaries should
follow cohesive responsibilities and dependency direction, not the four loop names.

Slice 5 credential, availability and roster placement is still proposed;
see [Slice 5 remaining placement](S5_OPEN_QUESTIONS.md).

## Domain ownership

The intended business domain includes relationships and CRM, marketing and intake,
contracts, workforce and capacity, engagements, delivery coordination, time and expenses,
invoices and settlement orchestration. Existing applications remain authoritative during
any extraction until migration and consumer cutover are verified.

The host retains general CMS, content and site composition. Agency consumes independent
identity, protected-file, field-access, evidence, knowledge and payment services through
their contracts. It must not copy those implementations.

Drupal's database and Entity API provide the initial storage environment. A shared database
does not imply shared authorization. Installation topology and any multi-tenant support
require explicit isolation design and verification before a support claim.

## Contact migration preflight

The internal commercial preflight takes a complete authorized snapshot for one
host and tenant, a private reviewed crosswalk, and an occupancy inventory for
both target populations. The host supplies source authorization and verified
user UUIDs. Review rows bind the source ID and UUID to one classification,
a history watermark and a digest. The watermark covers all source revisions,
their storage layout, default selection and referenced user identities. The
digest detects changes to a trusted row.

Person-shaped records have a commercial history and an optional independently
reviewed Person binding. Organization-shaped records hold the reviewed
shareable display name separately from a private commercial extension keyed
by Organization UUID and original revision ID. Inverse reconstruction recovers
the exact source value tree, merge references and claim bytes in memory. The
preflight reads supplied occupancy across both populations. Durable migration
requires a separate qualified writer and shared identity reservation.

## Independent state

Keep these concepts separate in storage, policy and user experience:

- Person, login identity, organization and membership.
- Prospective relationship, employment classification and work authorization.
- Vetting, roster participation, availability and accepted assignment.
- Credential verification, readiness and access grant.
- Document receipt, signature, review and policy completion.
- Time submission, acceptance, billability and payment.
- Payment request, provider acceptance and confirmed settlement.

A shared profile is an audience-specific projection, not permission to read its underlying
personnel, client or financial records.

## Tenant policy

The package does not ship an agency name, policy periods, retained-information statement,
fallback contact route or close reasons. Hosts supply reviewed configuration. Policy
periods use the ISO 8601 subset documented in the README (whole-number components
with optional fractional seconds; weeks cannot mix with other components). Missing required configuration must remain distinguishable
from a valid empty or disabled policy; it must not silently authorize a business action.

Discovery refuses invitation preparation when its required name, validity,
acknowledgement, follow-up or retention configuration is absent or invalid.
The optional summary window grants a separate expiring projection; failure to
prepare that grant cannot preserve workspace access after a successful Stop.

## Workflow integration seams

Connectors should translate provider activity into explicit, authorized business commands
or contextual links. Email, SMS, calendars, X, LinkedIn and project tools are intended
integration candidates, not declared supported adapters.

Design each adapter around account linking, scoped permissions, provider capabilities,
message provenance, verified callbacks, idempotency, delivery receipts, retries,
rate limits, revocation and reconnect behavior. Keep provider payloads outside the domain
model unless needed and authorized. A notification is not evidence that a person read,
accepted or signed anything.

Maintain one authoritative record for each fact. A disconnected connector must not erase
the underlying business relationship or create a second source of truth.

## Evidence and protected documents

Agency owns the durable evidence outbox. Positive business transitions and their
required outbox inserts share a database transaction through `recordRequired()`.
Withdrawal, invitation revocation and access suspension commit denial and immutable
local intent together before delivery. A failed drain never reopens access.
The drainer uses Audit Chain's public `logKeyed()` contract; its request-scoped
collector is not a durable retry queue. A recorded receipt confirms local
persistence, not delivery to the chain.
Do not put private documents, message bodies or personal financial data in audit metadata.

File Gate can protect document delivery; signature, personnel-verification and clearance
workflows still need their own defined responsibilities. Sensitive actions require
appropriate identity and authorization regardless of their entry channel.

## Evolution

Extract responsibilities in bounded slices. Preserve identifiers, revisions, references,
permissions and historical evidence. Use compatibility adapters while consumers migrate.
Removal follows verified cutover, not an assumed rename.

Do not add empty future modules, speculative public APIs or tenant-specific defaults
to reserve a name. The contribution should demonstrate one useful outcome and its failure paths.

Staff onboarding extraction follows the [compatibility contract](WORKFORCE_INVITATION_CONTRACT.md):
existing anonymous invitations remain distinct from Discovery authentication, and
local account/profile provisioning shares the invitation-consumption transaction.
