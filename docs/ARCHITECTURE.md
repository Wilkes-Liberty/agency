# Architecture

Agency is a reusable Drupal module package for agency and business operations.
The initial foundation provides the base module and an `agency_staff` shell.
Domain storage, invitations, authorization, application APIs and user interfaces
are separate implementation slices.

## Package structure

| Location | Responsibility |
| --- | --- |
| `agency.info.yml`, `agency.module`, `agency.install` | Base module and shared configuration guard |
| `config/schema` | Typed tenant-configuration contract |
| `modules/agency_staff` | Initial workforce capability boundary |
| `tests/src/Functional` | Installed-host package verification |
| `docs` | Product direction, boundaries and development guidance |

The four product loops are journeys across domains. Final package boundaries should
follow cohesive responsibilities and dependency direction, not the four loop names.

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

The current display-name guard is a foundation for later entry points. It does not by
itself implement invitation, authorization or workspace behavior. The invitation domain
slice must refuse to prepare an invitation when its validity, acknowledgement or follow-up
period is absent or invalid, and must cover those failure paths in an installed-host test.

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

Use Audit Chain's public collector contract for meaningful business transitions.
Define correlation, persistence failure and reconciliation behavior before making writes.
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
