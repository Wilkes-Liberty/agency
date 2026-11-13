# Agency Recruiting

This module registers career posting and application storage. Enabling it
creates no routes, forms, Views, menu links, host copiers or recruiting
authority. It does not copy host records and does not authorize staff
invitation, role provisioning or a Discovery workspace.

Posting close and application review stay later work. Public list and detail
rendering stay with the host. Résumé bytes stay behind File Gate; the
application row stores a File Gate UUID string only.

## Record types

| Ontology row | Machine name | States |
| --- | --- | --- |
| CareerPosting | `agency_career_posting` | `draft`, `published`, `closed` |
| Application | `agency_application` | `received`, `withdrawn`, `accepted`, `declined` |

A published posting must have a future close date (`valid_through`). An
application may omit a posting reference (general, no-role application).
Both types accept explicit integer ids, UUIDs, revision ids, timestamps and
owner values, and refuse collisions. Entity access is denied by default.

This registration does not flip ontology status, write a `recruiting`
authority row or run a copier.
