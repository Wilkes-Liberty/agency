---
section: Changed
---
Ready the Slice 2 workforce transfer design for operator gate G1: career
postings and the résumé/application workflow move to Agency, every S2
source maps to an `agency_*` type, copier plugin and Field Guard
marking, and DEV-992 Option A conditions are listed per entity. Ontology
draft v0.1.1 adds a recruiting relationship kind, a proposed
`agency_recruiting` boundary, and proposed application, posting,
profile-evidence, staff-invitation and staff-onboard types.
`chronicle_personnel` copies onto `agency_person` and
`agency_profile_evidence`. Career-upload tokens, résumé scan jobs and
claim-scoped access are File Gate carry-over; geo handlers are host-stay;
client profile bundles are host-stay with fail-closed collision
preflight. Staff and recruiting writes use their own evidence shapes.
Ledger citations pin webcms `be5a1962`. Slice 2 record types are not
registered.

See [DEV-987](https://wilkesliberty.atlassian.net/browse/DEV-987).
