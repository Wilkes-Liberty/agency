# Slice 5 remaining placement (design)

Status: **proposed questions**. This is not an implementation contract, not
a G-gate, and not operator approval. It does not add an entity type,
service, schema, copier or host adapter.

Slice 5 in the extraction plan is new product work, not a Chronicle move.
Campaign storage and `launchCampaign` are registered in `agency_campaign`.
Expense storage and `recordExpense` are registered in `agency_expense`.
Agreement storage is registered in `agency_agreement` with empty ontology
transitions. Credential, Availability and Roster are not registered here
because their product boundary is unresolved.

No tenant name, policy period, operating record or secret belongs in this
package. Verification, readiness and access stay separate concepts.

## 1. Credential and Availability vs workforce

Ontology YAML places both types on `agency_workforce`:

| Type | Machine name | YAML owner | Named action | YAML permission |
| --- | --- | --- | --- | --- |
| Credential | `agency_credential` | `agency_workforce` | `verifyCredential` | `agency_workforce.coordinate` |
| Availability | `agency_availability` | `agency_workforce` | `offerAvailability` | entitled principal |

Notes already in the YAML:

- Verification is not readiness and not an access grant.
- Availability is not a commitment and not an assignment.

`agency_workforce` is Slice 2 staff invitation and recovery. Parallel
Slice 2 work owns that module. Putting Slice 5 types there would mix
invitation recovery with credential and availability writes, and would
cross the stay-out boundary.

Options for the operator (pick one; do not combine silently):

1. Isolated submodules `agency_credential` and `agency_availability`,
   following campaign and expense. YAML owner names would be a later
   ontology edit, not this document.
2. Wait until Slice 2 workforce is the agreed home, then add the types
   there under an explicit workforce increment.
3. One combined `agency_readiness` submodule that still keeps
   verification, availability, assignment and access as separate records.

This document does not choose. Deny paths, replay, timeout and required
audit would apply to whichever home is approved, with local permissions
rather than borrowing Slice 2 or Slice 4 grants unless the operator says
otherwise.

## 2. Roster

The ontology has no Roster object type, no roster action and no roster
machine name. Availability is an offered window for a person. Membership
and invitation live elsewhere. North Star language uses "roster" as a
journey concept, not a stored type.

Options for the operator:

1. Add a Roster object and membership-on-roster link in a later ontology
   increment, then register storage.
2. Treat roster as a host projection over entitled principals plus
   offered availability, with no Agency entity.
3. Defer roster until workforce membership and availability homes are
   chosen.

This document does not invent a Roster entity.

## 3. Already asked on earlier Slice 5 pull requests

These are listed so the questions stay in one place. They are not
re-opened here as new scope.

- `recordExpense` ships a local `coordinate agency expenses` grant.
  Ontology YAML names `agency_engagement.coordinate`.
- Expense submit, approve and reimburse are states without actions.
- Agreement draft, signed, active and terminated are states without
  transitions.

## 4. What this slice will not do until those answers land

- Edit `agency_workforce`, `agency_commercial`, `agency_intake` or
  `agency_engagement` to park S5 types.
- Register Credential, Availability or Roster storage.
- Wire a webcms host adapter. The extraction plan treats S5 as Agency
  product work, not host configuration.
- Treat provider content as permission or operating instructions.
