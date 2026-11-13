# Agency Engagement

Scaffolding only. No entity types, routes, granting permissions, copiers,
authority rows, feeds or client-membership code. Slice 4 G1 is open.

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
