# Staff invitation and onboarding compatibility

This is the implementation contract for extracting the existing anonymous staff
onboarding workflow. It supersedes conflicting invitation and provisioning rules
in the proposed [workforce transfer](WORKFORCE_TRANSFER.md) and ontology. It is
not a claim that the workflow, host integration or workforce migration has shipped.

## Participant behavior

An authorized issuer chooses employee or contractor classification and an email
contact. The host delivers a single-use invitation. An anonymous recipient may
inspect it without consuming it and submit the host's onboarding form by POST.
Authenticated accounts cannot accept through this ceremony. Acceptance creates
the host account and matching profile, consumes the invitation and records the
required evidence together. It does not log the person in or send password mail.

Keep host consent, wording, localized confirmation, disabled contact input,
classification tamper checks, no-store and no-referrer handling. Preferred name
may be collected on acceptance; issuance must not invent a name it does not have.
Classification and retained language come from the stored invitation. An older
record without a language field resolves the current configured default, as the
existing workflow does. An explicit invalid or removed language is refused; the
stored record is never silently rewritten.

This ceremony grants no Discovery, client, partner or administrator privileges.
Contact is an account collision input, never authority to resolve or merge an
existing identity-provider principal. Discovery continues to require its own
authenticated identity and entitlement checks. The dormant client invitation
protocol is not the staff onboarding protocol.

## Package and host responsibilities

The proposed `agency_workforce` module owns reusable issuance, inspection,
acceptance, single-use policy and durable evidence. It must demonstrate those
behaviors with a synthetic installed host before being described as implemented.
Do not share Discovery invitation tables, commands, locks or evidence outbox.

The host owns forms, routes, catalogs, delivery, account and role/profile mapping.
Preserve service aliases, command aliases and return shapes while callers move.
The host supplies policy periods and configured-language validation. The package
must not copy one installation's values into universal defaults.

Ports must enforce these boundaries:

| Port | Required contract |
| --- | --- |
| Invitation storage | Read by digest, preserve absolute expiry, lock and consume conditionally on the supplied connection; never persist the bearer. |
| Local staff provisioner | Use trusted contact, classification, language and optional preferred name; return an opaque account binding; use the same database transaction. |
| Invitation policy | Validate issuer authority, configured scope, duration bounds and languages; missing required policy refuses. |
| Writer boundary | Validate current authority while acquiring exclusion; retain protection until the root transaction completes. |
| Evidence delivery | Commit required minimized intent with the business change; deliver after commit through Audit Chain's public signed-append contract. |

The default provisioner denies. A local provisioner must not commit independently,
make remote calls, send mail or start a session. A remote provisioning workflow
needs a separately qualified durable recovery contract; it is not a fallback for
failed local provisioning. Site/tenant scope comes from trusted host context,
never a submitted tenant identifier or an assumed anonymous identity claim.

## Transaction and concurrency

Use one local database transaction for account, profile, invitation consumption
and required evidence. A failure in any of them must leave no partial account or
profile and must preserve the original usable invitation.

1. Lock and validate the current writer authority.
2. Acquire durable contact and invitation exclusion in a fixed order.
3. Reload the invitation and validate its digest, expiry, classification and language.
4. Recheck case-insensitive account collisions under that exclusion.
5. Provision the host account/profile through the local transaction participant.
6. Consume the invitation with an affected-row assertion and persist required evidence.
7. Explicitly commit before releasing exclusion or returning success.

A short cooperative lease alone is insufficient. Two invitations for the same
normalized contact must not create two accounts. Other host account-creation
paths must also be inventoried before claiming universal collision protection.
Nested savepoint release is not root commit; an API invoked inside an outer
transaction must not report a durable acceptance before that transaction commits.

An ordinary rollback permits retry with the original token. Lost commit
acknowledgement requires receipt reconciliation against durable state; an uncertain
result never authorizes reminting. Audit Chain delivery failure leaves a durable
pending intent and does not undo committed acceptance or allow token reuse.
The outbox must lock each pending intent and commit its Audit Chain append and
delivery marker together on the same database connection. `logKeyed()` requires
signing; that method name alone does not provide delivery idempotence.

Recovery must also work when the response containing the receipt identifier was
lost. The original bearer may resolve a minimal committed result only within its
original lifetime and collection scope. It must not disclose contact details,
profile data or another invitation's result. After that expiry, only an authorized
operator may reconcile by digest. Keeping a receipt or consumption tombstone must
not turn an expired invitation into a permanent credential. An absent or unreadable
receipt remains unconfirmed and never permits automatic reprovisioning. Issuance
recovery cannot reconstruct the plaintext bearer or silently mint a replacement.

## Existing invitation storage

Existing bearers are 43-character base64url strings. Their stored keys are SHA-256
digests. Preserve each digest byte-for-byte and preserve its absolute expiry.
Never hash an existing digest again, reset its lifetime or require a replacement
64-character hexadecimal bearer. Plaintext remains transient and must not appear
in evidence, logs, migration receipts or stored failure messages.

The first delivery may move behavior behind a compatibility adapter while the
existing key/value collection remains authoritative. This avoids manufacturing
an entity migration merely to extract the workflow. It does not make the entire
workforce domain Agency-authoritative or complete personnel/profile migration.

A later invitation storage migration needs a dedicated adapter. Its source
identity is `(collection, digest)`: it has no original integer ID, UUID, revision
history or reliable creation timestamp. Preserve that identity in durable lineage
and explicitly assign new target identifiers. The entity ID-preservation rule
continues to apply to actual entity sources; it cannot fabricate identifiers for
this source. Do not invent person, principal or relationship references, or
historical consumed/revoked records that the old store deleted.

Fence every issuer and consumer, copy current records, reconcile digest/payload/
expiry and switch storage authority atomically. An Agency lookup miss must never
fall back to legacy. Rollback must replay newly issued records and consumption or
revocation before switching authority, so an old copy cannot resurrect a token.

## Required acceptance evidence

Retain existing host token, provisioner, hard-lock, language and catalog checks.
Write failure cases before implementation and verify at least:

- Same-token concurrent submissions, including a hold beyond the old lease;
  different tokens for the same case-insensitive contact.
- Authenticated acceptance, GET, malformed bearer, exact expiry boundary,
  classification tampering, missing policy and removed language.
- User, profile, consume and outbox failures; fresh-connection inspection shows
  no partial writes. Commit acknowledgement loss has a reconciled recovery path.
- A pre-extraction invitation remains usable without changing its digest or expiry.
- Required-evidence persistence failure rolls back; delivery outage leaves pending
  evidence; retry does not duplicate delivery or expose token/contact data.
- No Discovery writes, unexpected roles, mail or session creation.
- Native form submission preserves consent and localized confirmation.
- Authority changes cannot race issuance/acceptance, and rollback cannot restore
  a previously consumed invitation.

Passing package checks, host adoption, live authority cutover and removal of the
old implementation are separate results. Record each only when verified.
