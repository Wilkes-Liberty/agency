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
identity-preservation conditions per type are in [CRM transfer](CRM_TRANSFER.md).
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
  fails the run before any write and records a minimized `unmapped_marking`
  outbox intent. That intent carries plugin, source and target type ids, a
  count, hashed actor and the operator-visible reason. It does not list
  property names or write a run receipt, lineage or target rows.
- Historical Audit Chain rows are never rewritten. One minimized lineage event
  per batch is recorded through Agency's outbox and drained with Audit Chain's
  public `logKeyed()` contract so a verifier can resolve a legacy reference to
  its Agency counterpart. The unmapped-marking stop and a refused
  Agency-side load at non-agency authority use the same outbox.
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
- Rollback contains unqualified legacy journal replay. A declared domain with
  no pending journal writes can return to legacy authority only after every
  domain copier passes full reconciliation under the host writer fence.

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

## Full and sample reconciliation coverage

A default `compare($plugin_id)` inventories every current source, target and
lineage identity before qualifying the comparison. Equal totals alone do not
prove that the identifier sets, UUIDs, current revision ids and lineage mappings
match. Extra targets, orphaned or missing lineage and incomplete scans fail the
full report. Inventories use pages of at most 100 records; duplicate ids,
nonadvancing cursors, invalid records and scans inconsistent with counted totals
refuse qualification instead of looping or reporting partial work as complete.

Pagination bounds each read; the complete identity sets and field report still
consume memory proportional to the population. Entity batches discard cached
objects before loading persisted identities. These checks provide no concurrent
write guarantee. Real cutover comparisons must run under the host writer fence.

Full reports require `InventoryCopierInterface::loadTargetBatch()`, implemented
by `EntityCopierBase`. Existing custom copiers remain loadable, but full
reconciliation reports `coverage_unverified` until they can enumerate their
complete target population. Explicit source samples remain available through
`compare($plugin_id, $sample_ids)`. Duplicate sample ids are inspected once.

Reports retain `passed`, `counts`, `field_digests` and `exceptions`, and add
`coverage` and `full_passed`. Coverage identifies `full` versus `sample`, the
inspected and total source counts, and complete inventory fingerprints. For
compatibility, `passed()` on an explicit sample describes only the requested
comparison. Samples always have `coverage.complete: false` and
`full_passed: false`, including when the selected ids happen to cover every
source. Qualification callers must use `fullPassed()` or the JSON
`full_passed` property. The Drush reconciliation command requests full scope,
so its successful exit status requires complete passing coverage.

A directly constructed full report also checks source, target, lineage and
inspected (`sampled`) counts defensively. Empty exceptions alone no longer mean
a full report passed. Existing three-argument construction remains supported;
callers must supply accurate inspected counts and explicit inventory evidence.
Omitted coverage always has `complete: false`, even when all counts match.
A fourth coverage argument can identify verified full inventory evidence or an
explicitly limited sample.

Inventory rechecks detect observed population changes. They are not a database
snapshot or a host write fence, and a passing report does not authorize a real
transfer or an authority transition. Hosts still supply a qualified write
boundary and the operator's cutover decision.

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

Changing state is an operator action with a required reason. A new install
records `workforce` and `recruiting` at `legacy` through `hook_install`.
Upgrade `agency_migrate_update_10002` validates and imports existing authority
configuration once, preserving state, actor, reason and time while assigning
the first operational version. A marker in an explicitly SQL-backed key/value
collection shares that import transaction. Upgrade
`agency_migrate_update_10003` then inserts missing `workforce` and `recruiting`
rows at `legacy` without changing already recorded domains or their states.
Creation of those two domains in any other state is refused. The retained
`agency_migrate.authority` configuration is deprecated migration input; later
configuration imports cannot register or change runtime authority. Invalid
historical entries stop the upgrade instead of being dropped.

The public `register()`, `get()`, `status()`, `transition()` and read-only
`assertTransition()` methods remain. `status()` includes each recorded version.

Host adapters load Agency-side records through
`agency_migrate.authority_reader`. That reader is deny-first: only `agency`
authority returns a row. At `legacy`, `shadow` or `removed`, or when the
domain is unregistered, it refuses with the recorded state (or
`invalid_transition`) rather than `not_found`, records a minimized
`agency_read_refused` outbox intent, and does not open storage. Entity API
can still load planted Agency rows; hosts must consult the reader. The
intent carries domain, entity type, integer id, hashed actor and the
operator-visible reason. Drain uses the same `logKeyed()` path as
`unmapped_marking`.

The internal service constructor now takes the writer-fence service instead of
the configuration factory. A recorded `agency` state imported from an older
installation does not certify that its writers are covered.

