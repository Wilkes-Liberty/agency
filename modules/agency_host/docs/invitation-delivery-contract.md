# Approved Discovery invitation delivery

Technical contract for the disabled-by-default implementation. This is not a
mail, policy or release authorization; qualification gates remain separate.

## Goal

An eligible coordinator can review one exact invitation message and authorize
one delivery attempt to the explicitly selected existing identity's verified
contact. Acceptance, invitation validity, mail transport acceptance, eventual
delivery and evidence delivery remain separate facts. No account, profile, role
or identity is provisioned. The first implementation is English only and has a
captured transport for synthetic qualification.

## Existing public boundary and atomicity

Agency owns relationships, invitation generations, acceptance and entitlement.
The host owns message approval, token custody and the delivery attempt ledger.
The host uses only public Agency commands and reads. It neither reads nor writes
Agency entities or tables, decorates its storage nor derives credential state.

`InvitationCommands::issue()` and `resend()` return the token only on first
success; replay returns a committed receipt without the token. Agency owns its
root transaction and rejects an already-open caller transaction. Consequently,
**atomic Agency issuance plus host token capture is not available through the
current public ports**. A host transaction around issuance is prohibited.

The host implementation uses an explicitly bounded capture saga:

1. Commit an issuance reservation containing its immutable operation/key/version
   and authoritative recipient-binding reference in the host ledger.
2. Call the existing public invitation command outside a host transaction.
3. On first success, encrypt and persist the returned token, expiry and exact
   receipt together in one host transaction. Only this state permits preview.
4. On definitive refusal, retain the refusal and do not send. On uncertainty,
   leave issuance unresolved. An explicit exact-command retry may reconcile it.
5. If issuance committed but token custody was lost, retain `capture_unavailable`.
   A replay receipt cannot reconstruct the token. This state never dispatches,
   silently reissues or creates a new generation. Its recovery explains that the
   invitation may exist but no message was handed to transport. A separately
   approved revoke/new generation is a new operation, not a retry.

The atomic guarantee is host **send eligibility plus token custody**, not atomic
issuance across both owners. If true cross-owner atomic capture is mandatory,
implementation must wait for a reviewed additive public Agency capture port.
No such port is assumed or introduced by this host slice.

## Authoritative recipient and preparation

Queue projections contain no participant contact or principal identity. Existing
relationships cannot be associated with caller-entered recipients, display-name
matches, email matches or private domain records.

For new preparations, persist the exact explicit issuer/subject, verified contact,
coordinator and start-command fingerprint before calling `startDiscovery()`.
Reconcile its public receipt's relationship ID with the same reserved command;
a replay can recover that ID. Only this successful preparation mapping can back
a delivery. Existing unmapped relationships remain usable by the current console
but have no send action. No backfill guesses identity or contact.

Before preview and dispatch, independently require the dedicated delivery-approval
permission (no shipped role receives it) and a current eligible cookie
coordinator with assignment authority, the same configured issuer and exact
subject readback, fresh binding/deactivation checks, unchanged verified contact,
`mayContact()` permission and authoritative mail suppression clearance. The
issuance replay receipt is not fresh send authority. A changed recipient requires
a newly reviewed preparation; it never silently redirects the old invitation.

This mapping means the freshly verified contact explicitly approved with this
preparation; it is not a claim about the Agency-stored person contact. Agency may
reuse an existing person without updating that stored contact. The reused-principal
case is tested, and neither stored contact nor a changed email is inferred.

The public suppression service is used directly, and the normal mail-alter
suppression hook remains active. Missing service, unavailable decision or a
configuration that cannot enforce the selected suppression policy blocks sending.
The adapter never treats lack of suppression evidence as consent.

## Token custody and retention

Raw tokens exist only transiently in memory and authenticated encrypted host
payload storage. The encryption key is an explicit private deployment setting,
never an exported config value or database-stored fallback. Missing key or key
rotation without the required old key makes payloads unavailable, never plaintext.
Ciphertext uses a fresh nonce per encryption, a version/key identifier and
authenticated associated data binding the immutable mapping/delivery/relationship
identity. Swapping otherwise-valid ciphertext between rows must fail. Synthetic
tests supply synthetic keys; implementation provisions no live secret.

Immutable approval fingerprints bind the token-bearing message; no separate
token telemetry is exposed.
URLs/tokens/message bodies are excluded from logs, audit events, general messages,
receipts and queue projections. Authorized private preview alone renders the exact
message. It uses private/no-store, no-referrer, bounded input and protected POSTs.
An explicitly configured positive payload-retention interval is required for
custody and delivery, not merely cleanup. Missing/invalid retention disables both;
there is no invented business default. Expired/revoked/superseded tokens are never sendable.
Minimal attempt/outcome metadata may remain after payload cleanup so cleanup does
not erase unknown outcomes or permit another dispatch. Data-preserving code rollback keeps attempt fences. Restoring an older database
can lose newer fences: delivery must be disabled under an external deployment
gate before restore and remain disabled until retained attempt/provider evidence
is reconciled. This implementation cannot detect every stale database restoration
and does not claim replay safety across it. Backups retain the custody boundary.

