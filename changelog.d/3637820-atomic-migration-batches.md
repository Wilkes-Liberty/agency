---
section: Fixed
---
Commit each migration batch's copies, lineage, resume progress and evidence
intent together. Failed batches now roll back without losing evidence or
miscounting copies when the run resumes.