Authorized host domain services can use the [post-cutover operation writer](POST_CUTOVER_OPERATIONS.md)
to commit local revisionable SQL entity changes with a retryable journal receipt.
Run update `agency_migrate_update_10004` before calling that service on an existing
installation. Earlier journal rows remain available in their original format.

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

A legacy adapter may call `AuthorityRegister::lockForLegacyWrite($connection,
$domain)` inside its existing transaction, before any business mutation. The
connection must be the register's exact database connection and the domain must
already be registered in `legacy` or `shadow`. The method locks and returns the
current generation until the root transaction ends. It refuses missing or
promoted authority and never registers a domain or changes its state. The caller
still authorizes the business operation; anonymous invitation acceptance does
not need authority-administration permission. Every participating mutation must
use that same transaction. This boundary covers only adapters that actually call
it; it does not qualify other writers or enable the default transition fence.

Executing rollback uses `withTransitionFence()` before loading the pending-write
snapshot and retains the boundary through replay, markers and authority change.
Dry runs perform only read-only validation and a snapshot count, with no fence
acquisition. A dry-run result is not a reservation or concurrency guarantee.

Keep older code that reads authority configuration away from a domain after
this upgrade. The retained configuration becomes stale as operational authority
changes; downgrading to such code is not an authority rollback. Use the verified
rollback service and a reviewed code/data recovery procedure instead.

## Qualified rollback

The rollback service returns authority to legacy after whole-domain
reconciliation. With an empty journal, every registered copier must pass its
full reconciliation. Opted-in rollback copiers also compare their declared
access cases. Earlier copiers use the existing empty-journal reconciliation
contract. With post-cutover operations, the service checks and
replays the durable create, update and delete envelopes in write-id order.
The original four-argument `PostCutoverWriteLog::record()` still writes legacy
records. Those records lack operation and history evidence and cannot qualify
for replay. Their original rows remain available for recovery review.

The host must explicitly list every expected copier id for a domain in the
nonsecret Settings key `agency_migrate_copier_domains`. Each copier also declares
that domain in its optional `domains` annotation/attribute metadata. Both lists
must agree exactly. Missing registries, missing or unexpected copiers and empty
metadata remain unqualified. Two copiers for the same target within the domain
refuse with `rollback_ambiguous`; discovery order never selects authority.
`copierForTarget()` remains callable but now requires an explicit domain for a
qualified result. No domain membership is inferred from entity-type names.

For nonempty replay, each domain copier must implement `RollbackCopierInterface`
for its reviewed equal-layout pair and return its access-comparison principals.
Both entity storages must explicitly participate on the rollback SQL connection.
Qualification compares exact typed revision and translation snapshots, integer
IDs, UUIDs, original lineage, references and the final Agency state. A create
must prove its identity is new. An update preserves the complete old history.
A delete needs the exact owned preimage and records a tombstone that points to
the retained delete envelope. Side-by-side contact or workforce layouts need
their own reviewed compatibility before qualification.

The service pairs each operation with its retained outbox intent, including
delivered intents, and checks the pair in both directions. Preserve these
records together. Losing a create and its later delete can leave the current
populations looking equal while their delivered intents still prove a gap.
Every pending before and after snapshot must still match its journal
qualification fingerprint under the current host contract.

Preview checks the known data without acquiring a fence. Execution repeats
preflight inside one root SQL transaction while holding the current Agency
generation and the host writer fence. After replay it verifies every domain
copier and appends effective provenance, tombstones, replay markers, authority
evidence and a durable handover receipt in that transaction. Original lineage,
copy manifests and journal payloads retain their historical meaning.

Ordinary reconciliation and subsequent rollback use the effective history from
sealed receipts. A copied record keeps its original copy manifest. An identity
created after cutover keeps its original create operation. A delete stays
represented by its retained tombstone and the verified absence of the identity
and preimage revision IDs from both sides' mapped SQL tables. If an original
copy row, receipt, journal endpoint or provenance record changes unexpectedly,
the effective history cannot qualify reconciliation.

Run `agency:migrate:authority --domain=example --set=legacy --rollback
--reason='Reviewed rollback'` to preview. A nonempty execution also needs
`--execute` and `--generation` set to the previewed Agency generation. Repeat
the same generation and reason after `rollback_commit_uncertain` to recover the
committed receipt or safely retry. A stale generation refuses after further
authority changes. Authority `removed` has no rollback edge. Production replay
requires a qualified host fence and an operator-controlled rehearsal.

Backup restoration is not journal replay. Passing `--restore-from-backup` to
`agency:migrate:authority`, or the fifth `restore_from_backup` argument to
`RollbackService::rollback()`, refuses with `restore_refused` before any
registration, transition or replay. Authorized requests append a minimized
durable refusal intent, including in preview mode. The intent contains only a
fixed reason, a registered domain when known, and event identity/time; operator
reasons and backup locations are not recorded. A caller-owned transaction
refuses before that append, and unavailable evidence storage returns a fixed
failure instead of claiming an evidenced refusal. Backup recovery remains a
separate reviewed procedure.

