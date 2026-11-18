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

Entity mutation, immutable version 1 operation envelope and minimized evidence
intent commit together. Envelopes include operation kind, domain, generation,
operation ID, target identity and complete before/after histories. The decoder
checks payload identity against the indexed SQL columns. These fields are
consistency checks, not signatures or authentication. The public four-argument
`PostCutoverWriteLog::record()` remains readable as an unqualified historical
format. Update 10004 adds nullable fields and does not invent envelopes for old
rows. Only the replay marker is mutable through the existing log API.

Operation IDs are unique within a domain across generations. A deterministic
request hash includes the original generation, kind, target identity and typed
values, before generated IDs, UUIDs or timestamps exist. Map ordering does not
change the request; list ordering and scalar types do. Reusing an operation ID
with changed values, kind, identity or generation refuses.

An exact retry returns the original scalar historical receipt, even after the
record was deleted or authority moved away. This first receipt lookup occurs
outside a new transaction so MySQL cannot retain a stale snapshot while waiting
for authority. A second locking/current lookup after the authority lock handles
concurrent misses. No historical receipt lookup loads the current entity.

The service returns `completed` only after root commit. A lost commit
acknowledgement reports `operation_commit_uncertain`, with no success receipt.
Do not automatically mint another operation ID. Resolve the original operation
using its exact request; changed-generation retries cannot perform another
mutation. SQL and provider failures never include record values in refusal
messages or evidence metadata. The migration outbox delivers required intents
after business commit through its existing bounded drain.

This journal does not enable nonempty rollback. Pending operation rows continue
to refuse rollback until ordered replay, lineage, deletion handling and complete
reconciliation are implemented together.
