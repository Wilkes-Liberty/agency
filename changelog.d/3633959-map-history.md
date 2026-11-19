---
section: Fixed
---
Preserve typed values in stored map fields when capturing and reconstructing
revision history. Require a new snapshot format for layouts containing maps.
Apply the same preservation to persisted copy, merge and overwrite. Journal
qualification requires explicitly covered properties and refuses dynamic maps.
Verify native shared map storage against the hydrated current row and every
revision and translation. Refuse maps whose raw shape changes during hydration
and layouts with dedicated map storage. Shared map overwrites verify current
and revision values before committing.
