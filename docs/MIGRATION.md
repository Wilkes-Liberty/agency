# Migration and reconciliation

Status: implemented framework for synthetic copy, interruption, reconciliation
and rollback. Host adapters, ontology object types and production cutover are
not in this module. See the [architecture](ARCHITECTURE.md) preservation rules.

`agency_migrate` moves records from a host's legacy entity types into Agency
entity types while preserving identity, recording lineage, comparing both sides
in shadow, and supporting a reversible authority switch. It ships no tenant
names, policy values or host type identifiers. Production source types arrive
as copier plugins implemented by the host or by a later Agency capability
module once the ontology contract lists them. Proposed Slice 3 sources and
DEV-992 conditions per type are in [CRM transfer](CRM_TRANSFER.md).
Proposed Slice 4 source mappings live in [Time transfer](TIME_TRANSFER.md);
that design does not register copiers or record types.

## What this module does

Observed in the kernel suite against synthetic entity types:

- A copier plugin copies one source type into one target type with the same
  integer id, UUID, all revision ids, created/changed timestamps, revision
  authors and revision logs. Every non-computed property, ordered field delta,
  empty field and historical translation is copied for matching field layouts.
  The current default stays selected even when a newer pending revision exists;
  each revision retains its historical default and translation metadata.
- The target id must not already belong to a different UUID.
- Each copied pair is stored in a bidirectional lineage map with a content
  digest, a versioned history manifest and the run that wrote it. The manifest
  contains revision digests and default selection, without content values.
- Runs are bounded batches. Each batch commits target records, lineage rows,
  cursor and counters, and its evidence intent in one transaction. A failed
  batch leaves no partial copies or progress; resume starts after the last
  completed batch. Earlier completed batches remain durable. A second complete
  run is a verified no-op when both sides still match the committed manifest.
- Every source content property must have a marking (`public`, `internal`,
  `confidential`, `personal` or `financial`). An unmapped or invalid marking
  fails the run before any write.
- Historical Audit Chain rows are never rewritten. One minimized lineage event
  per batch is recorded through Agency's outbox and drained with Audit Chain's
  public `logKeyed()` contract so a verifier can resolve a legacy reference to
  its Agency counterpart.
- A reconciler reports counts, per-field digests, integer-reference integrity
  and access-decision parity (same principal token, same allow/deny on both
  sides) as JSON with a pass/fail and explained exceptions.
- An operational SQL authority register holds one versioned state per domain:
  `legacy`, `shadow`, `agency`, `removed`. Transitions move forward, except the
  rollback edge `agency → legacy`. Each change requires
  `administer agency migrate authority` and stores its state, event and evidence
  intent in one transaction. Authority configuration is no longer runtime state.
- Changes involving Agency authority require verified host writer coverage. The
  shipped adapter refuses with `writer_fence_unavailable`; no production writer
  barrier or cutover qualification is supplied by this framework.
- Rollback replays Agency writes recorded after cutover onto legacy through the
  plugin writer and then sets authority to `legacy`. It is not a database
  restore.

Rollback preview and execution both require `run agency migrate` and
`administer agency migrate authority`, a valid registered domain currently at
`agency`, and a nonblank reason of at most 255 bytes after trimming. These
checks run before reading pending writes or replaying records, so a request
that fails preflight leaves legacy records, replay markers, authority and
evidence unchanged. The final authority transition validates the request again.
Preflight does not lock the domain or provide protection against concurrent
writes; the rollback limits below still apply.

## Copier plugins

Implement `Drupal\agency_migrate\Plugin\CopierInterface`, typically by
extending `EntityCopierBase`. Discoverable under
`Plugin/AgencyMigrate/Copier` with the `MigrateCopier` attribute (Drupal 11)
and annotation (Drupal 10). Declare `source_type` and `target_type`. Provide:

- `propertyMarkings()` for every copied content property
- `referencePropertyNames()` for integer references to the same source/target
  entity type, checked at every delta and language during reconciliation
- `accessDecision($principal, $side, $id)` for parity checks

The runner does not infer types from the ontology document. When ontology v0
lists Agency object types, each listed legacy source gets its own plugin.
Proposed Slice 2 copier plugin ids and Field Guard markings are named in
[Workforce transfer](WORKFORCE_TRANSFER.md); those plugins are not shipped
until the operator approves G1.

