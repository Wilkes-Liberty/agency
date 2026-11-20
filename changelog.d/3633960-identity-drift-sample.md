---
section: Fixed
---
Sampled reconciliation reports `identity_drift` when source, target or
lineage UUIDs or current revision ids diverge, instead of skipping those
identity fields in the content digest loop and returning a clean comparison.
