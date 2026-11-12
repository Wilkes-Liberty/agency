# Agency

Agency is a Drupal package connecting people, clients, agreements, delivery and
payment across the life of a working relationship. Its product journeys are
Win, Staff, Prove and Settle; those journeys do not prescribe module boundaries.

This is an early package foundation, not a complete agency application. See the
[working North Star](docs/NORTH_STAR.md) for the intended experience, including
integration with the tools people already use.

The base module owns shared, tenant-neutral configuration. Capability modules
own their business records and workflows. General CMS concerns remain with the
host site, while identity, protected-file delivery, evidence, and payment
engines remain independent dependencies.

## Initial modules

- `agency` provides the shared package foundation and tenant configuration.
- `agency_staff` is the capability boundary for workforce relationships and the
  Discovery workspace.
- `agency_workforce` provides staff invitation issuance, acceptance, recovery and
  durable evidence through explicitly configured host adapters.
- `agency_migrate` copies host records into Agency types with lineage, shadow
  reconciliation and operational authority. Host adapters supply copier plugins
  and verified writer exclusion. Nonempty rollback replay remains unavailable.

`agency_staff` provides invitation-only Discovery domain services, internal
entity storage, scoped workspace/summary projections and a durable evidence
outbox. See the [provisional service contract](docs/DISCOVERY_CONTRACT.md).
The host supplies authentication, transport and the participant interface.
The default identity adapter denies access. Enabling the module creates no
relationships, accounts, roles or invitations, and sends no notifications.

`agency_workforce` preserves anonymous staff onboarding separately from Discovery.
Its optional services commit local account/profile provisioning, invitation
consumption and evidence intent together. The host supplies storage, policy,
provisioning and writer-boundary adapters; all four default to refusal. Enabling
the module supplies no public route, sends no mail and transfers no authority.
See the [invitation adapter guide](modules/agency_workforce/README.md).
Routine uninstall preserves populated migration and invitation recovery stores;
retiring an installation requires a reviewed retention and recovery procedure.

The alpha6 migration scope includes complete revision/translation copying,
atomic resumable batches and complete-population reconciliation. A rollback with
no pending journal writes can restore legacy authority only after full domain
reconciliation under a qualified host fence. Existing nonempty journals lack
the operation snapshots needed for safe replay and are refused. This release
does not qualify a production cutover or complete the broader workforce, CRM,
intake and time extraction.

## Host contract (`agency_host`)

`agency_host` is the generic host adapter: identity history, private Discovery
transport, coordinator console, invitation custody, evidence maintenance and
`drush agency:readiness`. It replaces the default denying identity service.
Tenant values stay in the installing site; this module ships empty defaults.

The host supplies:

- PHP settings `agency_host_identity` (issuer, binding version/mode, and IdP
  Admin API connection). Former `chronicle_agency_*` setting names remain
  readable during adoption.
- Config `agency_host.settings` for the BFF consumer, covered authmap
  providers, contained OpenID Connect clients, coordinator role denylist,
  protected account names, clean-origin source modules, and extra containment
  scans.
- Config `agency_host.identity_tokens` for the dedicated participant issuer and
  audience. Historical `paladin_issuer` / `paladin_audience` keys remain so
  already-qualified binding fingerprints do not change.
  `jwks_forced_refresh_interval` is the minimum seconds between forced JWKS
  refetches for an unknown `kid` (default 60). Binding fingerprints do not
  include this interval.
- Invitation custody in `$settings['agency_host_invitation_delivery']` (not
  config). The AES associated data stays `agency-delivery:1:`.
- Optional plugins: replace `agency_host.contact_policy` for CRM resolution,
  and `agency_host.delivery_audit` when a site-specific audit recorder exists.

Qualified binding fingerprints keep HMAC `chronicle-agency-history-key-v1` and
the digest schema slot keyed `chronicle_agency`. Do not add `agency_host` to
that array. Enabling `agency_host` copies former `chronicle_agency` tables,
config, state, key/value collections and queue items; it never drops the
source, so a code rollback can still read custody. Uninstall
`chronicle_agency` only after `agency_host` is enabled and copies succeed.
Clean-origin qualification stays pinned to schema 10003. The reviewed
Externalauth writer reference stays `2.0.13`; 2.0.14 and 2.0.15 keep the same
writer bytes, but moving the pin would change the digest. See the
[2.0.15 review](docs/EXTERNALAUTH_2.0.15.md).

