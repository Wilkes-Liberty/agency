# Workforce services

This module provides invitation issuance, inspection, acceptance and recovery
services. It supplies no public route, mail delivery, login ceremony or positive
production storage adapter. Installing it does not transfer workforce authority.

An installing host must configure an agency display name and replace the
`agency_workforce.store`, `agency_workforce.provisioner`,
`agency_workforce.policy` and `agency_workforce.writer_boundary` services.
The supplied implementations refuse operations.

The storage and provisioner must use the exact database connection supplied to
the service. Issuance and acceptance own a root transaction and refuse invocation
inside another transaction. The writer boundary must check fresh storage
authority and exclude every covered issuer and consumer until root completion.
Contact and invitation locks then serialize account collision checks and token
consumption. A compatibility adapter may permit explicitly verified legacy or
shadow storage; this alone does not prove coverage of other host writers.

The provisioner creates a local account and matching profile without committing,
sending mail, logging in or making remote calls. It must freshly verify the
returned binding, contact, classification and retained language. Storage must
conditionally consume the exact record version and report one affected row.
Locked lookups must freshly read storage; acceptance verifies the digest is
absent before recording completion.
The synthetic test module demonstrates these contracts without host source.

## Internal workforce records

The module registers internal content entity types for profile evidence,
staff onboard submissions, workforce relationships and workforce
entitlements. Machine names are `agency_profile_evidence`,
`agency_staff_onboard`, `agency_workforce` and
`agency_workforce_entitlement`. They have no routes, forms, Views or menu
links. The access handler denies view, update, delete and create for every
account, including users granted the module's invitation permissions.

Entity keys accept an explicit integer id, UUID, revision id, created and
changed timestamps and owner on create. A colliding id, UUID or revision
id is refused. Profile evidence stores File Gate UUID strings only, never
file bytes or `file` entity references. Staff onboard classification is
`employee` or `contractor`; `discovery`, `client`, `partner` and
`supplier` are refused.

Invitation bearers remain on `InvitationStoreInterface`, keyed by
collection and digest. This module does not register
`agency_staff_invitation`. Existing sites that already enabled the module
gain the new types through `agency_workforce_update_10001`. Enabling the
types does not transfer workforce authority.

## Own-profile read

`agency_workforce.profile_reader` reads a display name by workforce Person
UUID. The optional requested subject restricts the read to that exact
authenticated subject. The service has no route and both ports refuse by
default.

- `agency_workforce.read_authority` supplies the verified issuer, subject and
  tenant and checks the current own-profile workforce entitlement. Resolve
  these from authenticated context and current policy on each call.
- `agency_workforce.profile_source` supplies an explicit workforce Person
  binding. Look up binding metadata before loading profile data. A new
  workforce Person resolves to the eligible authenticated user's reviewed
  account ID and UUID. Profile and editorial records supply projections.
  A Discovery Person is eligible only through a reviewed same-tenant link.
  Ambiguous mappings and identifier collisions refuse.

The service checks the full identity and tenant, then rechecks current
authority before and after projection. The projection adapter verifies the
binding and entitlement together at the protected read, under a consistent
storage snapshot or writer exclusion. Complete any adapter-owned transaction
before returning. Unauthorized and missing profiles and provider failures
each return only `{"outcome":"refused","reason":"not_found"}`.

Audit Chain commits a signed event before the service returns. Failed audit
appends give a generic unavailable error. Call outside a
database transaction so the receipt cannot be rolled back by the caller.
`person.profile_refused` records person and subject refusals, and
`paladin_agent.denied` records a foreign-tenant binding refusal. Each has
only `reason=not_found` as application metadata. The `person.profile_listed`
success event has empty application metadata. All three omit tenant and
identity values, profile data and request contents from application metadata.

## Invitation operation recovery

Successful operations return only after root commit. An uncertain result must
be reconciled rather than retried automatically. Acceptance recovery requires
the original bearer within its original expiry window, or an authorized
operator. Recovery is scoped to the trusted host scope and storage collection;
it never provisions on a miss. Lost issuance acknowledgement cannot recover
plaintext from a digest: an operator must revoke and reissue through host policy.

Issuance records an `invite.issued` intent. Acceptance records
`invite.claimed`, `invite.provisioned`, and `invite.consumed` in that order.
These intents commit with the business operation. Inspection and recovery do
not record lifecycle stages. Recovery receipts retain the operation names
`invitation_issued` and `invitation_accepted`. Pending evidence from earlier
versions retains its original operation names when delivered.

The internal `acceptForApplication()` method returns
`{outcome: refused, reason: not_found}` for denied acceptance. A refusal
before business work or after a confirmed rollback queues `invite.refused`
with the fixed reason `not_found`. The application receives an `unresolved`
status if refusal evidence could not be confirmed or the caller already owns
the transaction. An uncertain business commit returns its receipt ID for
reconciliation. Hosts using `accept()` retain its refusal codes and own their
refusal evidence.

Drupal cron drains at most 20 pending intents per invocation. Schedule Drupal cron and configure
Audit Chain signing before expecting delivery. An unavailable signer leaves the
intent pending for a later invocation. `InvitationJournal::drain()` also permits
a bounded host-operated drain after the business transaction. Signed append and
the delivery marker share one transaction under an outbox row lock; `logKeyed()`
alone does not provide retry protection. Stage evidence contains the event UUID, effective time
and staff classification. Refusal evidence contains the event UUID, effective
time and fixed reason. Evidence excludes bearer, onboarding URL, digest,
contact, preferred name, phone, raw identity claims, filenames and account
binding.

## Personnel evidence file qualification

The optional protected file projector takes an exact parent revision edge and
child revision binding from the host. The host authorizes both ends and verifies
that the stored file points to a unique managed-file UUID with the
expected download gate and grant class. Its read-only grant fingerprint
covers existing grant identities and expiries. No grant is created or
replayed as part of projection.

The projection holds the original file ID, display and description for each
source delta, including repeated references. It is private, request-local
data. Inverse preflight re-reads the pinned source and compares ordered values,
file identities and the host grant fingerprints. Unknown stored properties
or stale protection refuse. A caller that writes after preflight must keep
its own writer fence across qualification and the write. The projector
provides no destination or authority transition.

## Removal and retained records

Routine uninstall refuses while invitation receipts or evidence intents remain,
including delivered intents. Missing or unreadable recovery storage also refuses
removal. Empty installations can be uninstalled normally. Before retiring a used
installation, stop its writers and complete an explicit retention and recovery
procedure; this module supplies no automatic purge. An uninstall refusal is not
a substitute for backup, a restore rehearsal or a writer fence.
