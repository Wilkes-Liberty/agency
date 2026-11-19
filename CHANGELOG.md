# Changelog

All notable changes to Agency are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha7] - 2026-11-18

### Added
- A value-based post-cutover writer commits local entity mutations, qualified typed before/after histories and required evidence intents under the current Agency authority generation. Hosts must mark every stored target property and verify equivalent journal access, recovery and retention. Stable operation IDs recover scalar receipts without repeating committed mutations. Existing unqualified journal rows keep their recorded format. Nonempty rollback remains unavailable.
- Qualified equal-layout migration copiers can replay post-cutover creates,
  updates and deletes in journal order. Replay verifies the whole domain and
  commits authority, provenance, tombstones and a retryable handover receipt in
  one transaction. Hosts supply the reviewed compatibility and writer fence.
- Hosts can explicitly enroll local rollback participants for additional domain
  resources. Qualified rollback verifies and seals their receipts in the same
  transaction as entity replay and the authority handover.
- Clarify the combined release scope: the earlier implementation-stage notes below
  retain the limitations recorded when each component was introduced. Qualified
  nonempty rollback is now available for reviewed copiers under a verified writer
  fence, including the required journal and resource disposition checks. Recruiting
  close deadlines apply only to published open postings. The commercial, intake and
  engagement modules remain refusing scaffolds; their earlier design-gate notes do
  not describe runtime activation or completed migration.

  Add internal contact migration preflight for reviewed person and organization
  classifications. Preserve stored history and claims in reversible in-memory
  projections and refuse collisions in either target population.

  Qualify issued statement snapshots and challenge receipts in a read-only
  preflight with source-scoped replay checks, exact inverse rows and historical
  approval and client ownership evidence.
- Add revision-bound personnel evidence file qualification and inverse preflight with ordered file item preservation. Hosts verify access, protected file identity and existing grant inventory.
- Add internal signed-document qualification for verified retries, append
  intent and preservation of existing attachment and mapping records.
- Register internal workforce entity types for profile evidence, staff onboard
  submissions, workforce relationships and workforce entitlements. Access
  denies by default. Invitation storage stays a digest-keyed port.
- Add a default-denying workforce profile reader with verified identity and
  entitlement checks, uniform identity refusals and signed Audit Chain evidence.
- Host adapters load Agency-side rows through an authority-gated reader.
  At legacy, shadow or removed, or when a domain is unregistered, the load
  is refused with the recorded state as the reason and a minimized outbox
  intent. Entity API loads remain ungated.
- Register career posting and application entity types in an
  `agency_recruiting` submodule. Storage is deny-by-default and ID-preserving.
  Published postings require a future close date. No routes, copiers or
  recruiting authority ship in this change. GitHub CI runs the recruiting
  kernel tests on the 11.x lanes.
- Register workforce and recruiting migration-authority domains at legacy on
  install and on existing sites. Already recorded domain rows and their states
  are left unchanged. Slice 2 proving tests cover unmapped-property refusal,
  legacy-authority reads, and identity-preserving File Gate UUID copy.
  An unmapped-marking stop records a minimized outbox intent so the refused
  write leaves an Audit Chain trace. Migration docs record that install and
  update 10003 seed those two rows.
- Add tenant-neutral `agency_commercial` and `agency_intake` scaffolding with
  refusing ports. No entity types, copiers or authority rows. Slice 3 G1 remains
  open.
- Add an `agency_engagement` submodule with refusing engagement,
  assignment, time-submission and statement-snapshot ports and a
  storage-free review-path policy. No entity types are registered.
  Slice 4 G1 remains open.

### Changed
- Move the reviewed Externalauth binding reference from 2.0.13 to 2.0.15. Writer hashes are unchanged; the fingerprint digest changes because the version string is part of it. Unreviewed Composer references and mismatched writer bytes still fail closed.
- Record minimized, signed workforce invitation lifecycle evidence. Provide a separate application acceptance boundary that returns the same refusal for used and expired invitations.

