# Slice 5 test-port inventory

Status: **observed**. This is not a G-gate and not operator approval.

Slice 5 is new product work (DEV-990 / Linear WL-920), not a Chronicle
move. The host scan at webcms `docs/agency-extraction/test-parity.json`
(`b12940a3`) listed **zero** Slice 5 classes as `to-port`. Ledger S5
has no leftover callers and no Campaign, Expense, Agreement,
Credential, Availability or Roster rows. The S3 "editorial campaign
constraint" stays Chronicle; it is not the Agency Campaign type.

## This change

Fail-closed proofs for unimplemented transitions on storage that already
exists in this 1.x stack:

| Proof | Surface |
| --- | --- |
| Active-campaign launch is `not_found` | `CampaignActionTest` |
| Pause and complete are not services | `CampaignActionTest` |
| Later expense states are not services | `ExpenseActionTest` |
| No agreement write service | `AgreementAccessTest` |
| Agreement stays a proposed S5 type | `OntologyContractTest` |
| YAML has no later write actions | `SliceFiveTransitionTest` |
| Credential, availability and roster modules are absent | `SliceFiveTransitionTest` |
| Service YAML omits those writes | `SliceFiveTransitionTest` |
| Routing, permissions, info, install and module PHP omit those ids | `SliceFiveTransitionTest` |
| Roster has no object or action | `SliceFiveTransitionTest` |
| Enabling campaign, expense, agreement and workforce does not enable those modules, types, tables or services | `SliceFiveUnregisteredRuntimeTest` |
| Uninstalling campaign, expense or agreement leaves the other domains intact and those types unregistered | `SliceFiveUnregisteredRuntimeTest` |

Credential, Availability and Roster stay unregistered. Placement
questions remain in [S5_OPEN_QUESTIONS.md](S5_OPEN_QUESTIONS.md). This
change does not register them or choose a home. Host ledger
[webcms#1754](https://github.com/Wilkes-Liberty/webcms/issues/1754)
records marketing, contracts, credentials, roster/availability and
expenses as Agency capability gaps, not migrated Chronicle sources.

## Cuts

No host Slice 5 class was copied. There is nothing to drop as a
duplicate.

The remaining 25 `to-port` classes on the host map are Slice 3 and
Slice 4. They already have open Agency product pull requests on other
stacks. This stack does not duplicate them.

## Host pin

webcms remains on `drupal/agency: 1.0.0-alpha7`. Campaign, expense and
agreement storage are unreleased 1.x work. They need a newer Agency
pre-release before the host can enable them. This change does not bump
that pin.
