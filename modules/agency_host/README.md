# Discovery host and coordinator console

This module is the generic host adapter for Agency Staff. The installing site
owns tenant values; see the package README host contract.

The private coordinator console is at `/admin/agency/discovery/console`. It uses
the existing Agency public queue, reads and commands. JSON transport routes keep
their existing contracts. A signed-in Drupal coordinator must remain eligible;
assignment, permission and identity are checked again when a command runs.

Prepare uses three explicit steps: find an existing sign-in by its exact email,
confirm the single verified match, then prepare the conversation. The directory
lookup is bounded to two matches; absent, ambiguous or ineligible matches cannot
be selected. The configured identity reader must support that query. Discovery
does not create identities, Drupal accounts, roles or profiles.

Commands with entered data show a read-only review before confirmation. The
review retains the original input, version and idempotency key in a store bound
to the current authenticated session. No-input commands open that review directly. A retry
uses the retained request, rather than interpreting an unchanged queue as proof
that an earlier request was not saved. A definitive result must be acknowledged
before this session makes another change to the same conversation.

Reviews last 30 minutes and the session retains at most 16. Identical unsubmitted
reviews reuse the retained input and command key as well as the review reference;
attempted requests are not silently evicted. After expiry,
an explicit protected release permits a new review but establishes no outcome
and never resends the old request. Session loss cannot recover an old request.
These holds do not coordinate other sessions, operators or API consumers.

Recovery metadata uses existing framework key/value storage with short atomic
locks. It is separate from serialized session writes. Overlapping attempts keep
an uncertain result unresolved until a saved receipt or qualified conflict is
known. A failed storage read cannot be interpreted as an empty session.
The retained-request list allows generic result acknowledgement or explicit
expiry release after reassignment, without displaying the former owner's
private draft or projection.

Thirty minutes is an access and retry limit, not a physical deletion guarantee.
Expiring payload rows remain until framework garbage collection; Core form
cache defaults to six hours. Cached console forms are rechecked before reuse,
including assignment and review expiry. They cannot restore expired authority.
Minimal hold metadata has no sliding draft lifetime. Cron removes orphaned
metadata only when Core's database session handler confirms its hashed session
row is absent. Unsupported session backends retain that metadata until a
reviewed cleanup integration exists; storage failure never means absence.

## Runtime requirements

The existing Discovery tenant policy, identity authority, contact rules and
binding-history qualification remain required. The console introduces no
production qualification shortcut. Identity selection lasts five minutes; a
retry after selection expiry requires fresh confirmation of the same identity,
while preserving the original business input and key.

Set `DRUPAL_AGENCY_INVITATION_ORIGIN` to the explicitly selected HTTPS origin for
the participant interface, such as `https://portal.example.test`. The default is
empty. A path, query, fragment or user information is refused. The host never
guesses the audience from the incoming Host header or another configured site.
Missing or invalid origin prevents issue/resend before a domain command runs.

Invitation links are shown only on their first successful response. They are
not retained in session review results or general messages; receipt replay does
not recover the link. Resend creates a new link and supersedes the old one.
Invitation validity follows tenant policy and the interface is English only.
The invitation entry opens a read-only preview of the captured conversation and
policy details. Only the separate Save invitation confirmation issues an
invitation. A reloaded review does not claim that no invitation exists when the
fresh public queue already offers resend instead of issue.

The queue shows elapsed time since the last observed agency action alongside its
exact timestamp and timezone. Future timestamps retain only their exact date;
missing observations remain "No action yet".

Closing messages and invitation delivery are captured obligations, not sent
notifications. Alerts describe their last observation, not current health or an
acknowledged resolution. Private notes appear only in an authorized detail
view. Access suspension is not exposed by this console.
An outage alert does not establish a command outcome: a required record-write
failure may refuse Stop or revoke, while a committed receipt can report pending
downstream evidence delivery. Unknown outcomes require checking or exact retry.

## Interface checks

`resources/console-fields.json` declares the purpose of rendered inputs,
command controls, hidden review references and command input keys. Form API
refuses undeclared inputs after building all elements; review capture refuses
undeclared command keys. Framework CSRF/build identifiers are explicit
exemptions. The host tests render every supported command and exercise negative
controls for new visible, hidden and command inputs.

The required CI documentation job runs
`node scripts/test-agency-console-copy.mjs` on every change. It checks the
English catalog and six refusal controls. These checks supplement rendered,
keyboard, narrow-screen and security qualification; they do not replace them.
