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
  integer id, UUID, current revision id, created/changed timestamps, revision
  author and revision log. Every non-computed property, ordered field delta,
  empty field and current translation is copied for matching field layouts.
- The target id must not already belong to a different UUID.
- Each copied pair is stored in a bidirectional lineage map with a content
  digest and the run that wrote it.
- Runs are bounded batches. Each record shares a transaction with its lineage
  row, so a crash mid-batch leaves no partial target. Resume continues from the
  last committed source id without duplicating copies. A second complete run is
  a no-op.
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
- An authority register holds one state per domain: `legacy`, `shadow`,
  `agency`, `removed`. Transitions move only forward, except the rollback edge
  `agency → legacy`. Each change requires `administer agency migrate authority`
  and stores an actor hash plus reason as evidence.
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
lineage digests are not comparable to this encoding. Existing lineage rows still cause a rerun to skip the pair:
reconcile existing copies before considering them faithful. This change does
not repair values lost by an earlier copy.

This preserves the current loaded revision only. Earlier revisions, pending
non-default revisions, historical translation changes and revision-reference
remapping still require separate work. A passing current-value reconciliation
does not establish complete history preservation or authorize cutover.

A synthetic copier (`synthetic_item`) lives in
`modules/agency_migrate/tests/modules/agency_migrate_test` and is not a
production adapter. Fixture rows are in `tests/fixtures/migrate/records.json`.

## Authority

Hosts register a domain at `legacy`, then advance `legacy → shadow → agency →
removed` as the slice gates pass. Shadow means Agency is written in parallel
and must not be read as authority. Agency-authoritative means legacy is
read-only for that domain. Removed is a drain state and does not roll back.

Changing state is an operator action with a required reason. The default
configuration is an empty domain map; no domain is registered by installing
the module.

## Rollback limits

- Replay writes current Agency field values onto legacy. It does not rebuild
  intermediate Agency revisions that existed only after cutover. Current
  translations are replayed, including addition and removal, with all field
  deltas and stored item properties.
- New Agency records without a lineage row are inserted onto legacy with the
  same integer id and UUID. If that id is taken by a different UUID, rollback
  refuses.
- Concurrent legacy writes during the Agency-authoritative window are
  overwritten by replay.
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
of complete revision history preservation or a host cutover.