The generic writer replaces its former `scalarValues()` helper with
`fieldValues()`, which returns complete item arrays. Host integrations calling
that alpha helper must adapt; package callers have been updated.

The generic writer requires matching identity key names, revisionability,
translatability, stored field names, types, cardinality and storage settings.
It refuses incompatible layouts with `incompatible_field_layout` before the
record write. Different entity keys (for example `nid` versus `id`), bundles,
reference target types or field transformations need an explicit host adapter;
the writer does not infer mappings.

Canonical per-field values are JSON objects keyed by language, containing all
ordered field items and their non-computed properties. Property keys and
language keys are sorted; delta order remains significant. Primitive properties
use their declared Drupal typed-data cast, preserving boolean and numeric types
across database drivers; null and empty lists remain distinct. Older scalar-only
lineage digests are not comparable to this encoding.

## Revision history and existing copies

`EntityCopierBase` implements the additive `HistoryCopierInterface`. It loads
all revisions through revision-aware entity queries and `loadMultipleRevisions`,
then compares persisted source and target revision digests. The SQL restore
writes current tables only for the actual current default. Earlier defaults
and pending revisions go only to revision storage, preserving each revision's
stored `wasDefaultRevision()` metadata. Every restored revision is reloaded
with `loadRevision()`; current-only `load()` is not history verification.
The writer owns a transaction around the complete history, including when a
rollback inserts a record outside the migration runner. The runner's enclosing
transaction adds lineage to the same atomic record operation. PostgreSQL
sequences advance beyond all explicitly restored revision ids.
Batch size bounds entities; one complete entity history is loaded into memory
and restored in a single record transaction.

Run database updates before using the history-aware runner. Update 10001 adds
a nullable `history_manifest` column without rewriting earlier lineage or
inventing proof for old copies. Existing NULL, unsupported or malformed
manifests refuse with `history_unverified`. Changed source history, missing or
changed target revisions, or changed default selection refuse with
`history_drift`. An occupied revision identifier refuses with `history_conflict`.
A target without lineage is not adopted simply because its UUID matches.
An adapter implementing only `CopierInterface` remains loadable, but cannot
copy or qualify revisionable records until it implements and proves the history
contract; declaring the interface alone is not evidence of faithful storage.

For earlier incomplete copies, preserve the source, target and lineage. Take
a recoverable backup and inspect the source history against the existing target.
A reviewed host-specific repair must restore and verify all missing or divergent
revisions before establishing a manifest. This module does not provide an
automatic repair or manifest-backfill command. Do not delete real records or
lineage to force a rerun, and do not populate manifests from a current-only copy.

A source-history recheck before lineage commit detects an observed snapshot
change. It is not a write fence and does not make concurrent source writes safe.
Hosts must provide a qualified write boundary before a real transfer or cutover.
Revision-reference remapping and post-cutover historical replay onto existing
legacy records remain outside this copy contract. Passing reconciliation does
not authorize cutover.

A synthetic copier (`synthetic_item`) lives in
`modules/agency_migrate/tests/modules/agency_migrate_test` and is not a
production adapter. Fixture rows are in `tests/fixtures/migrate/records.json`.

## Authority

Hosts register a domain at `legacy`, then advance `legacy → shadow → agency →
removed` as the slice gates pass. Shadow means Agency is written in parallel
and must not be read as authority. Agency-authoritative requires legacy to be
read-only. Removed is a drain state and does not roll back. Duplicate domain
registration refuses rather than replacing an existing generation.

`agency_migrate_authority_state` is operational storage, independent of
configuration imports, runtime overrides and cached configuration objects.
Each transition locks and rereads its domain row, checks the legal edge again,
updates the expected version, and commits its event and outbox intent together.
Concurrent registration is arbitrated by the domain's unique key. Outbox
fingerprints include the operational generation. This boundary is verified on
MySQL and PostgreSQL; unavailable schema or unsupported database engines refuse
with `authority_storage_unavailable`.

