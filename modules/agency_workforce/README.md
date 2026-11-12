# Workforce invitations

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
The synthetic test module demonstrates these contracts without host source.

Successful operations return only after root commit. An uncertain result must
be reconciled rather than retried automatically. Acceptance recovery requires
the original bearer within its original expiry window, or an authorized
operator. Recovery is scoped to the trusted host scope and storage collection;
it never provisions on a miss. Lost issuance acknowledgement cannot recover
plaintext from a digest: an operator must revoke and reissue through host policy.

Required evidence intents commit with the business operation. Drupal cron drains
at most 20 pending intents per invocation. Schedule Drupal cron and configure
Audit Chain signing before expecting delivery. An unavailable signer leaves the
intent pending for a later invocation. `InvitationJournal::drain()` also permits
a bounded host-operated drain after the business transaction. Signed append and
the delivery marker share one transaction under an outbox row lock; `logKeyed()`
alone does not provide retry protection. Evidence excludes bearer, digest,
contact, preferred name and account binding.
