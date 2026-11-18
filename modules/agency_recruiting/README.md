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

Publication validation requires `valid_through` only when `status` is
`published` and `hiring_status` is `open`. The timestamp is an exclusive
deadline. It must be later than the current request time. A paused or filled
posting may remain published with a missing or elapsed deadline.

A host adapter mapping an inclusive date-only close value should use the
start of the following calendar day in the source site's timezone as the
deadline. Calendar-day arithmetic accounts for daylight saving transitions.
Keep the original date and timezone in the projection for reverse mapping.
Use identity-preserving restore for historical records so each revision
retains its recorded publication state and deadline. Normal entity validation
applies when editing an open published posting.

An application may omit a posting reference (general, no-role application).
Both types accept explicit integer ids, UUIDs, revision ids, timestamps and
owner values, and refuse collisions. Entity access is denied by default.

This registration does not flip ontology status, write a `recruiting`
authority row or run a copier.
