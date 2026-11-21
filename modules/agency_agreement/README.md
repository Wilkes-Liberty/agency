# Agency Agreement

This module registers contract/agreement storage. Enabling it creates no
agreements, routes, forms, Views, menu links, host copiers or authority.
It copies no host data and grants no engagement or roster authority.

Ontology YAML still marks Agreement as proposed. This registration does
not flip that status, write an authority row or run a copier. Agency is
the sole authority for this type; there is no Chronicle source.

## Record type

| Ontology row | Machine name | States |
| --- | --- | --- |
| Agreement | `agency_agreement` | `draft`, `signed`, `active`, `terminated` |

Entity access is denied by default, including for user 1. Ontology
lifecycle lists those states and no transitions. This module does not
invent a write action.

`engagement_uuid` is an opaque UUID string. It is not resolved against
engagement storage. `terms` is confidential long text.

## Authority

| Domain | Authority | Source today | Import | Switch-over |
| --- | --- | --- | --- | --- |
| Agreements | Agency | none | none | none |

No host wiring ships in this package.

## Open design

Named actions for draft, sign, activate and terminate are not in the
ontology. See the operator questions on the pull request that introduces
this module; do not treat storage registration as those actions.
