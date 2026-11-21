# Test port — generic Agency proofs from the host scan

Parent: [DEV-807](https://wilkesliberty.atlassian.net/browse/DEV-807).
Assurance kit: DEV-1006 / Linear WL-936. Residual scan: DEV-991 /
Linear WL-921. Host inventory:
[webcms TEST_PARITY.md](https://github.com/Wilkes-Liberty/webcms/blob/b12940a3603d511b89439f7c56f3170da62693bc/docs/agency-extraction/TEST_PARITY.md)
at `b12940a3`. Vault plan: *Agency — Enterprise extraction and public
release plan* (supersedes *Agency — Extraction plan* as of 2026-11-12).

**Status: proposed tracking.** This file records which scanned host
tests have generic Agency proofs in this package. It is not a
permission to remove host modules.

## Proposed versus observed

| Kind | What |
| --- | --- |
| Observed | Scan totals at `b12940a3`: 206 classes, 81 `to-port`, 125 `host-stays`, 0 obsolete. |
| Observed | Agency `1.x` already covered staff invitation deny, replay, mail absence, language clamp, collision and rollback in `StaffInvitationTest` and `WorkforceInputValidationTest`. |
| Proposed here | Port only generic behavior. Host-product copy, `/onboard` UI, identity-provider and GraphQL wiring, `field_user_type`, Webform and config/sync stay on the webcms host. |
| Pending | Remaining `to-port` rows listed below. Later stacked pull requests should update this table when they land proofs. |

## Operator decisions needed

1. Confirm that host-only ceremony (mail copy, Gettext catalogs, onboard
   routes, tenant config/sync) stays on webcms even when the scan
   marked the class `to-port`.
2. Confirm that exact duplicates of `StaffInvitationTest` are dropped
   rather than copied.
3. Confirm that S3 CRM and S4 projection tests wait on entity types
   that are still scaffolding in this package.

## Batch 1 — S2 staff invitation (7 scanned classes)

| Host class | Agency status | Reason |
| --- | --- | --- |
| `InvitationCatalogInstallationTest` | cut | Host Gettext catalogs and `/onboard` routes. Agency has no public onboard route. |
| `RetainedLanguageOnboardingTest` | cut | Host Webform and localized UI. Language retention is already proved by `testIssueClampsAndRetainsLanguageWithoutMail` and `testDiscardedResponseRecoversByBearerWithoutReplay`. |
| `StaffInviteMailTest` | cut | Host invite-mail hook. Agency sends no mail; `StaffInvitationTest` already asserts an empty collector. |
| `StaffInviteHardLocksTest` | cut | Host config/sync (identity-provider, GraphQL, operator roles, billing fields, tenant UUIDs). |
| `InviteTokenStoreTest` | partial | Generic malformed-bearer and unavailable-issuer proofs added. Retired `InviteClaim` wrappers stay on the host adapter. |
| `StaffInviteProvisionerTest` | partial | Generic extra-role, username-length and mixed-case collision proofs added. Mint, rollback, replay and existing-account cases already live in `StaffInvitationTest`. Preferred-name usernames and `field_user_type` ACL stay on the host. |
| `StaffUserTypeTest` | partial | Extra host role ids refused on `StaffClassification` and `InvitationService::issue()`. Host role/profile maps stay on the host. |

Landed in this package:

- `tests/src/Kernel/WorkforceIntegration/StaffInvitationTest.php`
- `tests/src/Unit/Workforce/WorkforceInputValidationTest.php`

## Remaining `to-port` (74)

Later reviewable batches, not this pull request:

- S2 personnel signed-document (9)
- S2 Agency projection and host adapter (12)
- S2 host client-access adapted invitation (9)
- S3 CRM (20)
- S3 intake (9)
- S4 time (4)
- S4 projection (8)
- S4 membership / OIDC bind (3)

S3 and S4 entity proofs wait on commercial, intake and engagement
storage that is still scaffolding. Do not invent those types to make
the tests compile.
