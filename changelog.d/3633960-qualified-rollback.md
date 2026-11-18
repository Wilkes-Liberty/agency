---
section: Added
---
Qualified equal-layout migration copiers can replay post-cutover creates,
updates and deletes in journal order. Replay verifies the whole domain and
commits authority, provenance, tombstones and a retryable handover receipt in
one transaction. Hosts supply the reviewed compatibility and writer fence.