## Exact preview and approval

Capturing an invitation does not approve delivery. A separate protected Form API
page displays the frozen recipient, tenant identity, fixed Discovery purpose,
English subject/body, neutral existing-sign-in CTA, actual token expiry with
unambiguous timezone and typed support route. It includes no private notes,
free-text message additions, attachments or credential/setup-status claims.

The preview fingerprint covers the delivery identity and exact token-bearing
envelope: recipient, subject/body including the invitation URL, expiry, support
route, language and template version. Final
approval supplies its server-held fingerprint and explicit button, not editable
mail arguments. Fresh revalidation or changed content invalidates the preview;
it cannot silently substitute different content. GET does not approve, issue,
resend, mark attempted or send.

The neutral CTA is "Sign in to view your invitation". The renderer uses the
configured public HTTPS invitation origin and existing `/en/invite/{token}` route.
Real recipient and delivery approval remain explicit operator actions. Synthetic
capture is identified as capture and never labelled sent or delivered.

## Dispatch, duplication and concurrent changes

Host records have a stable delivery ID and unique exact-command fingerprint.
Changed payload with a reused key refuses. Concurrent approvals or requests for
the same delivery cannot create a second attempt. The dispatcher commits an
`attempting` fence before transport handoff and records `unknown` if that
boundary cannot be reconciled. There is no automatic retry of attempting,
unknown, accepted or delivered states, including after process restart. A stale database restore requires the external
delivery-disable and reconciliation gate described above.

Immediately before the transport boundary, recheck current authority, contact,
suppression, expiry and `inspect(token)` status; only the same open invitation is
eligible. A later generation makes the old token closed. Use one host relationship
lock for this host's capture/dispatch/resend/revoke operations. Ordinary public
Agency status remains authoritative if another caller changes the generation.

The last successful preflight is the eligibility cutoff; external mail cannot be
atomically recalled. A revocation or supersession that wins before preflight
blocks dispatch. One racing with an already-started handoff may leave mail in
flight, but the old link remains invalid. Show that distinction; never imply
revocation recalls previously handed-off mail. No implementation accesses the
private Agency mutex to invent a stronger guarantee.

## Transport outcomes and reconciliation

Use the existing MailManager/plugin transport, not staff or client onboarding
senders. Dispatch runs inline only after an explicit protected POST; no cron or background
worker sends or retries messages. Disabled mode preserves the existing console
preparation and captured-link paths. Only delivery-enabled preparations use the
new authoritative mapping path.

The production adapter remains disabled until explicit deployment
configuration, approved identity/support/policy and transport qualification exist.
The synthetic adapter captures messages and deterministically simulates outcomes.

Preview preparation calls `MailManager::mail(..., send: FALSE)` so the configured
mail hook, all mail-alter hooks and selected plugin formatting run without sending.
The returned `send` flag remains the hook suppression decision. Freeze and show
the final formatted envelope, including exact recipient/from/reply-to, permitted
headers, normalized subject/body and plugin identity. Reject extra recipients,
Cc/Bcc, attachments and unsupported envelope parameters. Before dispatch, prepare
again through MailManager without sending and require an identical envelope and
current `send=TRUE`; changed formatting or recipient requires new preview/approval.
Then invoke the qualified `PrivateSmtp` handoff subclass's inherited `mail()`
with that exact validated envelope once. This avoids rerunning alteration hooks after approval.
The initial real adapter qualifies only the existing SMTP plugin with rerouting,
queueing and debug output disabled; a different plugin or routing configuration
requires explicit qualification. No automatic alternative transport is selected.

SMTP applies sender changes after formatting. Qualification therefore computes and
validates the effective From address/display name, envelope Sender/Return-Path and
reply-to under the pinned SMTP implementation's precedence rules. Freeze these
values and a fingerprint of effective non-secret routing configuration with the
preview; validate again before handoff. Require explicit approved TLS settings
with certificate verification, bounded timeout and a single qualified destination;
backup hosts, opportunistic/unprotected routing, reroute, queue and debug modes
are refused. Explicit sender settings must agree with all sender-bearing message
headers/parameters; no implicit site-name/address fallback is accepted. Tests
exercise the actual SMTP plugin through a no-network PHPMailer pre-send capture,
not a second implementation that merely predicts the envelope.

| State | Evidence | Permitted next action |
| --- | --- | --- |
| Invitation captured; not approved | Token/message custody exists; no send approved | Preview and explicit approval |
| Approved message captured for testing; nothing sent | Synthetic adapter retained the approved message without a mail handoff | Inspect captured evidence only |
| Issuance unresolved | Issuance may have committed; no delivery attempt exists | Explicit same-command reconciliation; no automatic new generation |
| Suppressed | Known pre-handoff suppression | Review current permission; no automatic retry |
| Failed before handoff | Proven no transport call occurred | Fresh preview/approval of the same invitation |
| Unknown | Attempt fence or ambiguous/negative/exceptional transport result | Read-only reconciliation; no automatic retry/new invitation |
| Accepted by transport | Positive transport handoff receipt | Observe; do not call this delivered or accepted by participant |
| Delivered | Authenticated correlated delivery evidence, when the transport provides it | Observe only |
| Capture unavailable | Invitation may exist; host has no token | Explicit recovery; no send/reconstruction/reissue |