### Optional host resources

A host can register a local SQL participant for additional resources that
need to return when a domain hands authority back. The participant
implements `Drupal\agency_migrate\RollbackDispositionInterface` and uses
the exact connection supplied by Agency. It runs within the existing
authority and host writer fence for cutover and rollback. Each service has
one `agency_migrate.rollback_disposition` tag with a stable `provider_id`
and canonical `domain`. Service configuration alone cannot enroll the
domain or change its authority.

An authority administrator calls
`AuthorityRegister::requireDisposition($domain, $provider_id)` while the
domain is in legacy or shadow. Enrollment is durable, and repeating the same
enrollment in either state is safe. Enrollment cannot be changed or cleared.
Before cutover advances authority, the declared provider qualifies the
prospective generation and Agency records its immutable binding. Missing or
mismatched qualification prevents the transition.

A dry run can return a separate `disposition_pending` count for a bound
generation. It reserves nothing. Execution requires the expected generation
when either the entity journal or the additional resource plan has pending
work. It builds a fresh plan after entity preflight under the existing locks,
applies and verifies it after successful entity replay, and seals the private
host receipt digest before returning authority. The public `replayed` count
covers entities. If a participant ends the original transaction, Agency
refuses to seal the receipt or advance authority. An interrupted rollback
with an early SQL commit reports `rollback_commit_uncertain`.

The provider owns its immutable receipt and verifies it without using live
resource counts when an operator reconciles an uncertain handover. A missing
provider, binding, incomplete receipt, or mismatched proof prevents
confirmation. Keep the provider available for the lifetime of the retained
receipt. Update 10006 adds nullable enrollment and proof fields and a
generation binding table. It preserves earlier receipts without inferring
host resource history.

## Removal and retained records

Routine uninstall refuses while lineage, run receipts, evidence intents, write
journals, rollback receipts and provenance, authority events or authority state
contain records. Delivered evidence
remains protected. Missing or unreadable recovery storage also refuses removal;
empty installations remain removable. Stop writers and complete an explicit
retention and recovery procedure before retiring a used installation. There is
no automatic purge, and this check does not replace a backup, restore rehearsal
or writer fence.

## Commands

Drush entry points are read-only unless `--execute` is passed, except that an
authorized `--restore-from-backup` request always records a refusal intent:

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


### Snapshot history merge helper

`IdentityPreservingWriter::captureHistory()` captures every persisted revision
of a revisionable content entity as typed scalar, null and array values.
Version 1 records the source type, integer ID, UUID, selected default revision,
stored field/property layout, revision count and a list of revisions. Each
revision carries its original ID, runtime default flag, default language,
translation language inventory and all stored field properties. Numeric strings
in string properties remain strings. Objects and nonfinite numbers refuse.

`validateHistorySnapshot($snapshot, $target_type)` validates this reconstruction
payload without loading the original source or changing storage. It checks exact
identity, layout, fields, properties, revision IDs and default selection.
Duplicate IDs, inconsistent counts and missing translations refuse. For bundled
content, the actual target bundle key is authoritative. Every captured revision
and translation must carry the same nonempty bundle value, which must exist in
target configuration. Only that bundle is supplied when deriving the target
layout; no bundle remapping or source entity lookup is inferred. These
checks detect malformed or truncated payloads. A self-consistent snapshot does
not establish that its author captured every source revision; a hash alone
establishes neither provenance nor authentication.

`mergeHistory($snapshot, $target_type)` merges the captured revisions into an
existing same-identity entity or restores an absent entity. Existing target-only
revisions remain. An overlapping ID must have identical stored fields, including
historical translations, revision author, time, log and stored default metadata.
A conflicting overlap, another entity owning a revision ID, or ID/UUID mismatch
refuses before imports. Runtime default flags may differ as current selection
changes; stored historical default metadata must match. The captured default
must have been a stored default, and may be older than a later pending revision.

The merge uses a nested transaction and verifies the complete union, current
selection, and values exposed by an ordinary entity load. On Drupal 10, selecting
an existing default also refreshes its current dedicated field tables from the
selected revision. The merge invalidates entity and revision caches after
failure. It does not reload live source content during replay. Repeated identical
merges are idempotent; later automatic IDs advance beyond imported IDs.

This additive helper does not qualify journal records, change lineage, authorize
writers or implement nonempty rollback. Existing four-argument journal records
remain unqualified. Qualified replay still requires a fresh capture inside the
actual writer boundary, durable operation evidence and final complete domain
reconciliation under the authority fence.
