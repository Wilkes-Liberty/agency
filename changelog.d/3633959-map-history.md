---
section: Fixed
---
Preserve typed values in stored map fields when capturing and reconstructing
revision history. Require a new snapshot format for layouts containing maps.
Apply the same preservation to persisted copy, merge and overwrite. Journal
qualification requires explicitly covered properties and refuses dynamic maps.
Refuse writes when a map begins with a numeric key that Drupal would reload
as a list of field items.
