---
section: Fixed
---
Migration authority now uses versioned operational SQL rows instead of cached,
importable configuration. Competing changes serialize and reread their state;
authority, events and evidence commit together. Upgrade 10002 preserves existing
recorded states once. Authoritative transitions and rollback fail closed until
the host supplies verified source/target writer coverage. Executing rollback
acquires that boundary before its pending-write snapshot. Older code that reads
the retained authority configuration must not operate on upgraded domains.