## Configuration boundary

The host must provide `agency.settings`. No agency name or policy period ships
as a package default. The schema declares these keys:

- `display_name`
- `policy.invitation_validity`
- `policy.acknowledgement_period`
- `policy.follow_up_period`
- `policy.retention_after_close`
- `policy.summary_window` (optional, enables expiring terminal summaries)
- `policy.retained_information_statement`
- `policy.fallback_contact_route`: explicit `{kind, value}` contact mapping;
  legacy plain-text values must be replaced without inferring the channel.
- `policy.close_reasons`

Drupal's status report returns an error while `display_name` is absent or blank.
Invitation and participant-workspace code must call
`agency_require_display_name()` and refuse to operate in that state.
Policy values and participant-facing close wording belong to the installing
agency and must be configured before any real invitation is prepared.
Period values use a supported ISO 8601 subset: whole-number components, with
optional fractional seconds. Use `P7D`, `PT12H`, `PT1H30M` or `PT0.5S`; express
fractional days or hours using smaller whole units. Week values stand alone
(`P2W`), without other components. The retained-information statement is
tenant-authored text. The fallback contact route is an explicit `{kind, value}`
mapping; replace a legacy plain-text value without inferring the channel.
Discovery rejects zero durations. Calendar years/months clamp to the last day
of the target month in UTC before adding the remaining days/time. Summary
expiry is independent of record retention.

The restricted [retention services](docs/DISCOVERY_RETENTION.md) report when
closed and withdrawn records become due, then remove one reviewed expired
context and its revisions. Retention starts at each record's stored terminal
time. Current policy supplies the period; a later change moves outstanding
deadlines without rewriting that timestamp. Shared person and identity records
remain when other relationships still need them. Automatic scheduling, message
custody and backup restoration stay with the host. A preview is not evidence of
deletion. Summary-link expiry and message-payload expiry are separate from
relationship retention.

## Storage and dependencies

Agency stores business data through Drupal's Entity API in the host Drupal
database, with an `agency_*` logical boundary. It does not require a separate
database. Audit Chain is a package dependency; Agency records business actions
through its public contract rather than copying its implementation. Field Guard
provides the explicit-permission check used at coordinator authorization
boundaries; Agency does not reproduce that access primitive.

## Supported versions

- PHP 8.2 or later
- Drupal 10.6 or later on the 10.x line
- Drupal 11.3 or later on the 11.x line

GitHub and Drupal.org CI are configured to exercise supported core branches.
Actual passing results, not the presence of a workflow, establish compatibility.

## Development and contribution

- [Contributing](CONTRIBUTING.md): issue routing, focused changes and review.
- [Development](docs/DEVELOPMENT.md): Composer, standards and installed-host tests.
- [Architecture](docs/ARCHITECTURE.md): domain, host, integration and evidence boundaries.
- [Ontology](docs/ONTOLOGY.md): draft tenant-neutral object, link and action contract.
- [Migration](docs/MIGRATION.md): identity-preserving copy, reconciliation and rollback.
- [Workforce transfer](docs/WORKFORCE_TRANSFER.md): broader Slice 2 proposal and unregistered scaffolding.
- [Staff onboarding compatibility](docs/WORKFORCE_INVITATION_CONTRACT.md): anonymous invitation, transaction and migration requirements; implementation and host qualification remain separate.
- [CRM transfer](docs/CRM_TRANSFER.md): proposed Slice 3 CRM, intake and contact-lookup design.
- [Time transfer](docs/TIME_TRANSFER.md): proposed Slice 4 engagement, time, client-membership and feed design. No record types.
- [Maintaining](docs/MAINTAINING.md): development line, remotes and release verification.
- [Security](SECURITY.md): private vulnerability reporting and coverage status.

Module bugs and feature requests belong in the
[Drupal.org issue queue](https://www.drupal.org/project/issues/agency).

## Maintainers

- [Jeremy Michael Cerda](https://www.drupal.org/u/jmcerda) — <jmcerda@wilkesliberty.com>
- Wilkes & Liberty, LLC — [wilkesliberty.com](https://wilkesliberty.com)
