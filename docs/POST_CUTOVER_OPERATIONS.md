# Post-cutover operations

`agency_migrate.post_cutover_writer` provides value-based `create`, `update` and
`delete` operations for a declared Agency target type. Each call supplies the
domain, expected authority generation and a new canonical UUID operation ID.
Updates and deletes also supply the integer entity ID and UUID. The calling
domain service must authorize the business operation before calling this API.
The writer supplies no route or permission grant.

The initial implementation supports revisionable local SQL entities. A host
must explicitly select `OperationSqlContentEntityStorage`, or provide a storage
handler implementing `OperationStorageParticipantInterface` with its actual
mutation connection. Ordinary SQL interface membership does not qualify a
custom handler. The storage connection must be the exact writer connection.
The explicit copier-domain registry must authorize that target type.

The host must replace `agency_migrate.operation_journal_policy` with an
`OperationJournalPolicyInterface` adapter. The default refuses new mutations.
Qualify each exact domain, target entity type and bundle. The declaration must
mark every stored field item property, including identity and revision metadata
and fields that are empty on the current record. Use `public`, `internal`,
`confidential`, `personal` or `financial`. Unmarked, unknown and secret
properties refuse. A source copier's markings describe the copied source and
cannot qualify a target journal.

The host must verify that the journal gives the target's data equivalent access
controls, recovery protections and retention, including older revisions,
translations, backups, replicas, exports and the handling of deleted records.
The journal stores full typed values in SQL. Qualify the actual storage and
recovery paths before enabling writes and version the declaration's contract ID
when the stored layout, verified controls or scope change. The package ships a positive
declaration only for disposable synthetic tests.

Every new mutation owns its root transaction and refuses an existing outer
transaction. It locks fresh operational authority and requires Agency state at
the caller's expected generation before loading entities. That authority lock
remains held through entity mutation, journal insertion, required evidence
insertion and root commit. Independent writers that bypass this API still need
host coverage; this service does not establish that coverage.

The writer captures complete typed persisted history itself. Callers cannot
supply entities, history snapshots or save callbacks. Create allocates identity
only on the first attempt. Update forces a new revision and verifies that all
old revision IDs and stored fields remain unchanged. Runtime default-selection
flags may change; stored revision metadata must not. Delete retains the complete
preimage after verifying the record and its revisions are absent. Entity caches
are cleared when the mutation attempt exits.

Qualification checks the complete stored preimage before update or delete and
checks the candidate layout before save. Before journaling, the writer rechecks
the reloaded history after save or the retained preimage after delete. A changed
bundle, stored property coverage or policy rolls the operation back. A refusal
reports `operation_unqualified` without field values or provider details.

Entity mutation, immutable version 2 operation envelope and minimized evidence
intent commit together. The envelope stores typed before/after histories and a
fingerprint of the approved domain, type, bundle, stored layout, markings and
contract ID.
The decoder checks payload identity against the indexed SQL columns. These
fields are consistency checks. The four-argument
`PostCutoverWriteLog::record()` format remains readable as an unqualified
historical record. Unqualified version 1 typed envelopes refuse automated
receipt recovery and replay with `operation_legacy_unqualified`. Update 10004
adds nullable columns. Existing rows retain their recorded formats. Only the
replay marker is mutable through the existing log API.

Operation IDs are unique within a domain across generations. A deterministic
request hash includes the original generation, kind, target identity and typed
values, before generated IDs, UUIDs or timestamps exist. Map ordering does not
change the request; list ordering and scalar types do. Reusing an operation ID
with changed values, kind, identity or generation refuses.

An exact retry returns the original scalar historical receipt, even after the
record was deleted or authority moved away. This first receipt lookup occurs
outside a new transaction so MySQL cannot retain a stale snapshot while waiting
for authority. After locking the current authority row, the writer checks again for a
committed receipt before qualifying authority for a new mutation. This covers a
concurrent miss followed by a transition. Historical receipt lookup leaves live
entities unread. An exact version 2 receipt retry also works after the host
withdraws qualification for new mutations. Any future replay must match each
stored fingerprint against current qualification before writing.

The service returns `completed` only after root commit. A lost commit
acknowledgement or a failed final cache reset after commit reports
`operation_commit_uncertain` and withholds the receipt. A cleanup failure
includes the secondary reason `operation_cache_cleanup_failed` and preserves
any existing refusal reason. Do not automatically mint another operation ID. Resolve the original operation
using its exact request; changed-generation retries cannot perform another
mutation. SQL and provider failures never include record values in refusal
messages or evidence metadata. The migration outbox delivers required intents
after business commit through its existing bounded drain.

This journal does not enable nonempty rollback. Pending operation rows continue
to refuse rollback until ordered replay, lineage, deletion handling and complete
reconciliation are implemented together.
