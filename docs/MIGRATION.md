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

## What this module does

Observed in the kernel suite against synthetic entity types:

- A copier plugin copies one source type into one target type with the same
  integer id, UUID, current revision id, created/changed timestamps, revision
  author and revision log.
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

## Copier plugins

Implement `Drupal\agency_migrate\Plugin\CopierInterface`, typically by
extending `EntityCopierBase`. Discoverable under
`Plugin/AgencyMigrate/Copier` with the `MigrateCopier` attribute (Drupal 11)
and annotation (Drupal 10). Declare `source_type` and `target_type`. Provide:

- `propertyMarkings()` for every copied content property
- `referencePropertyNames()` for integer references checked in reconciliation
- `accessDecision($principal, $side, $id)` for parity checks

The runner does not infer types from the ontology document. When ontology v0
lists Agency object types, each listed legacy source gets its own plugin.

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
  intermediate Agency revisions that existed only after cutover.
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
rollback. They are not evidence of a host cutover.
