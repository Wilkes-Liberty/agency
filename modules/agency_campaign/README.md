# Agency Campaign

This module registers marketing campaign storage and the `launchCampaign`
action. Enabling it creates no campaigns, routes, forms, Views or menu
links. It copies no host data and grants no relationship, roster or
Discovery authority.

Ontology YAML still marks Campaign as proposed. This registration does
not flip that status, write an authority row or run a copier. Agency is
the sole authority for this type; there is no Chronicle source.

## Record type

| Ontology row | Machine name | States |
| --- | --- | --- |
| Campaign | `agency_campaign` | `draft`, `active`, `paused`, `completed` |

Entity access is denied by default, including for user 1. Writes go
through `launchCampaign` only.

## launchCampaign

The action requires an explicit `coordinate agency campaigns` grant on a
fresh, non-anonymous account. User 1 does not satisfy that grant by the
administrator shortcut.

| Input | Result |
| --- | --- |
| `title` | Creates a `draft` campaign owned by the coordinator. |
| `campaign_uuid` of an owned draft | Moves that campaign to `active`. |

Launch does not pause or complete a campaign. Those transitions have no
action in the ontology yet.

Deny paths, checked first:

- a caller-supplied `tenant` argument
- a `not_after` deadline that is missing as an integer or has passed
- a malformed idempotency key
- anonymous, ungranted or inactive callers
- a drifted payload on a reused key
- a missing, foreign or non-draft campaign on activate

The same coordinator, key and payload returns the original receipt.
Required evidence is inserted in the same transaction as the campaign
row. Evidence metadata is limited to event id, campaign id, actor hash,
state and effective time. Titles, contacts, tokens and files are
forbidden there.

## Authority

| Domain | Authority | Source today | Import | Switch-over |
| --- | --- | --- | --- | --- |
| Marketing campaigns | Agency | none | none | none |

No host wiring ships in this package. A later host adapter may call the
launch service; that is configuration, not a second authority.
