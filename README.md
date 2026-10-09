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

Enabling `agency_staff` does not create relationships, accounts, roles, or
invitations. Those behaviors arrive as complete vertical slices with storage,
authorization, evidence, and tests.

## Configuration boundary

The host must provide `agency.settings`. No agency name or policy period ships
as a package default. The schema declares these keys:

- `display_name`
- `policy.invitation_validity`
- `policy.acknowledgement_period`
- `policy.follow_up_period`
- `policy.retention_after_close`
- `policy.close_reasons`

Drupal's status report returns an error while `display_name` is absent or blank.
Invitation and participant-workspace code must call
`agency_require_display_name()` and refuse to operate in that state.
Policy values and participant-facing close wording belong to the installing
agency and must be configured before any real invitation is prepared.

## Storage and dependencies

Agency stores business data through Drupal's Entity API in the host Drupal
database, with an `agency_*` logical boundary. It does not require a separate
database. Audit Chain is a package dependency; Agency records business actions
through its public contract rather than copying its implementation.

## Supported versions

- PHP 8.1 or later
- Drupal 10.6 or later on the 10.x line
- Drupal 11.3 or later on the 11.x line

GitHub and Drupal.org CI are configured to exercise supported core branches.
Actual passing results, not the presence of a workflow, establish compatibility.

## Development and contribution

- [Contributing](CONTRIBUTING.md): issue routing, focused changes and review.
- [Development](docs/DEVELOPMENT.md): Composer, standards and installed-host tests.
- [Architecture](docs/ARCHITECTURE.md): domain, host, integration and evidence boundaries.
- [Maintaining](docs/MAINTAINING.md): development line, remotes and release verification.
- [Security](SECURITY.md): private vulnerability reporting and coverage status.

Module bugs and feature requests belong in the
[Drupal.org issue queue](https://www.drupal.org/project/issues/agency).

## Maintainers

- [Jeremy Michael Cerda](https://www.drupal.org/u/jmcerda) — <jmcerda@wilkesliberty.com>
- Wilkes & Liberty, LLC — [wilkesliberty.com](https://wilkesliberty.com)
