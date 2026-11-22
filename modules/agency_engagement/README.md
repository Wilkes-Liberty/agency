# Agency Engagement

Agency Engagement provides refusing access ports, a storage-free review policy
and an internal statement preservation preflight.

This submodule owns the Slice 4 engagement and time boundary named by
G-ONT. Enabling it registers refusing ports and a storage-free review-path
policy. It does not register record types, grant permissions, copy host
rows or transfer authority.

The ports named from the proposed time-transfer reads are:

- an engagement and assignment reader
- a time-submission review port
- a statement-snapshot reader

Each port refuses. Signatures take a synthetic identifier only. They do
not represent client membership, statement versus invoice placement,
time-session revisions, feed audiences or authority-register rows.

`ReviewPathPolicy` encodes only the confirmed 2026-09-19 review-path
rule restated as Slice 4 G1 D7: deny self-approval; require both an
assignment and an explicit review permission supplied as booleans; refuse
amendments to an already approved submission; pin exact entry revision
ids; bulk approval is all-approved or a conflict list, never partial.
A time actor is never derived from a Discovery entitlement.

## Deny, replay and timeout

`EngagementActionPolicy` is storage-free. It does not register record
types. Tenant comes from the principal. Cross-tenant and missing-object
reads use the Discovery body `{outcome: refused, reason: not_found}`.
A hatch principal with no tenant is `missing_tenant_claim` and is not
renamed to `not_found`. `offerAssignment`, `submitTime` and
`issueStatement` may be provisioned; they mint no Discovery relationship,
roster membership, CRM contact or opportunity. `submitTime` with a
foreign entry keeps the host time-door `{error: denied}` and does not
invent a time-specific `not_found`. `issueStatement` and
`acceptTimeSubmission` do not call the payment engine. An administrator
flag is not a review assignment. A consumed or expired submit key is
`not_found` and issues no second financial claim. Membership grants do
not mint employee, contractor or extra visitor roles, or create a user,
profile, engagement or Discovery relationship.

Tracker URLs, project refs, gateway class names and tenant hostnames stay
out of this package.

## Time input, commercial projection and membership

`TimeInput` normalizes duration and interval provenance without tracker
identifiers. Unknown keys are refused rather than copied. Host adapters
may still accept tracker context.

`CommercialAccountProjection` emits only account number, billing legal
name and payment-method label from a caller-supplied record. Tax,
address, contact, terms, instruments, financial totals, opportunities
and entity dumps stay unread. Host GraphQL and Profile transport stay on
the adapter until a later pin.

`ClientMembershipPolicy` maps configured group paths to visitor roles,
refuses missing groups and privileged accounts with reasons that are not
`not_found`, never revokes a visitor role the claim no longer carries,
and ignores staff roles in the host map. The OIDC ceremony stays on the
host adapter.

## Statement preservation preflight

Use `StatementPreflight` with a reviewed `StatementSourceInterface` adapter
inside a stable writer-fenced snapshot. Bind the reviewed source namespace
when constructing the preflight. It qualifies an issued statement by its
original kind and serial ID and checks the namespace before reading replay
slots. The inverse returns the original statement row, including exact JSON
bytes. The sorted engagement membership identifies the index rows. The typed
projection retains source-list
order independently from group and line order. It requires historical approval
and bill-to evidence for the actual pinned revisions, including client
Profile ownership at issuance.

The preflight recognizes absent presentation and frozen presentation versions
1 and 2. It checks their structure and keeps the stored prose and formatting.
A separate challenge projection keeps the original receipt identity, actor,
retry key, reason, digest and time. Replay checks compare the source serial slot
and both challenge keys. They refuse occupied invoice IDs and conflicting rows.

Snapshots and challenge data require protected handling. The preflight has no
registered entry point. Access to the statement reader continues to refuse until
its client and reviewer authorization policies are qualified.
