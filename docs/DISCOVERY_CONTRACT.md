# Discovery service contract

Canonical feature: [Drupal.org issue #3629756](https://www.drupal.org/project/agency/issues/3629756).

Status: provisional implementation contract, revision 1. This is the initial
invitation-only Discovery relationship. Host adapters and participant interfaces
can build against these service boundaries and synthetic examples. Release
requires the domain, security and experience checks in
[the failure matrix](DISCOVERY_VERIFICATION.md). This document is not evidence of
a deployed endpoint, real invitation or approved tenant policy.

## Scope and authority

`agency_staff` owns the relationship and its evidence outbox. Five internal
Drupal content entities hold the person resolver, principal binding,
revisionable relationship, invitation generation and entitlement. They have no
public entity routes, forms, JSON:API or GraphQL resources. Reads expose explicit
projections; Drupal entity access denies direct access, including administrator
bypass. The host owns authentication, transport, CSRF, rate limiting and its
translation catalog. No discovery command creates a user, role, Profile,
employment classification, roster membership or assignment.

The host implements `IdentityProviderInterface`: a fresh authenticated principal
is an exact issuer/subject pair, with current account status. It also resolves
the intended principal before an invitation can be prepared and attests whether
the effective coordinator credential is an eligible human credential. The
default provider refuses all authority. Contact data never selects or merges
a principal. Do not deserialize these trusted provider results from request
parameters. Identity errors happen before writes. The host must invalidate its
identity verdict on account deactivation and rebinding.

Coordinator operations additionally require Field Guard's explicit permission
check and assignment to the relationship. An administrator bypass is
insufficient. CRM, staff, client, partner, pending and machine credentials do
not acquire authority through role names. The host eligibility adapter must
reject disallowed mixed credentials too; it must not reload a limited token as
its owner's unrestricted account. This module supplies no such host adapter.

## Reads

`RelationshipReads::forActor(?string $relationshipId = NULL): array` returns a
single authorized workspace, or a list of authorized workspace contexts.
`RelationshipReads::summary(string $relationshipId): array` is a separate
capability. `RelationshipReads::queue(): array` is coordinator-only and scoped
to the current owner. `RelationshipReads::summaries(): array` separately lists
currently authorized past conversations as opaque `id`, `kind`, and the curated
`summary`; clients need not retain identifiers from earlier sessions. Reads
revalidate identity, binding and entitlement on each
call and bypass Drupal's entity memory cache. No shared response cache is safe:
host responses must use `Cache-Control: private, no-store`.

Workspace fields:

| Field | Contract |
| --- | --- |
| `id`, `kind` | Opaque relationship UUID; `kind` is `discovery`. |
| `state` | `invited`, `exploring`, `prepared`, `paused`, `withdrawn`, `closed`, `moved_forward`; only entitled workspaces are projected. |
| `waiting_on` | Stored `participant` or `agency`; `display_waiting_on` may be `nobody` for a participant pause. |
| `agency_name` | Configured organization display name; missing name refuses the workspace. |
| `owner` | Display name and typed contact route. Never a Drupal user identifier. |
| `owner_changed_at` | Timestamp of a reassignment notice, or null. |
| `next_action` | At most one: `code`, `summary`, `why_it_helps`, `side`, absolute `expected_by`, and `optional`. Coordinator text has null code. A server-generated `agency_response` code has null text and is localized by the host catalog. |
| `agency_late` | Server-derived boolean; never derived for participant-owned actions. |
| `updated_at`, `version` | Server time and monotonically increasing relationship version. |
| `pause_origin` | `participant`, `agency`, or null. Participant cannot resume an agency pause. |
| `proposal` | Curated target purpose/message awaiting explicit participant consent, or null. |
| `agreed_next_step` | Accepted target purpose/message, or null; never a new access grant. |
| `permitted_actions` | Server-calculated command identifiers; unknown identifiers are ignored by clients. |

Missing, unrelated, revoked, suspended, deactivated and rebound contexts return
the same `not_found` refusal without any relationship fields. List reads omit
them. Public adapters use the same status, shape and cache policy for every
such case. Internal entity objects, principal IDs, notes from other records,
raw evidence and outbox rows never appear in participant projections.
Identity is resolved before protected identifier lookup, including during an
outage, so missing and existing identifiers have the same observable response.

The restricted `recover agency discovery ownership` permission, in addition to
eligible human coordination authority, exposes ownerless contexts in the queue.
An owner is unavailable when missing, blocked or no longer explicitly eligible.
Recovery permits only an owner-only `setNextAction` payload; it preserves state,
pause origin and current obligations. Ordinary mutations require assignment.
An active eligible owner's contexts cannot be taken over through this permission.
Queue rows carry `owner_unavailable`, `last_agency_action_at` and captured notices.

Application/recruiting is a later relationship kind. Submitted, review,
interview and decision labels are not Discovery enum values. Future application
claims may reuse the invitation ceremony, but still require separately verified
single-use ownership proof, wrong-account recovery and concurrency protection.
An application's email/contact point is never its authenticated subject.

## Commands and results

`RelationshipCommands` exposes `startDiscovery`, `setNextAction`, `respond`,
`pauseRelationship`, `resumeRelationship`, `withdrawRelationship`,
`revokeInvitation`, `suspendAccess`, `close` and `moveForward`.
`InvitationCommands` exposes `issue`, `resend`, `accept` and `revoke`.
Invitation lifecycle is separate from relationship state.

Every command takes an idempotency key for one user intent. Relationship
commands also take a relationship UUID and expected version. The effective
actor is resolved by the provider/current session, never by caller IDs. Command
methods own a root transaction; do not call them inside a caller transaction.
Returning `committed` means the local domain transaction committed, not that
the evidence has been chained. Outbox inserts themselves support caller
transactions/savepoints. Host mutation endpoints must be POST-only with CSRF
or equivalent authenticated BFF protection. Acceptance additionally checks the
request method within the domain service.

| Outcome | Meaning and recovery |
| --- | --- |
| `committed` | Minimal receipt plus the new projection when workspace authority remains. Terminal commands return no workspace. |
| `refused` | No command mutation committed; stable reason such as `not_found`, `invalid`, `identity_unavailable`, `policy_unavailable`, or `recording_unavailable`. Preserve the draft where appropriate. |
| `conflict` | Ordinary command version is stale. Re-read the authorized model; preserve draft and intent key. Never choose a transition locally. |
| `uncertain` | Commit acknowledgment or transport outcome is unknown. Reconcile before claiming success or retrying. A transport timeout alone never proves rollback. |

The same key and identical command payload replay the original minimal receipt
while the actor still has the authority the command requires. A different
payload with the same key is refused. A replay never returns a cached workspace:
any accompanying projection is freshly authorized. **Terminal participant
retries are an explicit exception:** after withdrawal the entitlement no longer
authorizes the command, so even the original key returns `not_found`. A later
suspension also prevents replay. No replay recreates or extends a grant.
Existing receipts are located after authorization and before validation for a
new intent: an elapsed action deadline or subsequent policy change does not
invalidate a receipt. New intents still require current policy and contact checks.

| Command | Inputs and effect |
| --- | --- |
| `startDiscovery` | Coordinator supplies display name, typed contact, owner/contact, first next action. Resolve intended issuer/subject first. Creates an `invited` context, pending entitlement and thin person/principal rows; no delivery. |
| `issue` / `resend` | Coordinator captures an invitation, with policy-derived expiry and a cryptographically random bearer credential stored only as a hash. Resend supersedes all earlier active generations. Capture is not sending. |
| `accept` | POST plus token, current authenticated exact intended issuer/subject and key. Lock, verify expiry/generation/pending grant, consume, activate and record in one transaction. Used token cannot bind again; an already bound subject may separately open its existing workspace. |
| `setNextAction` | Assigned coordinator sets summary, purpose, owning side, date and `optional`. Optional `owner` contains a proposed existing `uid` and typed `contact`; the server validates eligibility and explicit permission before reassignment and records `owner_changed_at`. Owning side determines `waiting_on`. No required participant task in Discovery. |
| `respond` | Participant choice and optional bounded plain-text note. `not_now` records a reply without pausing. `decline_proposal` clears only the proposal and leaves the conversation exploring, waiting on the agency. |
| `pauseRelationship` | Participant pause clears participant obligations and retains workspace access. Agency pause requires an agency-owned update date. |
| `resumeRelationship` | Only the pausing side can resume. Server resumes to exploring, waiting on agency, with policy-derived acknowledgement date. |
| `moveForward` | Coordinator proposes target purpose and bounded message; state stays exploring. Participant explicitly accepts the current proposal/version before moved_forward. No role, classification, new relationship or external access is created. |
| `withdrawRelationship` | Participant Stop applies to the latest authorized active state despite a stale expected version. Denial plus immutable intent commit together. |
| `revokeInvitation` / `revoke` | Assigned coordinator revokes generations and entitlement, including summary access, despite stale expected version. |
| `suspendAccess` | Assigned, explicitly authorized coordinator removes pending/workspace/summary authority immediately. Also works after withdrawal or close. |
| `close` | Coordinator supplies configured reason category, bounded participant message and contact route. Positive transaction; requires durable local evidence, records separate captured closing-message obligation. |

## Terminal summaries and lost responses

Summary-only access for withdrawn/closed is specified as a separately
authorized, expiring entitlement, subject to security review before release.
It requires configured summary-window and retention information; there are no
default durations. It is changed in the same transaction that denies workspace
access. Failure to prepare a summary during withdrawal chooses denied/no-summary;
it must not prevent Stop. Failure to persist the denial **and mandatory intent**
is different: neither commits, and there is no successful Stop receipt.

Summary fields are only terminal state, effective date, bounded participant
message for closed, retained-information statement and retention period,
contact route, and `readable_until`. No actions, next action, audit status or
internal identifiers are projected. Closing a visibility window does not
delete retained records. Expiry, revocation, suspension, account deactivation
and subject rebinding immediately deny summary reads. Help is a contact route,
not a terminal domain mutation. Authorized operator suspension remains possible.

After an uncertain withdrawal, a separately authorized summary showing
`withdrawn` confirms that state, without asserting evidence-delivery status.
If it is unavailable/not-found, the result stays unconfirmed. A failed Stop
followed by suspension is observably identical to a committed Stop followed
by suspension. Use the session's known support route or tenant fallback;
do not fetch protected owner details after denial. Never infer “ended” from
not-found or from the local record of pressing Stop.

## Evidence, concurrency and policy

Positive writes and `recordRequired()` share one database transaction. Any
local persistence failure rolls back both. Access-reducing writes commit denial
and immutable local intent together, then attempt delivery. Missing signing
key, append failure or drain interruption never reopens access. Participant
receipt evidence is `recorded` or `pending`, never `chained`. Coordinator queue
exposes only a pending flag, not evidence payloads.

The outbox drainer locks one row, rechecks delivery, calls only the public
`AuditChainLoggerInterface::logKeyed()`, and marks delivery in the same database
transaction. Audit Chain must use the same Drupal database connection; a
remote logger is outside this atomicity contract. A failed delivery-marker write
rolls back the append too. No crypto, signing or chain-mutex code is copied.
Metadata is allowlisted to opaque event/context identifiers, operation, state,
waiting side, timestamps and actor hash. Contact points, free-text notes,
credentials and file data are forbidden in stored and chained metadata.

Refused protected calls record a separate minimized observation after rollback:
only a generated event ID, fixed reason, subject hash and timestamp. Guessed
relationship IDs, tokens, request payloads and contacts are never retained in
these observations. Identity and persistence failures also set bounded latest-
observation timestamps for the authorized coordinator queue's `alerts` list.
These are historical observations, not claims that an outage is still active.
If evidence persistence itself is unavailable, the standard logger receives only
a fixed failure code; no protected access or successful receipt is created.
The host must rate-limit these ports and route operational alerts appropriately.

The first implementation serializes domain mutations with a seeded database
mutex row. Reads acquire the same boundary before fresh authorization/projection;
invitation generations are also locked during acceptance. This deliberately
trades throughput for one understandable first-slice transaction order. Outbox
drainers use their own row locks and never reacquire the domain mutex.

Policy periods use the foundation's ISO 8601 subset. Zero periods are refused.
Years/months use UTC calendar addition with end-of-month clamping, then add
remaining days/time; for example January 31 + P1M is February's last day,
and February 29 + P1Y is February 28 in a non-leap year. Fractional seconds are
rounded upward to the timestamp storage precision, never to zero. All output
times are UTC instants for host localization. Tenant policy values remain unset
in product defaults. Synthetic tests configure them explicitly.

## Host handoff

The host supplies the identity/eligibility adapter and maps these service
results to its existing private API. It must not expose the internal entities
or accept a caller-supplied issuer, subject, owner authority or allowed-actions
list. Separate workspace/summary endpoints and POST acceptance are mandatory.
No endpoint names or GraphQL schema are invented by this module contract.
Keep “Work with us” as the shared section; use one catalog keyed by relationship
kind. The UI renders server actions, pause origin, waiting side, update date and
optional flags. It does not restore a browser-cached previous state.

## Callable inputs and synthetic fixture

Container service IDs are `agency_staff.commands`, `agency_staff.invitations`,
`agency_staff.reads`, `agency_staff.outbox` and `agency_staff.identity`.
Replace the identity service through the host's dependency-injection integration.
All methods below return arrays with the outcomes above:

```php
startDiscovery(array $input, string $key): array;
// All other RelationshipCommands methods use this parameter order:
respond(string $id, array $input, string $key, int $version): array;
// InvitationCommands:
issue(string $id, string $key, int $version): array;
resend(string $id, string $key, int $version): array;
revoke(string $id, string $key, int $version): array;
accept(string $token, string $key): array;
inspect(string $token): array;
```

`startDiscovery` uses the acting coordinator as the initial owner. Its input:

```json
{
  "display_name": "Example Person",
  "contact": {"kind": "email", "value": "person@example.test"},
  "owner_contact": {"kind": "url", "value": "https://example.test/help"},
  "next_action": {
    "summary": "Choose whether to talk.",
    "why_it_helps": "Learn what happens next.",
    "side": "participant",
    "expected_by": 2000000000,
    "optional": true
  }
}
```

All dates must be future instants at command time. Contact routes and messages
are bounded plain data: hosts escape text and validate route schemes before
turning them into links. They are not HTML or redirect destinations.

`respond` accepts `choice` (`yes`, `not_now`, `message`, `ready`, or
`decline_proposal`) and optional `note`. Participant pause/resume inputs may be
empty; agency pause/resume require `side: agency`. Agency pause additionally
requires `summary`, `why_it_helps` and `expected_by`. Withdrawal accepts an
optional note. Revoke/suspend take an empty payload. Close requires `category`,
`message` and typed `contact`. Move-forward proposal requires `target_purpose`
and `message`; participant acceptance uses `consent: true` and current version.

Issue/resend returns `invitation.token`, `expires` and `delivery_state: captured`
only on initial success. A retry recovers the minimal receipt, never the bearer
token. If the token response is lost, the coordinator explicitly resends with a
new intent key and current version. No token appears in logs, evidence or stored
receipts. Inspection returns `open`, `closed`, `already_used`, or `workspace`;
only the currently entitled bound principal receives a workspace identifier.

Workspace reads use `outcome: available` with `workspace` or `contexts`; summary
reads use `summary`. A committed command receipt contains `id`,
`relationship_id`, `effective_at`, `evidence`, and, for a terminal transition,
nullable `readable_until`. Initial command responses include `version`.
Authorized participant mutations additionally return the fresh `workspace`.
Coordinator adapters re-read their queue after mutation. Receipt replay does
not promise the original workspace or a bearer token.
