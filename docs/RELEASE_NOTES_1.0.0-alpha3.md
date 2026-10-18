# Agency 1.0.0-alpha3 — draft release notes

This prerelease ships the generic `agency_host` Discovery host adapter, the
`agency_migrate` copy and authority framework, a draft ontology contract, and
unregistered Slice 2 workforce scaffolding, plus the tests, documentation and
CI gates that landed on `1.x` after 1.0.0-alpha2. Hosts that do not enable
`agency_host` or `agency_migrate` keep the alpha2 Discovery domain behavior.
Publication, host cutover and invitation remain separate decisions.

## Included

- `agency_host`: identity history, private transport, coordinator console,
  invitation custody, evidence maintenance and `drush agency:readiness`. Tenant
  values stay with the installing site. Enabling the module copies former
  `chronicle_agency` tables, config, state, key/value collections and queue
  items without dropping the source. Qualified binding fingerprints keep HMAC
  `chronicle-agency-history-key-v1` and the digest schema slot keyed
  `chronicle_agency`. Contact policy and delivery audit are replaceable ports.
  Historical `paladin_issuer` / `paladin_audience` token keys remain readable
  so already-qualified coverage does not change
  ([DEV-1008](https://wilkesliberty.atlassian.net/browse/DEV-1008),
  [webcms#1899](https://github.com/Wilkes-Liberty/webcms/pull/1899),
  [GitHub #28](https://github.com/Wilkes-Liberty/agency/pull/28)).
- Draft ontology contract v0.1.0 for tenant-neutral object, link and action
  types, with YAML, JSON Schemas and failing-fixture tests. Discovery storage
  stays the implemented subset
  ([GitHub #25](https://github.com/Wilkes-Liberty/agency/pull/25)).
- `agency_migrate`: identity-preserving copy, lineage, property markings,
  reconciliation and reversible authority (`legacy` → `shadow` → `agency` →
  `removed`). Host adapters supply copier plugins
  ([GitHub #27](https://github.com/Wilkes-Liberty/agency/pull/27)).
- Slice 2 workforce transfer design and unregistered Staff command, read and
  host-adapter scaffolding. Authority ids match the migrate register. No
  workforce service is registered; expand waits on design gate G1
  ([GitHub #26](https://github.com/Wilkes-Liberty/agency/pull/26)).
- Contributor guidance: pull-request and issue text is written for the
  reviewing operator; agent working rules are not restated there
  ([GitHub #21](https://github.com/Wilkes-Liberty/agency/pull/21)).
- Interrupted-batch and withdrawn-terminal retention controls. An earlier
  committed removal stays removed when a later item rolls back. Withdrawn
  records use the same current policy and recorded terminal time as closed
  records. GitHub CI requires these concurrent cases to execute
  ([GitHub #22](https://github.com/Wilkes-Liberty/agency/pull/22),
  [issue 3630743](https://www.drupal.org/project/agency/issues/3630743)).
- README and the Discovery contract now describe authorized removal, retention
  start, and host duties for scheduling, message custody and backup
  restoration.
- Remaining Discovery access, summary-window and drain verification: missing
  and unrelated refusals are identical, a later summary window cannot extend a
  stored deadline, and an interrupted evidence drain retains one pending intent
  ([GitHub #23](https://github.com/Wilkes-Liberty/agency/pull/23),
  [issue 3629756](https://www.drupal.org/project/agency/issues/3629756)).
- Drupal.org PHPUnit runs the `agency` group so MySQL lanes do not execute the
  explicit PostgreSQL host-qualification test. CSpell uses a project dictionary
  and ignores GPL boilerplate in COPYING.

## Upgrade and operation

#21–#23 add no database schema change or update hook. Enabling `agency_host`
copies former `chronicle_agency` tables, config, state, key/value collections
and queue items without dropping the source. Enabling `agency_migrate`
installs lineage and authority tables; it registers no host source types.
Installation still grants no retention permission. `agency_staff` still
exposes no route, MCP tool or scheduler; `agency_host` adds the host adapter
and `drush agency:readiness`. Workforce interfaces are unregistered.

Hosts already on 1.0.0-alpha2 that do not enable `agency_host` or
`agency_migrate` can replace the package from this tag without a data
migration of Discovery records. Retention, invitation, evidence and summary
contracts in `agency_staff` are the same as alpha2; the new tests and
documents describe that existing behavior.

The WebCMS companion that switches off `chronicle_agency`
([webcms#1899](https://github.com/Wilkes-Liberty/webcms/pull/1899)) requires
Composer `drupal/agency:1.0.0-alpha3` (constraint `^1.0.0-alpha3` also
resolves this tag) and must wait for this tag and an installable package.

Preview pages remain advisory. Changing policy, history, references or identity
bindings still invalidates a prepared removal plan. An uncertain result must be
reconciled through an authorized exact retry.

See [Discovery retention](DISCOVERY_RETENTION.md) for the service contract,
bounds and failure behavior. See [Migration](MIGRATION.md) and
[Workforce transfer](WORKFORCE_TRANSFER.md) for copy/authority and Slice 2
limits.

## Compatibility and acceptance limits

The supported matrix remains Drupal 10.6/PHP 8.2, Drupal 11.3/PHP 8.3 and
Drupal 11.4/PHP 8.4, with MySQL 8 and PostgreSQL 16. No dependency version or
support floor changes in this release.

GitHub supported-core CI on `4084bef342f0bd9a8afd2545855226aa83faebdc`
([run 39098097936](https://github.com/Wilkes-Liberty/agency/actions/runs/39098097936)),
merged to `1.x` as `310ae70d406c301dec1639f5671af1aea2c43a9c`, executed 90
domain tests and 2 installed-host functional tests, with none of the required
concurrent Discovery cases skipped, on:

- Drupal 10.6 / PHP 8.2 / MySQL 8.0
- Drupal 11.3 / PHP 8.3 / MySQL 8.0
- Drupal 11.3 / PHP 8.3 / PostgreSQL 16
- Drupal 11.4 / PHP 8.4 / MySQL 8.0

The same run executed `agency_host` tests (226 tests on Drupal 11.4 / PHP 8.4;
smtp, postmark_webhooks and PostgreSQL-only proofs skip when those modules or
engines are absent). Package and Drupal standards and PHPStan (PHP 8.3,
Drupal 11.4 analysis host) passed on the same run.

PostgreSQL hosts must still separately qualify genuine deferred-COMMIT failure
with the ended-transaction cleanup fix from
[Drupal core issue 3584465](https://www.drupal.org/project/drupal/issues/3584465).

Removal still covers package-owned records only. Older evidence, replay
receipts and minimal denial records remain effective. Host delivery ciphertext,
attachments, identity-provider accounts, audit archives and backups stay
outside this service.

This release does not activate Discovery, qualify identity history, send an
invitation, cut over workforce authority or establish browser/accessibility
acceptance. No security-advisory coverage claim follows from prerelease
publication. Drupal.org GitLab results remain unverified until an approved
source push of the tagged commit.

Publication requires reviewed source, green release checks, maintainer
approval, contribution-credit review and verified package availability. Follow
[Maintaining](MAINTAINING.md); preserve the existing alpha1 and alpha2 tags.
