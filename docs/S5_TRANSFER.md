# Slice 5 transfer and legacy inventory

Status: **observed on this 1.x stack**. This is not a G-gate, not a
release, and not a host pin bump.

DEV-990 / Linear WL-920 asks for a single-authority transfer table per
capability, imported-record lineage, and a list of interim host
workarounds. Slice 5 is built, not migrated. Ledger S5 has no leftover
callers and no Campaign, Expense, Agreement, Credential, Availability
or Roster rows. The S3 editorial campaign constraint stays Chronicle; it
is not the Agency Campaign type.

## Host pin (unchanged)

webcms `composer.json` stays on `drupal/agency: 1.0.0-alpha7`. This
package does not bump that pin.

## Needs a newer Agency pre-release

These types exist on unreleased 1.x pull requests. They are not in the
published alpha7 tarball. The host cannot enable them until a maintainer
cuts a newer pre-release:

- Marketing campaigns: `agency_campaign` (Agency #83). `launchCampaign`
  is not in alpha7.
- Expenses: `agency_expense` (Agency #84). `recordExpense` is not in
  alpha7.
- Agreements: `agency_agreement` (Agency #87). The module is not in
  alpha7. Ontology transitions stay empty.

Fail-closed proofs for unimplemented later states live on the parent of
this change. They exercise that unreleased storage, not alpha7.

## Operator-blocked (not in this package)

See [S5_OPEN_QUESTIONS.md](S5_OPEN_QUESTIONS.md). Do not register these
until the operator picks a home:

| Capability | Ontology | Registered here |
| --- | --- | --- |
| Credential | proposed S5, `verifyCredential` proposed | no |
| Availability | proposed S5, `offerAvailability` proposed | no |
| Roster | no object type | no |

alpha7 already has workforce invitation and deny-by-default staff
storage. Parking credential or availability on `agency_workforce` would
mix invitation recovery with Slice 5 writes. That is one of the open
questions, not a silent choice.

## Transfer tables

Agency is the sole authority. There is no Chronicle source, no import
point and no switch-over:

| Domain | Authority | Source today | Import | Switch-over |
| --- | --- | --- | --- | --- |
| Marketing campaigns | Agency | none | none | none |
| Expenses | Agency | none | none | none |
| Agreements | Agency | none | none | none |
| Credentials | unresolved | none | none | none |
| Availability | unresolved | none | none | none |
| Roster | unresolved | none | none | none |

Module READMEs already carry the campaign, expense and agreement rows.
This document does not add a second authority.

## Legacy retirement

- S3 editorial campaign constraint: stays Chronicle. Not Agency
  Campaign.
- Spreadsheet, form or host workaround for expenses: none found in the
  ledger.
- Spreadsheet, form or host workaround for agreements: none found in
  the ledger.
- Credential, availability and roster host twins: not registered.
  `wl_*` twins stay with DEV-801 / webcms#1681.

No copier, host adapter or authority row ships in this slice.

## What this document does not do

- Bump the alpha7 pin.
- Invent pause/complete, expense submit/approve/reimburse, or
  agreement sign/activate/terminate.
- Merge, tag, publish or deploy a pre-release.
