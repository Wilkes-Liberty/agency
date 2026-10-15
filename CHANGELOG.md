# Changelog

All notable changes to Agency are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha2] - 2026-10-14

Prepared prerelease; publication requires maintainer approval. See the
[draft release notes](docs/RELEASE_NOTES_1.0.0-alpha2.md).

### Fixed

- Finish failed Discovery transaction callbacks before recording an observation
  in a new transaction. A successful observation must not make failed business
  work appear committed to its callbacks.

- Acquire the Discovery writer lock before the first ordinary read. A writer
  waiting under MySQL repeatable-read isolation must see its predecessor's
  committed changes when checking records and shared references.

### Added

- Explicitly authorized removal of one expired Discovery context, including
  its revisions, invitations and entitlements. Execution rechecks a bounded
  prepared plan, preserves shared records and denied bindings, and records
  removal evidence and its retry receipt in the same transaction. This internal
  service does not schedule deletion or remove host-owned stores. See
  [issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

- Internal retention policy guard for the default SQL configuration backend.
  It locks and verifies current policy, refuses stale cached data, and disables
  only retention maintenance when the storage wiring is unsupported. It does
  not delete records. See [issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

- Restricted, read-only retention preview for closed and withdrawn Discovery
  records. Pages use the current policy and recorded terminal time; missing
  history is held for review. This preview does not remove records or schedule
  retention enforcement. See [issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

## [1.0.0-alpha1]

Initial prerelease candidate. Publication is pending maintainer approval. The
package supplies Discovery domain services; a separately qualified host provides
authentication, private transport, screens and any approved message delivery.

### Fixed

- Bind invitation preparation retries to the freshly verified exact intended
  principal without changing the idempotency key. Recheck contact permission and
  stored identity binding before replay; refuse substituted, unavailable or
  rebound identities. Earlier unbound preparation receipts fail closed and
  require reconciliation without creating a replacement intent.

### Added

- Minimized evidence delivery health with durable attempts, safe failure timing,
  oldest-undelivered age and a caller-configured alert threshold. An additive
  storage update preserves existing evidence and delivery state.

- Invitation-only Discovery domain services, five internal content entities,
  revisioned state, scoped workspace and expiring summary reads, and explicit
  host identity/eligibility integration.
- Durable required evidence, denial intent, idempotency and transactional drain
  through Audit Chain's public keyed logger; no notification delivery.
- Failure-first domain checks for authorization, recovery, rollback, calendar
  policy, independent database writers and nested summary savepoints.

- Manual GitHub-to-DrupalCode synchronization guidance with explicit commit and
  tag review, ancestry checks, SSH authentication and publication boundaries.

- Product direction, integration principles, architecture, development and
  contributor guidance.
- GitHub supported-core functional checks, package/coding standards and shared
  attribution verification.
- PHPStan analysis on the GitHub development primary.
- A PostgreSQL 16 functional lane beside the MySQL 8.0 lanes, including
  independent-connection domain transaction and evidence-drain checks.
- Tenant policy fields for the retained-information statement and fallback
  contact route.
- Field Guard as the shared explicit-permission dependency for Agency Staff.
- Repository editor settings, review ownership and contribution templates.

### Changed

- Supported-core jobs run on the GitHub-hosted Ubuntu 24.04 image and start its
  preinstalled MySQL 8.0 and PostgreSQL 16 servers.

### Corrected

- Fallback support routes use the same typed contact shape as owner contacts.
  Coordinator reads stop projecting a pause note after the relationship resumes.

- Discovery consent leaves a dated agency handoff with participant Stop and
  coordinator next-action/close support. Replies preserve existing agency
  promises; read models expose current policy facts, reply choices, owner-only
  action modes and appropriately scoped participant notes.
- Invitation inspection supplies a tenant support route and an active inviter
  display name without exposing an owner's contact details.

- Composer's description identifies the package as a foundation.

- Duration validation describes its supported format and rejects a trailing newline.
- The Agency Staff module listing describes the current capability shell.

- The translatable configuration test fixture declares its language code.
- The PHP floor is 8.2, and the Drupal 10.6 compatibility leg exercises that
  floor.
- Policy periods require ISO 8601 durations; unknown policy keys and malformed
  close reasons fail typed-configuration validation.
- Drupal 11.3 and later expose the agency-name requirement through the status
  report's class-based hook while Drupal 10.6 retains its supported legacy hook.
- The functional-test server uses absolute document-root and router paths so
  requests remain routable throughout the installed-host suite.
- Security advisory coverage guidance distinguishes opted-in covered releases
  from development and uncovered releases.

### Package foundation

- Initial `agency` and `agency_staff` package structure.
- Tenant configuration schema for the agency display name and operating-policy
  keys, without tenant values or policy periods in package defaults.
- A runtime requirements error when the agency display name is missing.
- Drupal.org GitLab CI, coding-standard, and static-analysis configuration.
