# Agency 1.0.0-alpha3 — draft release notes

This prerelease records the tests, documentation and CI gates that landed on
`1.x` after 1.0.0-alpha2. It does not change Discovery runtime behavior for an
installed site. Publication and host activation remain separate decisions.

## Included

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

Runtime module, configuration and Composer files are unchanged from
1.0.0-alpha2.

## Upgrade and operation

No database schema change or update hook runs during this upgrade. Installation
still grants no retention permission and still exposes no route, Drush command,
MCP tool or scheduler.

Hosts already on 1.0.0-alpha2 can replace the package from this tag without a
data migration. Retention, invitation, evidence and summary contracts are the
same as alpha2; the new tests and documents describe that existing behavior.

Preview pages remain advisory. Changing policy, history, references or identity
bindings still invalidates a prepared removal plan. An uncertain result must be
reconciled through an authorized exact retry.

See [Discovery retention](DISCOVERY_RETENTION.md) for the service contract,
bounds and failure behavior.

## Compatibility and acceptance limits

The supported matrix remains Drupal 10.6/PHP 8.2, Drupal 11.3/PHP 8.3 and
Drupal 11.4/PHP 8.4, with MySQL 8 and PostgreSQL 16. No dependency version or
support floor changes in this release.

GitHub supported-core CI on `7a47681b43b695b2b69c1c5851fec5aab04db9a7`
([run 38929727153](https://github.com/Wilkes-Liberty/agency/actions/runs/38929727153))
executed 74 domain tests and 2 installed-host functional tests, with none of
the required concurrent cases skipped, on:

- Drupal 10.6 / PHP 8.2 / MySQL 8.0
- Drupal 11.3 / PHP 8.3 / MySQL 8.0
- Drupal 11.3 / PHP 8.3 / PostgreSQL 16
- Drupal 11.4 / PHP 8.4 / MySQL 8.0

Package and Drupal standards and PHPStan (PHP 8.3, Drupal 11.4 analysis host)
passed on the same run. Drupal.org GitLab results for this candidate remain
unverified until an approved source push of the tagged commit.

PostgreSQL hosts must still separately qualify genuine deferred-COMMIT failure
with the ended-transaction cleanup fix from
[Drupal core issue 3584465](https://www.drupal.org/project/drupal/issues/3584465).

Removal still covers package-owned records only. Older evidence, replay
receipts and minimal denial records remain effective. Host delivery ciphertext,
attachments, identity-provider accounts, audit archives and backups stay
outside this service.

This release does not activate Discovery, qualify identity history, send an
invitation or establish browser/accessibility acceptance. No security-advisory
coverage claim follows from prerelease publication.

Publication requires reviewed source, green release checks, maintainer
approval, contribution-credit review and verified package availability. Follow
[Maintaining](MAINTAINING.md); preserve the existing alpha1 and alpha2 tags.
