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

## Remaining `to-port` (25)

Later reviewable batches, not this pull request:

- S3 CRM product still in flight or scaffolding (11)
- S3 intake product still in flight (9)
- S4 time `TimeInputTest` (1; in flight on agency#95)
- S4 `CommercialAccountProjectionTest` (1; in flight on agency#95)
- S4 membership / OIDC bind (3; in flight on agency#95)

Do not duplicate those classes on this stack. They already have open
product pull requests (`#89`–`#103`, `#86`, `#95`).

## Batch 3 — S3 contact field types (2 scanned classes)

| Host class | Agency status | Reason |
| --- | --- | --- |
| `ContactFieldTypesTest` | partial | Typed email, telephone and address are pinned on `ContactFields`. Host `CrmContact` entity source and form widgets stay on the host. |
| `ContactEmailTest` | cut | Host `ContactEmail` key/hint/kind helper. Agency has no mailbox-equivalence object yet; adding one would invent Slice 3 product. |

Landed in this package:

- `tests/src/Unit/ContactFieldContractTest.php`

## Batch 4 — S2 GraphQL projections and client-access ceremony (21 scanned classes)

These rows stay on the webcms host. Agency already covers the generic
deny, inspect and own-profile cases in `StaffInvitationTest` and
`WorkforceProfileReaderTest`. Copying GraphQL producers, Paladin
adapters or coordinator forms would invent host transport.

| Host class | Agency status | Reason |
| --- | --- | --- |
| `AccountExistsTest` | cut | Host GraphQL `accountExists` plus identity-provider login hint. Mixed-case mailbox collision is already in `StaffInvitationTest`. |
| `AccountProfileTest` | cut | Host GraphQL `wlAccountProfile`. Own-profile reads are `WorkforceProfileReaderTest`. |
| `AccountProfileUpdatedAuditTest` | cut | Host GraphQL account-profile update plus recorder contract. |
| `AccountSubjectResolverTest` | cut | Host identity-provider subject resolver. |
| `InviteStatusSchemaTest` | cut | Host GraphQL schema for `wlInviteStatus`. |
| `InviteStatusTest` | cut | Host `/invite` inspect door. GET never consumes; Agency inspect is already in `StaffInvitationTest`. |
| `PersonProfileTest` | cut | Host GraphQL `personProfile`. |
| `PersonProfileUpdatedAuditTest` | cut | Host GraphQL own-profile update plus signed evidence. |
| `PersonProfileWriteResidualAuthzTest` | cut | Host GraphQL write residual authorization. |
| `PersonProfileWriteTest` | cut | Host GraphQL `updatePersonProfile`. |
| `PersonTranslationDraftConflictTest` | cut | Host CMS translation draft conflict on person profiles. |
| `PortalOwnRecordAuthzGapTest` | cut | Host portal GraphQL own-record gap. Indistinguishable denials are in `WorkforceProfileReaderTest`. |
| `InvitationPresentationTest` | cut | Host invitation presentation UI. Module stays disabled. |
| `PortalDeadlineReviewTest` | cut | Host coordinator deadline-review chrome. |
| `ReplacementProfileReviewTest` | cut | Host replacement-profile review UI. |
| `CoordinatorTest` | cut | Host `wl_client_access` coordinator. |
| `InvitationDenyPathTest` | cut | Host Paladin/mail adapters, accept-form copy and config_split enablement. GET-versus-POST and expiry are in `StaffInvitationTest`. |
| `InvitationLifecycleHardenTest` | cut | Host coordinator lifecycle ceremony. |
| `InvitationResidualLockTest` | cut | Host residual lock on the disabled client-access module. Replay refusal is in `StaffInvitationTest`. |
| `InvitationStatusTest` | cut | Host invitation status projection on the coordinator. |
| `StaffProvisioningWorkflowTest` | cut | Host coordinator workflow that must not grant client. Extra-role refusal is in `StaffInvitationTest`. |

## Batch 5 — S4 GraphQL feeds and host CRM/time ceremony (17 scanned classes)

Host GraphQL commercial feeds, CRM chrome, private search, seat
provisioning, time workspace and statement presentation stay on the
webcms host. Generic commercial-account projection, time input and
client-membership policy are already in flight on agency#95.

| Host class | Agency status | Reason |
| --- | --- | --- |
| `ClientDeliverablesTest` | cut | Host GraphQL `clientDeliverables`. Payment engine stays shared. |
| `ClientInvoicesTest` | cut | Host GraphQL `clientInvoices`. |
| `ClientInvoicesResidualAuthzTest` | cut | Host GraphQL invoice residual authorization. |
| `ClientProfileTest` | cut | Host GraphQL `clientProfile`. Do not merge with `personProfile`. |
| `CommercialAccountResidualAuthzTest` | cut | Host GraphQL commercial-account residual authorization. |
| `InstructionSourceAuthorityTest` | cut | Host GraphQL instruction-source authority. |
| `InstructionSourceScopeConfigTest` | cut | Host GraphQL instruction-source scope config. |
| `CrmBreadcrumbTest` | cut | Host commercial-route breadcrumb eligibility and route cache. |
| `CrmContactUpdate10005Test` | cut | Host `hook_update_N` contact lineage. |
| `SeatProvisionerTest` | cut | Host CRM seat, OAuth consumer and operator-role provisioning. Extra-role refusal for staff is in `StaffInvitationTest`. |
| `LivePrivateSearchTest` | cut | Host private commercial search. |
| `PrivateSearchIndexTest` | cut | Host private search index. |
| `RecordSearchTest` | cut | Host record search. |
| `SearchMetadataTest` | cut | Host search metadata. |
| `TimeWorkspaceTest` | cut | Host time workspace UI. |
| `TimeCommandsTest` | cut | Host Drush time commands. |
| `ClientStatementsTest` | cut | Host statement presentation. Snapshot qualification is `StatementPreflightTest`. |

S3 and S4 entity proofs wait on commercial, intake and engagement
storage that is still scaffolding, or on the in-flight product stacks
named above. Do not invent those types to make the tests compile.
