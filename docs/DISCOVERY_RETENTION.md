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
complete inventory. Future removal must
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

## Removal still required

The preview does not delete records or establish that retention is enforced.
The next change must reauthorize and recheck a reviewed batch under the domain
writer lock. Removal must include every relationship revision, invitation and
entitlement. Shared people and identity bindings must remain while referenced;
minimal denial, replay and evidence records must remain effective.

Verify policy drift, concurrent writers, evidence failure, rollback, interrupted
commit and retry with independent PostgreSQL 16 connections. Run the supported
Drupal/PHP matrix. The host must separately qualify scheduling, encrypted-message
custody, attachments and backup expiry. Do not claim those stores were erased
because the domain row disappeared.
