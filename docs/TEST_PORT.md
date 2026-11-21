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

## Batch 2 — S2 personnel signed-document (9 scanned classes)

Generic qualification already lives in
`tests/src/Unit/SignedDocument/SignedDocumentPolicyTest.php`. This batch
adds terminate-permission and existing-envelope attach refusals. Host
Field Guard, Drupal roles, GraphQL, SQL compare-and-set storage and
operator forms stay on the host.

| Host class | Agency status | Reason |
| --- | --- | --- |
| `PersonnelAccessAuditTest` | cut | Host Field Guard adapter (`wl_personnel.auditor`). Audit Chain stays shared. |
| `SignedDocumentFieldGuardTest` | cut | Host `field_guard.settings`, GraphQL denylist and recipe copy. |
| `SignedDocumentRolePermissionsTest` | cut | Host `user.role.*` YAML including operator roles. Generic terminate/attach grants are policy flags. |
| `SignedDocumentMutantKillTest` | cut | Host SQL compare-and-set mutants. Agency has no mapping table. |
| `SignedDocumentMappingConstraintsTest` | cut | Host entity unique keys on `signed_document_mapping`. |
| `SignedDocumentStateMachineTest` | cut | Host SQL transitions. Generic lease, expiry and illegal edges are in `SignedDocumentPolicyTest`. |
| `SignedDocumentAttachServiceTest` | partial | Envelope collision and permission denials added. Paragraphs, profile NDA dates and `field_user_type` stay on the host. |
| `SignedDocumentOperatorFormTest` | cut | Host form CSRF and route ceremony. |
| `SignedDocumentMappingTestBase` | cut | Shared host kernel helper, not an assertion class. |

Landed in this package:

- `tests/src/Unit/SignedDocument/SignedDocumentPolicyTest.php`

## Remaining `to-port` (63)

Later reviewable batches, not this pull request:

- S2 Agency projection and host adapter (12)
- S2 host client-access adapted invitation (9)
- S3 CRM (18)
- S3 intake (9)
- S4 time (4)
- S4 projection (8)
- S4 membership / OIDC bind (3)

S2 GraphQL producers (`accountExists`, `wlInviteStatus`) and client-access
coordinator forms stay on the host. Agency already covers mixed-case
mailbox collision, GET-versus-POST, expiry and own-profile denials.

## Batch 3 — S3 contact field types (2 scanned classes)

| Host class | Agency status | Reason |
| --- | --- | --- |
| `ContactFieldTypesTest` | partial | Typed email, telephone and address are pinned on `ContactFields`. Host `CrmContact` entity source and form widgets stay on the host. |
| `ContactEmailTest` | cut | Host `ContactEmail` key/hint/kind helper. Agency has no mailbox-equivalence object yet; adding one would invent Slice 3 product. |

Landed in this package:

- `tests/src/Unit/ContactFieldContractTest.php`

S3 and S4 entity proofs wait on commercial, intake and engagement
storage that is still scaffolding. Do not invent those types to make
the tests compile.
