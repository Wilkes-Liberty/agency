# Agency Expense

This module registers expense storage and the `recordExpense` action.
Enabling it creates no expenses, routes, forms, Views or menu links. It
copies no host data and grants no relationship, assignment or roster
authority.

Ontology YAML still marks Expense as proposed. This registration does
not flip that status, write an authority row or run a copier. Agency is
the sole authority for this type; there is no Chronicle source.

## Record type

| Ontology row | Machine name | States |
| --- | --- | --- |
| Expense | `agency_expense` | `drafted`, `submitted`, `approved`, `reimbursed` |

Entity access is denied by default, including for user 1. Access-checked
entity queries return no rows. Writes go through `recordExpense` only.

## recordExpense

The action requires an explicit `coordinate agency expenses` grant on a
fresh, non-anonymous account. User 1 does not satisfy that grant by the
administrator shortcut. Ontology YAML names
`agency_engagement.coordinate`; this module keeps a local permission so
Slice 4 engagement remains a separate boundary.

| Input | Result |
| --- | --- |
| `amount` | Creates a `drafted` expense owned by the coordinator. |
| `assignment_uuid` | Optional opaque UUID. Not resolved against assignment storage. |

Submit, approve and reimburse remain ontology states without an action.

Deny paths, checked first:

- a caller-supplied `tenant` argument
- a `not_after` deadline that is missing as an integer or has passed
- a malformed idempotency key
- anonymous, ungranted or inactive callers
- a missing, non-positive or over-precise amount
- a drifted payload on a reused key

The same coordinator, key and payload returns the original receipt.
Required evidence is inserted in the same transaction as the expense
row. Evidence metadata is limited to event id, expense id, actor hash,
state and effective time. Amounts, assignment ids, contacts, tokens and
files are forbidden there.

## Authority

| Domain | Authority | Source today | Import | Switch-over |
| --- | --- | --- | --- | --- |
| Expenses | Agency | none | none | none |

No host wiring ships in this package. A later host adapter may call the
record service; that is configuration, not a second authority.
