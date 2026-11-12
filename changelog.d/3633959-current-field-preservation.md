---
section: Fixed
---
Migration copy, rollback replay and reconciliation now include all current field
items, stored item properties and translations. Incompatible field layouts
refuse instead of silently dropping data. Reference checks inspect every delta
and language independently of the canonical JSON encoding. Full revision history
preservation remains separate work.