Changing state is an operator action with a required reason. New installations
start with an empty operational table. Upgrade `agency_migrate_update_10002`
validates and imports existing authority configuration once, preserving state,
actor, reason and time while assigning the first operational version. A marker
in an explicitly SQL-backed key/value collection shares that import transaction.
The retained `agency_migrate.authority` configuration is deprecated migration
input; later configuration imports cannot register or change runtime authority.
Invalid historical entries stop the upgrade instead of being dropped.

The public `register()`, `get()`, `status()`, `transition()` and read-only
`assertTransition()` methods remain. `status()` includes each recorded version.
The internal service constructor now takes the writer-fence service instead of
the configuration factory. A recorded `agency` state imported from an older
installation does not certify that its writers are covered.

### Host writer coverage

Replace `agency_migrate.host_writer_fence` with a verified
`HostWriterFenceInterface` adapter before attempting an authoritative change.
`assertAvailable()` must check coverage without writes, locks or reservations.
`acquire()` runs after the operational authority row is locked and must exclude
all source and target writers until the **root** transaction commits or rolls
back. Releasing a savepoint cannot release that exclusion. The adapter must also
enforce the rejected side's write policy after authority commits and couple
normal Agency writes to the replay log in the same transaction.

An inventory must cover Entity API writes, direct SQL, queues, cron, imports,
independent processes and other database clients. Cooperative advisory locks or
an optimistic snapshot alone do not establish this coverage. No positive
production adapter ships here. The default refuses entering Agency authority,
removal and executing rollback; a read-only preflight cannot bypass that refusal.
The synthetic test double exercises transition/replay mechanics and is not
proof of host writer exclusion.

Executing rollback uses `withTransitionFence()` before loading the pending-write
snapshot and retains the boundary through replay, markers and authority change.
Dry runs perform only read-only validation and a snapshot count, with no fence
acquisition. A dry-run result is not a reservation or concurrency guarantee.

Keep older code that reads authority configuration away from a domain after
this upgrade. The retained configuration becomes stale as operational authority
changes; downgrading to such code is not an authority rollback. Use the verified
rollback service and a reviewed code/data recovery procedure instead.

## Rollback limits

- Replay writes current Agency field values onto legacy. It does not rebuild
  intermediate Agency revisions that existed only after cutover. Current
  translations are replayed, including addition and removal, with all field
  deltas and stored item properties.
- New Agency records without a lineage row are inserted onto legacy with the
  same integer id, UUID and complete target revision history. This insertion
  has its own atomic writer transaction. If an entity or revision identifier
  is already occupied, rollback refuses.
- Uncovered legacy or Agency writers make cutover and replay unsupported. The
  host must exclude them and enforce the recorded side; the default writer-fence
  service refuses these operations.
- Historical evidence is not rewound. Lineage events remain so either
  reference can be resolved.
- Authority `removed` has no rollback edge. Restoring from `removed` is out of
  scope.
- Host adapters must record post-cutover Agency writes through
  `agency_migrate.writes`. Unrecorded writes are not replayed.

Rehearse rollback on a staging clone before any production cutover. That
rehearsal is an operator procedure, not a function of this module.

## Commands

Drush entry points are read-only unless `--execute` is passed:

- `agency:migrate:run PLUGIN` — plan or copy
- `agency:migrate:resume RUN_ID` — plan or continue
- `agency:migrate:reconcile PLUGIN` — compare (read-only)
- `agency:migrate:report RUN_ID` — print a stored run receipt
- `agency:migrate:authority` — status; writes need `--execute` and `--reason`

Copy, resume, reconcile, report and rollback also require
`run agency migrate`. Authority changes require
`administer agency migrate authority`.

## Verification

Kernel tests under `tests/src/Kernel/Migrate` use `@group agency` and synthetic
records from `tests/fixtures/migrate`. They cover identity preservation,
occupied-id refusal, unauthorized denial, interrupted resume, second-run
no-op, unmapped markings, reconciliation exceptions, authority transitions and
rollback. Current-value proofs use multivalue formatted text, summaries, user
references and translated fields in real Drupal storage. They are not evidence
of complete revision history preservation, host writer coverage or a cutover.
Independent-process authority proofs cover competing registration/transition,
stale configuration imports and generation/event atomicity. Host-specific
writer fencing remains a separate acceptance requirement.
