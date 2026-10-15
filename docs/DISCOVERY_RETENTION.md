# Discovery retention

Implementation record: [Agency issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

The current tenant policy supplies the retention period. A relationship's
recorded terminal time supplies its start. Changing policy can move the deadline;
the existing summary contract also reads current policy. A removal command must
compare its reviewed preview with fresh policy and records before deleting.
Summary expiry is a separate access decision and is never removal evidence.

## Read-only preview

`agency_staff.retention->preview($after_id, $limit)` requires a fresh, eligible
coordinator with the separate explicit `maintain agency discovery retention`
permission. No permission is granted by installation. It exposes no route,
Drush command or MCP tool.

A page scans up to 100 withdrawn/closed relationships, ordered by internal ID.
Start at cursor `0`; use `next_after_id` until it is null. The default page size
is 20. Records contain only relationship UUID, state, version, deadline and
eligibility (`waiting`, `due` or `review_required`). The report includes its
evaluation time and policy period. There is no personal or conversation text.

Pages are advisory reads, not a locked inventory or deletion authorization.
An older active relationship can close after its ID has been passed and be
missed by that traversal. Policy and records can also change between pages.
Reconcile changes or start a fresh scan; reaching the last page never proves a
complete inventory. Removal must
recheck the selected records, effective policy and authority under the writer
lock. A due row is still present until an independently verified removal.

## Preview failure cases

These controls are specified before implementation:

- An ordinary coordinator, blocked account, stale role or ineligible host
  identity cannot inspect the maintenance inventory. Require the separate
  explicit `maintain agency discovery retention` permission on the fresh user.
- A missing or malformed retention period refuses the whole preview. Never
  infer a default period or a close date from an entity's last edit.
- Only withdrawn and closed relationships are scanned. A missing, future or
  malformed terminal timestamp is held for review. Active and moved-forward
  relationships are excluded.
- A record is not due before its deadline and is due at the deadline. A policy
  change must be visible on the next preview.
- Pagination is bounded and deterministic. A preview contains identifiers and
  timing facts, never conversation text, names, contacts or identity claims.
- Preview success and refusal leave business entities, revisions, evidence and
  idempotency records unchanged. The operation cannot run inside another
  caller's transaction.

## Removal service

`agency_staff.retention_removal->prepare($relationship_uuid)` prepares one
bounded removal. `remove($relationship_uuid, $plan_sha256, $idempotency_key)`
executes that exact plan after rechecking current authority and records.
Both methods require a fresh eligible coordinator with the separate restricted
`remove expired agency discovery` permission. Installation grants no permission.
There is no route, Drush command, MCP tool or scheduler.

Preparation returns `prepared` with a keyed plan digest, relationship UUID,
deadline, revision/child counts and person/principal actions. It returns no
personal data. Execution returns `committed` with its minimal receipt,
`refused` with a reason, or `uncertain` when commit cannot be confirmed.
The host must retain its idempotency key and reconcile an uncertain result by
retrying the exact request with fresh authority. Never infer deletion from a
missing response. A different key cannot recover the original receipt.

This service removes package-owned records only. The host must separately
qualify scheduling, encrypted-message custody, attachments and backup expiry.
Do not claim those stores were erased because the domain row disappeared.

## Policy prerequisite

The internal `agency_staff.retention_policy` service captures stored and
effective policy within a caller-owned transaction. Execution callers request
the row lock; advisory preparation may read without it. The service compares
the authoritative SQL row with the factory's unoverridden data before using
effective overrides. It refuses missing policy, stale cached data and an
unsupported storage graph. It grants no permission and exposes no route.
Only the tested MySQL and PostgreSQL drivers are eligible for this guard.

This guard does not implement removal. The domain store acquires its writer
lock before any ordinary read, so a waiting writer cannot establish an old
InnoDB snapshot before its predecessor commits. The deletion path must read
its inventory inside that boundary. Independent-writer controls cover commit
and rollback on the supported database matrix.

## Removal boundaries and failure cases

The service prepares one bounded removal at a time. A separate restricted
permission authorizes preparation and execution; the preview permission alone
does not authorize deletion. The host must provide an eligible maintenance
operator and keep these services off public routes and MCP.

Preparation returns an opaque digest, the selected relationship UUID, its
deadline and removal/preservation counts. It reads current effective policy,
the terminal record, every revision and its invitations and entitlements.
It also checks all references to the associated person and principal. Bound
work to one relationship, 1,000 revisions and 100 records of each child type;
larger or malformed inventories require review. The digest uses the site hash salt as an HMAC key; an absent or short key
refuses preparation. No participant content leaves the service. Preparation is read-only and gives no execution authority.

Reference scans are bounded too: at most 100 references per entity type and
1,000 retained revision references. Include non-default revisions of other
relationships, orphan invitations/entitlements and other principal-to-person
bindings. Overflow or incomplete reads require review, never an assumption
that a record is unshared.

Execution takes the exact relationship, prepared digest and idempotency key.
It reauthorizes under the existing domain writer lock, reloads effective policy
and records, and compares the prepared digest. A changed deadline, revision,
reference or binding refuses the operation. The current time must reach the
deadline; future or missing terminal times are never inferred.

For the default Drupal database configuration backend, hold its authoritative
`agency.settings` row lock and compare uncached stored data with the config
factory's underlying stored data before using effective overrides. A factory
reset alone does not evict cached storage. Bind stored and effective policy
into the plan. A late container compiler check must verify the exact default
database/storage/factory chain, table, collection and connection; alternate
wiring leaves removal unavailable without breaking the site. Runtime override
sources require host deployment containment throughout execution. This does
not claim to contain arbitrary privileged PHP or out-of-contract raw SQL.

Use the Entity API to remove the relationship, all its revisions, invitations
and entitlements. Delete an active principal only when no other relationship
or child record references it. Delete its thin person resolver only when no
remaining relationship or binding references that person. Preserve shared
records. An unshared inactive principal must retain its opaque principal key
and denied status, while removing its direct identity/contact fields, so
purging personal details cannot make a blocked principal eligible again. An
active binding with malformed identity claims or a mismatched principal key
requires review; its existing denial must not disappear through deletion.

Record the required removal intent and minimal idempotent receipt in the same
transaction. Retain older intents and evidence. An exact authorized retry may
return its committed receipt without recreating records. A different key never
manufactures a second removal. An uncertain commit remains uncertain until a
fresh transaction observes the receipt; rollback or a missing response is not
proof of deletion.

The domain boundary releases an ended failed transaction before recording a
separate observation. Otherwise that observation's successful transaction can
overwrite the outcome seen by the failed root's callbacks. A transaction still
active after cleanup is uncertain and cannot start an observation transaction.

Failure controls, specified before removal implementation:

- Deny ordinary/preview-only permission, stale role, blocked account and host
  eligibility loss before reading an inventory or replay receipt.
- Refuse active, moved-forward, not-yet-due, unknown-history, malformed-policy,
  over-budget and changed-plan requests without deleting any record.
- Prove exact deadline behavior and policy changes in both directions. A
  changed current policy requires a new preparation, including an equivalent
  period expressed differently.
- Verify complete revision/child removal, shared-record preservation, unshared
  personal-data removal and preservation of an inactive principal's denial.
- Force failure after delete and during required evidence/receipt persistence;
  independent connections must see either the whole committed result or none.
- Race another domain writer, a policy change and a new shared reference.
  Revalidation must refuse stale authority and stale inventories.
- Verify same-key replay after removal and lost acknowledgement, altered-key
  refusal, no restoration through old invitation/command retries, and continued
  delivery/verifiability of retained evidence.

This design covers package-owned data only. It creates no scheduler and does
not delete host delivery ciphertext, attachments, identity-provider accounts,
audit archives or backups. Their separate removal/recovery procedures must
precede any operator claim that the complete retention policy is enforced.

## Explicit PostgreSQL host fault qualification

The normal supported-version matrix covers statement failure, atomic rollback,
concurrent references/policy and retry after a lost application response. That
last control discards the response after a real commit; it does not simulate a
lost PDO COMMIT acknowledgement.

`tests/src/Qualification/RetentionCommitFaultTest.php` is a separate, explicitly
invoked host test for a real deferred PostgreSQL COMMIT rejection. It requires
Drupal core's ended-transaction cleanup fix from
[core issue 3584465](https://www.drupal.org/project/drupal/issues/3584465).
It is outside the ordinary Kernel suite and is not silently skipped. Run only
against an isolated synthetic PostgreSQL 16 test database in the qualified host:

```sh
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --filter testDeferredCommitRejection \
  web/modules/contrib/agency/tests/src/Qualification/RetentionCommitFaultTest.php
```

Record the exact core, patch and Agency commits with the result. Independent
readback must show unchanged business rows and no removal receipt/event, the
failed root callback must receive false, and a subsequent transaction and
process shutdown must complete normally. Passing this host test does not
qualify unpatched core versions or prove backup/ciphertext erasure.
