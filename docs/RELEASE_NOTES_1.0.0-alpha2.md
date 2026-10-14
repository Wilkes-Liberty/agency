# Agency 1.0.0-alpha2 — draft release notes

This prerelease adds maintenance services for expired Discovery records and
corrects transaction behavior needed to remove them safely. It remains an
integration candidate; publication and host activation are separate decisions.

## Included

- A bounded, read-only retention preview, using the current configured period
  and each closed or withdrawn relationship's recorded terminal time.
- Preparation and removal of one expired context under a separately granted
  permission. Execution rechecks the exact prepared plan, current policy,
  authority, revisions and shared references before deleting.
- Atomic removal of owned relationship revisions, invitations and entitlements,
  with required evidence and an idempotent receipt. Shared records remain.
  Inactive bindings retain the minimal key and denied status needed to prevent
  removal from making a blocked principal eligible again.
- MySQL writer-lock ordering that prevents a waiting writer from reading an
  earlier snapshot. Failed transaction callbacks also finish before a separate
  observation transaction can replace the outcome they report.

The implementation record is [Agency issue 3630743](https://www.drupal.org/project/agency/issues/3630743).

## Upgrade and operation

No database schema change or automatic removal runs during this upgrade.
Installation grants no maintenance permission. A fresh eligible coordinator
needs `maintain agency discovery retention` for preview and the separate
`remove expired agency discovery` permission for preparation and execution.

The services expose no route, Drush command, MCP tool or scheduler. The host
must provide an approved invocation, current operator authority, a usable site
hash salt and a supported default SQL configuration backend. Missing, stale or
unsupported policy/storage wiring leaves preparation and removal unavailable.

Preview pages are advisory; reaching the final page is not proof of a complete
inventory. Changing policy, history, references or identity bindings invalidates
a prepared removal plan. Unknown history, malformed bindings or excessive
inventory requires review. An uncertain result must be reconciled through an
authorized exact retry; a missing response does not prove deletion.

See [Discovery retention](DISCOVERY_RETENTION.md) for the service contract,
bounds, failure behavior and the explicit PostgreSQL host fault test.

## Compatibility and acceptance limits

The supported matrix remains Drupal 10.6/PHP 8.2, Drupal 11.3/PHP 8.3 and
Drupal 11.4/PHP 8.4, with MySQL 8 and PostgreSQL 16. No dependency version or
support floor changes in this release.
Supported-version CI covers ordinary rollback, concurrent writers, preservation,
authorization and retry after an application response is lost.

PostgreSQL hosts must separately qualify genuine deferred-COMMIT failure with
the ended-transaction cleanup fix from
[Drupal core issue 3584465](https://www.drupal.org/project/drupal/issues/3584465).
The explicit host fault result does not establish that unpatched core has that
behavior. Run it against the exact installed core and patch composition.

Removal covers package-owned records. Older evidence, replay receipts and
minimal denial records remain effective and need their own documented retention
rules. Host delivery ciphertext, attachments, identity-provider accounts, audit
archives and backups are outside this service. Scheduling and those stores must
be qualified before promising complete retention enforcement.

This release does not activate Discovery, qualify identity history, send an
invitation or establish browser/accessibility acceptance. No security-advisory
coverage claim follows from prerelease publication.

Publication requires reviewed source, green release checks, maintainer approval,
contribution-credit review and verified package availability. Follow
[Maintaining](MAINTAINING.md); preserve the existing alpha1 tag.
