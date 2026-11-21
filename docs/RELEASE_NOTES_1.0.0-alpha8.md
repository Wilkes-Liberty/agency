# Agency 1.0.0-alpha8 — draft release-prep notes

Status: **prep only**. This is not a tag, not a Drupal.org publish, and
not a webcms pin bump. The maintainer chooses the tag name; 1.0.0-alpha8
is the next identifier after 1.0.0-alpha7. Do not tag, merge, or deploy
from this document.

Inventory date: 2026-11-21. Head of `1.x` at that time:
`9a7ea6b` (merged #122). webcms remains on
`drupal/agency: 1.0.0-alpha7`.

Tracking: DEV-807. Slice stories DEV-988 / WL-918 (S3), DEV-989 / WL-919
(S4), DEV-990 / WL-920 (S5). Ontology DEV-1000 / WL-930. Residual scan
DEV-991 / WL-921. Vault: *Agency — Enterprise extraction and public
release plan* and Decision `2026-10-17 Agency extraction is
ontology-first with per-domain authority cutover`.

## Already on `1.x` after 1.0.0-alpha7

These merged after #79 (alpha7 prep). They ship in the next tag even if
no other stack merges. Links are GitHub pull numbers on
Wilkes-Liberty/agency.

Foundation:

- #80: drop REQUIREMENT_ERROR from the agency-name report
- #81: sampled identity drift for UUID and revision mismatches
- #82: Discovery deny paths for permission and assignment

Slice 5 (DEV-990):

- #83: `agency_campaign`, `launchCampaign`
- #84: `agency_expense`, `recordExpense`
- #87: `agency_agreement` storage, no write action
- #88: credential, availability, roster placement questions
- #112: fail-closed unimplemented Slice 5 transitions
- #113: Slice 5 transfer inventory; pin unchanged
- #122: credential, availability and roster stay unregistered

Slice 4 (DEV-989):

- #86: engagement deny, replay, timeout proofs
- #95: time input, commercial projection, membership policy
- #105: S2 GraphQL / client-access host ceremony cuts
- #106: S4 GraphQL feed / CRM / time host ceremony cuts
- #110: client deliverable and invoice listings
- #111: remaining Slice 4 host tests as storage-free policies

Slice 3 (DEV-988) already on `1.x`:

- #89: S3 deny, replay, timeout contracts
- #121: remaining S3 CRM kernel deny proofs
- #93: conservative contact-email keys
- #97: opportunity, activity, intake-submission types

Test-port (generic host proofs):

- #90: staff-invitation deny proofs
- #91: signed-document terminate and envelope proofs
- #104: typed contact field proofs

## Ready-for-review pull requests that would be in the tag

Not merged. They sit on the remaining Slice 3 stack. Merge bottom-up
onto `1.x`. Do not flatten the stack or merge a child onto `1.x` before
its parent.

### Slice 3 (9b0a) — commercial and intake remainder

Merge order: **#98 → #99 → #100 → #101 → #102 → #103 → #107 → #108 →
#109 → #114 → #115 → #116 → #117 → #118 → #119**.

#101–#119 are ready but sit on drafts #98–#100. Those drafts have to
merge (or be marked ready and merged) before #101 can land on `1.x`.

- #101 ready: deny-closed commercial record and match ports
- #102 ready: deny-closed intake outbox and policy ports
- #103 ready: deny-closed delivery and intake-tools hatches
- #107 ready: vocabulary, tool payload, private-search projection
- #108 ready: inquiry validation and business submission mapping
- #109 ready: fail-closed intake policy without a live catalog
- #114 ready: deny-closed operating seats and typed lifecycle actions
- #115 ready: commercial route eligibility without host CRM breadcrumbs
- #116 ready: ticket submission mapping without JSM custom fields
- #117 ready: deny-closed contact match lookup
- #118 ready: deny-closed private search lookup
- #119 ready: deny-closed seat isolation and account issuance

Slice 4 and Slice 5 have no remaining open product pull requests on
`1.x`.

## Draft pull requests not ready for the tag yet

Include them only after each is marked ready on its current head.

- #98 S3: operating schedule. Blocks #101 and above
- #99 S3: operating-draft receipts. Blocks #101 and above
- #100 S3: intake proxy-signal. Blocks #101 and above
- #120 this file: draft release-prep notes. Not a tag.

## Merge order the remaining stack needs

1. `1.x` already has the foundation, Slice 5, Slice 4, test-port, and
   Slice 3 through #97.
2. S3 remainder: #98, #99, #100, then #101, #102, #103, #107, #108,
   #109, #114, #115, #116, #117, #118, #119 when the drafts are ready.
3. After those merges, compile changelog fragments on a dedicated prep
   commit (same pattern as #79). Then the maintainer tags. This file is
   not that compile.

## New and expanded submodules and types

alpha7 already shipped refusing `agency_commercial`, `agency_intake`
and `agency_engagement` scaffolds. The next tag expands those modules
and adds three Slice 5 modules that are already on `1.x`.

- `agency_campaign`: not in alpha7. Campaign entity; `launchCampaign`.
- `agency_expense`: not in alpha7. Expense entity; `recordExpense`.
- `agency_agreement`: not in alpha7. Agreement entity; no write action.
- `agency_commercial`: alpha7 refusing scaffold. `1.x` adds
  `agency_opportunity` and `agency_activity`.
- `agency_intake`: alpha7 refusing scaffold. `1.x` adds
  `agency_intake_submission`.
- `agency_engagement`: alpha7 refusing scaffold. Merged Slice 4 work
  still adds no engagement entity types.

Credential, Availability and Roster stay unregistered.

## Schema and update hooks

None of the Slice 3/4/5 modules above add `hook_update_N()`. Existing
alpha7 sites run the normal package replace and `updatedb`; they do not
gain a numbered update from these modules.

Schema that appears when a module is **enabled**:

- `agency_campaign`: `hook_schema` tables `agency_campaign_outbox` and
  `agency_campaign_intent`. Entity tables `agency_campaign` and
  `agency_campaign_revision`.
- `agency_expense`: `hook_schema` tables `agency_expense_outbox` and
  `agency_expense_intent`. Entity tables `agency_expense` and
  `agency_expense_revision`.
- `agency_agreement`: no extra schema. Entity tables
  `agency_agreement` and `agency_agreement_revision`.
- `agency_commercial`: no extra schema. Entity tables
  `agency_opportunity`, `agency_opportunity_revision`,
  `agency_activity`, `agency_activity_revision`.
- `agency_intake`: no extra schema. Entity tables
  `agency_intake_submission` and
  `agency_intake_submission_revision`.
- `agency_engagement`: empty `hook_schema()`. No entity tables in the
  merged Slice 4 work.

Enabling a module creates no business rows. Entity access stays denied
by default, including for user 1.

## Upgrade path from 1.0.0-alpha7

1. Maintainer merges the remaining Slice 3 stack in the order above,
   compiles changelog fragments, and tags. This document does not do
   those steps.
2. Hosts replace `drupal/agency: 1.0.0-alpha7` with the new tag.
   Constraint `^1.0.0-alpha8` also resolves it once the tag exists.
3. Run database updates. No new numbered updates are required for the
   Slice 3/4/5 modules listed here.
4. Enable only the modules the host intends to use. They do not enable
   themselves. `agency_campaign`, `agency_expense` and
   `agency_agreement` are new; they are absent on alpha7 sites until
   enabled.
5. `agency_commercial`, `agency_intake` and `agency_engagement` were
   already present as scaffolds. Enabling them after the tag installs
   any newly registered entity types.
6. Do not treat enablement as authority cutover. No copiers or host
   adapters ship in Slice 5. Slice 3/4 host leftovers stay until the
   host swaps callers (below).
7. This prep change does not edit webcms `composer.json`. The pin stays
   `1.0.0-alpha7` until the maintainer bumps it after the tag is
   installable.

Drupal 10.6 still skips campaign, expense and agreement kernel tests
that use PHPUnit 10 attributes. Named unit proofs run on every lane.

## webcms caller swaps that unblock after the tag

These stay on the host until the pin moves. After the tag is
installable, a separate webcms change can point at Agency instead of
the leftover host class. This list is from merged and remaining PR
bodies. It is not a pin bump.

Slice 5 has no Chronicle source and no leftover host callers in the
ledger. The host swap is: enable `agency_campaign` / `agency_expense` /
`agency_agreement` and call `launchCampaign` / `recordExpense`. There
is no agreement write to call.

Slice 3 leftovers named on the remaining PRs (host keeps them until the
pin):

- Contact-email helpers and CRM contact field widgets
- Commercial record/match, intake outbox/policy, delivery worker,
  intake tools, vocabulary, tool operations, private-search metadata
- Inquiry, FormProvenance, InquiryWebformHandler,
  BusinessSubmissionMapper, ServiceRequestDeliveryWorker
- Overlay form ids, Webform third-party settings, JSM field ids

Slice 4 leftovers named on the merged PRs:

- Time workspace UI and Drush time commands
- Client deliverable / invoice GraphQL feeds
- Statement presentation (snapshot qualification is already Agency
  preflight)

S2 GraphQL producers, `/invite` doors and client-access coordinator
forms stay host ceremony (#105). They are not Slice 5 product.

Editorial campaign constraint stays Chronicle (ledger S3). That is not
the Agency Campaign type.

## Operator questions (unchanged)

Left for the operator. This prep does not pick an answer.

1. Credential and Availability home: isolated submodules, workforce, or
   a combined readiness module.
2. Roster: new object, host projection, or defer until membership and
   availability homes are chosen.
3. `recordExpense` local `coordinate agency expenses` grant versus
   ontology `agency_engagement.coordinate`.
4. Whether expense submit/approve/reimburse and agreement
   draft/sign/activate/terminate get named actions, or stay states
   without transitions.

## What this prep does not do

- Tag `1.0.0-alpha8` or any other version.
- Compile `changelog.d` into `CHANGELOG.md`.
- Bump the webcms pin.
- Push to Drupal.org or edit drupal.org issues.
- Merge any of the remaining Slice 3 pull requests.
- Register Credential, Availability or Roster.