A MailManager `result=false` after entering the plugin is ambiguous, not proof of
non-delivery. A suppression cancellation with `send=false` before plugin handoff
is a known suppressed outcome. Lack of provider correlation leaves delivery
unknown; it does not justify fake delivery telemetry. Unknown outcomes identify the responsible coordinator and the configured typed
support route without permitting another send or automatic generation. Disabled
or suppressed delivery explains the unmet prerequisite and provides the allowed
review/support action. A changed preview requires new review. Loss of permission,
assignment or identity removes the frozen recipient/body/token from the response
and replaces recovery detail with generic support; it never promises an old
preview remains accessible.

The first SMTP adapter may
only establish accepted/unknown; later authenticated provider reconciliation can
add delivered/bounced without changing invitation authority or acceptance.

## Accessible review and recovery

Use native labelled controls, one named main region and heading, and a separate
review/approval step with no default send selection. Cancellation restores focus
to its opener. Field validation is associated with its input and preserves only
currently authorized safe entries. Outcome/recovery regions receive focus and
polite announcements without duplicate alerts. Approval, attempting/unknown,
transport acceptance, delivered mail and participant acceptance have separate
labels. Signing in to view the invitation does not accept it, create an account
or grant a working relationship; the fixed body says so without credential-state
inference. Keyboard navigation, long content, 320px layout and actual screen-reader
output are distinct qualification gates, with no screen-reader claim from DOM
roles or automated checks alone.

## Failure modes and verification before implementation

- Missing mapping, wrong subject/issuer, deactivation, rebinding, reassignment,
  contact denial and suppression all prevent preview/dispatch without disclosure.
- Outer-transaction issuance is refused by the package; prove the proposed saga
  never depends on nested transaction acceptance.
- Preparation response loss reconciles the same relationship; issuance response
  or custody loss cannot send/reconstruct/automatically replace an invitation.
- A capture write failure leaves no send-eligible row. Ciphertext tampering and
  missing/wrong keys fail closed. Payloads never appear in logs/receipts.
- GET preview and repeated/reloaded approval cannot send. Forged/stale Form API
  tokens and altered preview fingerprints cannot change the approved message.
- Expiry, revocation, supersession, policy changes and reassignment between preview
  and approval invalidate eligibility. Concurrency tests use independent database
  connections and assert one attempt fence/transport handoff.
- Transport throws, reports false, loses its response or loses final ledger write:
  preserve unknown, never resend. Proven pre-handoff failure allows only fresh
  explicit approval for the same still-valid generation.
- Wrong-account acceptance and reopening acceptance remain package/connected
  journey controls; transport success never changes those states.
- Restore gate refusal, missing retention, wrong key, ciphertext tampering and
  valid ciphertext swapped across rows all block custody/dispatch.
- SMTP effective sender/reply/return-path, TLS and backup routing are observed at
  its actual pre-send seam without any network handoff.
- Mail-alter recipient/body/header changes, suppression and transport routing
  changes after preview prevent dispatch; no hook can silently substitute content.
- Synthetic captured mail verifies exact recipient, neutral purpose, expiry,
  support, English-only policy, no attachments and plain/escaped content.
- Run PHP standards, existing copy contracts, focused PHP 8.4/PostgreSQL integration
  tests, additive install/upgrade checks and independent security review.

## Implementation and qualification sequence

1. Review changes to this contract, especially the honest atomicity limit, custody key and
   authority/preflight cutoff. No new business policy approval is requested here.
2. Keep failure-first integration cases for the durable state machine and public
   package interactions, with deterministic captured transport boundaries.
3. Maintain the host-owned ledger/schema/update, cipher, immutable renderer, public-port
   preparation/capture wrapper and disabled dispatch adapter.
4. Preserve protected delivery review/approval forms and safe status copy;
   keep existing captured-link behavior available for unmapped invitations.
5. Qualify captured/synthetic transport and concurrent/unknown recovery, then
   independent review and CI. Real mail and deployment remain later explicit gates.

### Private SMTP handoff

The mail formatter remains `SMTPMailSystem`. The qualified handoff class is
`Drupal\agency_host\Delivery\PrivateSmtp`, included as `handoff_class`
in the routing fingerprint. It inherits the pinned SMTP constructor and envelope
construction, overriding only the send boundary. It calls PHPMailer once and
closes the connection in `finally`. Provider exception text and `ErrorInfo` never
enter the logger; a failure is unknown. Construction failure has no fallback to
the general SMTP helper. A no-network parent-mail test injects a failure carrying
a synthetic bearer marker and verifies logs, attempt count and connection close.
