# Changelog

All notable changes to Agency are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha5] - 2026-11-12

### Fixed
- Use Drupal's `FetchAs` enum for invitation delivery listing on Drupal 11.2+,
  keeping the Drupal 10 PDO fetch mode so listing rows stay associative arrays.

## [1.0.0-alpha4] - 2026-11-06

### Added
- Add a CI and PHPUnit check that package code, configuration and shipped defaults stay free of tenant-specific strings, with an allowlist for declared legacy-compat keys.

### Changed
- Use independent changelog fragments for pull requests and the shared validator for lossless release preparation.

  Keep the Discovery concurrency CI worker compatible with Audit Chain's dedicated stream logger dependency.
- Ready the Slice 2 workforce transfer design for operator gate G1: career
  postings and the résumé/application workflow move to Agency, every S2
  source maps to an `agency_*` type, copier plugin and Field Guard
  marking, with identity-preservation conditions listed per entity. Ontology
  draft v0.1.1 adds a recruiting relationship kind, a proposed
  `agency_recruiting` boundary, and proposed application, posting,
  profile-evidence, staff-invitation and staff-onboard types.
  `chronicle_personnel` copies onto `agency_person` and
  `agency_profile_evidence`. Career-upload tokens, résumé scan jobs and
  claim-scoped access are File Gate carry-over; geo handlers are host-stay;
  client profile bundles are host-stay with fail-closed collision
  preflight. Staff and recruiting writes use their own evidence shapes.
  Slice 2 record types are not registered.

  See the [workforce transfer design](docs/WORKFORCE_TRANSFER.md).

### Fixed
- `agency_host` throttles forced JWKS refetches for an unknown `kid` to a
  configurable per-issuer interval (default 60 seconds). The last forced
  refresh is stored in the existing JWKS cache bin and serialized with a lock
  so concurrent requests cannot each trigger discovery. One refresh after the
  window still picks up a rotated key. See
  [GitHub #31](https://github.com/Wilkes-Liberty/agency/issues/31).

### Docs
- Propose the Slice 3 CRM and intake transfer design, including the Slice 1
  contact-lookup port that must leave `chronicle_agency` before Discovery
  authority can reach `removed`.
- Propose the Slice 4 engagement, time, client-membership and commercial-feed transfer. The design applies identity preservation per source type and adds no record types.

### Documentation
- Record the Externalauth 2.0.15 binding-writer review and pin fingerprint behavior on the reviewed 2.0.13 release without changing the digest. Deny-path tests execute the unreviewed-reference, writer-hash and schema-floor throws.

## [1.0.0-alpha3] - 2026-10-18

Prepared prerelease; publication requires maintainer approval. See the
[draft release notes](docs/RELEASE_NOTES_1.0.0-alpha3.md).

### Changed

- AGENTS.md: PR and issue text is written for the reviewing operator; agent rules are not restated there.

- README and the Discovery contract describe the authorized retention removal
  service, retention-start semantics for withdrawn and closed records, and host
  responsibilities for scheduling, message custody and backup restoration.

### Added

- `agency_host` generic Discovery host adapter: identity history, private
  transport, coordinator console, invitation custody, evidence maintenance,
  and `drush agency:readiness`. Tenant values stay with the installing site.
  Enabling the module copies former `chronicle_agency` tables, config, state,
  key/value collections and queue items without dropping the source. Qualified
  binding fingerprints keep HMAC `chronicle-agency-history-key-v1` and the
  digest schema slot keyed `chronicle_agency`. Contact policy and delivery
  audit are replaceable ports. Historical `paladin_issuer` / `paladin_audience`
  token keys remain readable so already-qualified coverage does not change.
  Installing hosts can require `^1.0.0-alpha3`.

- Draft ontology contract v0.1.0: tenant-neutral object, link and action
  types with machine-readable YAML, JSON Schemas and failing-fixture
  contract tests. Discovery storage stays the implemented subset. See
  [GitHub #25](https://github.com/Wilkes-Liberty/agency/pull/25).

- `agency_migrate` identity-preserving copy, lineage, property markings,
  reconciliation and reversible authority (`legacy` → `shadow` → `agency`
  → `removed`). Host adapters supply copier plugins; this package ships a
  synthetic plugin for kernel proofs only. See
  [GitHub #27](https://github.com/Wilkes-Liberty/agency/pull/27).

- Slice 2 workforce transfer design and unregistered Staff command, read
  and host-adapter scaffolding, with value-object validation tests.
  Authority ids match the migrate register. Expand, service registration
  and schema wait on design gate G1. See
  [GitHub #26](https://github.com/Wilkes-Liberty/agency/pull/26).

- Interrupted-batch and withdrawn-terminal retention controls. An earlier
  committed removal stays removed when a later item rolls back; the same
  current policy and recorded terminal time apply after Stop. GitHub CI now
  requires these concurrent retention cases to execute. See
  [issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

- Discovery verification for identical missing and unrelated refusals, stored
  summary-window expiry, and an interrupted evidence drain that retains one
  pending intent. Drupal.org PHPUnit runs the `agency` group so MySQL lanes do
  not execute the explicit PostgreSQL host-qualification test. CSpell uses a
  project dictionary, and COPYING is ignored as GPL boilerplate. See
  [issue 3629756](https://www.drupal.org/project/agency/issues/3629756).

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