### Fixed
- Preserve typed values in stored map fields when capturing and reconstructing
  revision history. Require a new snapshot format for layouts containing maps.
  Apply the same preservation to persisted copy, merge and overwrite. Journal
  qualification requires explicitly covered properties and refuses dynamic maps.
  Verify native shared map storage against the hydrated current row and every
  revision and translation. Refuse maps whose raw shape changes during hydration
  and layouts with dedicated map storage. Map writes require target storage on
  the writer's transaction connection. Shared map overwrites verify current and
  revision values before committing.
- Added a validated revision snapshot and atomic history merge helper that preserves
  target-only revisions, original metadata and translations, and exact default
  selection. Conflicting identities and revision IDs refuse. Nonempty rollback
  remains unqualified pending operation evidence and integrated replay.
- Refuse backup restoration requests before migration status, registration,
  transition or journal replay, with minimized durable refusal evidence.
- Restore Drush service discovery and typed authority command options.
- Apply the recruiting close deadline only to published open postings, so paused and filled postings can stay visible with a missing or elapsed deadline.

## [1.0.0-alpha6] - 2026-11-12

### Added
- A transaction-bound legacy writer API checks and locks the current authority generation on the caller's database connection. It refuses unregistered or promoted domains without granting authority-administration permission or claiming complete host writer coverage.
- An optional workforce invitation module provides transaction-bound issuance and acceptance, scoped recovery and required evidence delivery through bounded cron. Host storage, provisioning, policy and writer-boundary adapters default to refusal. Synthetic integration proofs cover legacy bearer compatibility, local account/profile atomicity, concurrent acceptance, recovery expiry and signed evidence retry.

### Changed
- Clarify the staff onboarding extraction contract: preserve anonymous single-use
  invitations, existing bearer hashes and expiry, and atomic account/profile creation.
  Separate this ceremony from authenticated Discovery and defer storage cutover
  until writer exclusion, reconciliation and rollback are qualified.

  Remove internal tracker references and dated host rollout windows from transfer
  guidance and archived release entries, preserving release headings and behavior
  descriptions. Clarify that CRM and time rollback must fence writers, replay and
  reconcile before restoring authority.

### Fixed
- Migration copy, rollback replay and reconciliation now include all current field
  items, stored item properties and translations. Incompatible field layouts
  refuse instead of silently dropping data. Reference checks inspect every delta
  and language independently of the canonical JSON encoding. Full revision history
  preservation remains separate work.
- Preserve complete revision and translation history during identity-preserving copy, including pending revisions and current default selection. Verify replay against versioned lineage manifests and refuse incomplete earlier copies.
- Migration authority now uses versioned operational SQL rows instead of cached,
  importable configuration. Competing changes serialize and reread their state;
  authority, events and evidence commit together. Upgrade 10002 preserves existing
  recorded states once. Authoritative transitions and rollback fail closed until
  the host supplies verified source/target writer coverage. Executing rollback
  acquires that boundary before its pending-write snapshot. Older code that reads
  the retained authority configuration must not operate on upgraded domains.
- Require complete source, target and lineage inventories for full reconciliation, detect count and identity-set mismatches, and identify explicit samples as limited comparisons that cannot qualify a full transfer.
- Refuse unqualified legacy rollback journals and ambiguous or undeclared domain copiers. Verify all pending identities before effects, and permit a concrete empty-journal return only when every declared domain copier passes full reconciliation under the writer fence.
- Validate rollback authority permission, domain, state and reason before
  previewing or replaying writes. Refused requests now leave legacy records,
  replay markers and authority evidence unchanged.
- Commit each migration batch's copies, lineage, resume progress and evidence
  intent together. Failed batches now roll back without losing evidence or
  miscounting copies when the run resumes.
- Routine module uninstall now refuses while migration or invitation recovery and evidence tables contain records, or their storage cannot be verified. Empty installations remain removable. No automatic purge bypasses retention or recovery review.

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
