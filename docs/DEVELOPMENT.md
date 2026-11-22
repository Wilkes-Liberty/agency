# Development and verification

Work in an isolated checkout of Agency. Use a disposable Drupal test host; the commands
below are not an instruction to modify an operating site.

## Package checks

From the module checkout:

```sh
composer validate --no-check-publish --strict
git diff --check
```

Install Drupal Coder in your development tools environment and run its binary against the
module's `phpcs.xml.dist`. Both Drupal and DrupalPractice must pass:

```sh
/path/to/tools/vendor/bin/phpcs --standard=phpcs.xml.dist .
```

Run PHPStan from a Drupal analysis host that has the package and
`mglaman/phpstan-drupal` installed:

```sh
vendor/bin/phpstan analyze web/modules/contrib/agency \
  -c web/modules/contrib/agency/phpstan.neon.dist \
  --autoload-file=vendor/autoload.php --no-progress --memory-limit=2G
```

## Tenant-string guard

Package code, configuration and shipped defaults must not contain tenant
names or host-product strings: Wilkes, W&L, wilkesliberty, or chronicle.
Declared legacy-compat keys such as `chronicle-agency-history-key-v1`,
the `chronicle_agency` schema slot and historical `paladin_*` token keys
live in `tests/fixtures/tenant-strings/allowlist.yml` with a reason for
each exception. `composer.json` is path-allowlisted for maintainer
attribution only. A seeded fixture proves the check fails.

From the module checkout, with PHPUnit and a YAML parser available:

```sh
phpunit tests/src/Unit/TenantStringGuardTest.php
```

GitHub CI runs this as a dedicated PHP 8.2 job and again on every
supported-core lane. Drupal.org PHPUnit uses the `agency` group, which
includes the same test. A collected test is not proof of execution: read
the job result and confirm `testSeededFixtureFails` and
`testPublishedPackagePasses` both ran without skips.

## Install the candidate into a test host

Create a Drupal recommended-project test site using the supported minor you intend to
verify. From its root, register your Agency checkout as a Composer path repository:

```sh
composer config repositories.agency --json \
  '{"type":"path","url":"/absolute/path/to/agency","options":{"symlink":true,"versions":{"drupal/agency":"1.x-dev"}}}'
composer require 'drupal/agency:1.x-dev' --with-all-dependencies
```

Composer resolves the package's independent dependencies. Confirm that
`web/modules/contrib/agency` points to the candidate checkout.

Install matching `drupal/core-dev` dependencies. Functional tests require a disposable
database, an HTTP server serving the test host, `SIMPLETEST_DB`,
`SIMPLETEST_BASE_URL`, and a writable `BROWSERTEST_OUTPUT_DIRECTORY`. Use a
`mysql://` or `pgsql://` database URL; run anything that depends on transactions,
locks or savepoints on both engines before claiming it holds.

```sh
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/tests/src/Kernel"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/tests/src/Functional"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/modules/agency_host/tests/src"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/modules/agency_recruiting/tests"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/modules/agency_campaign/tests"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/tests/src/Unit/Workforce"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/modules/agency_expense/tests"
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" \
  "$PWD/web/modules/contrib/agency/modules/agency_agreement/tests"
```

The GitHub matrix installs the host identity/OAuth stack and Drush so
`modules/agency_host` unit tests run on every Drupal/PHP lane. Kernel tests
run on the 11.x lanes, including PostgreSQL 16. Binding-fingerprint pins for
the reviewed Externalauth 2.0.15 writers run as a unit test on every lane and
as a live kernel proof on 11.x; see
[EXTERNALAUTH_2.0.15.md](EXTERNALAUTH_2.0.15.md). Invitation smtp and
postmark_webhooks cases skip when those modules are not on the test host.
Clean-origin, evidence-lock and other PostgreSQL-only proofs skip on MySQL. See
[.github/workflows/ci.yml](../.github/workflows/ci.yml) for the complete
executable setup, including version assertions and test-result checks.

## Manual foundation smoke check

On an installed disposable site with Drush available:

```sh
vendor/bin/drush en agency_staff -y
vendor/bin/drush status
```

Confirm both Agency modules are enabled. The status report should identify the missing
agency display name. Supply a synthetic name through your host's reviewed configuration
and confirm the requirement clears. No participant, role, invitation or workforce
relationship should be created by enabling the modules. Run database updates
when upgrading an already enabled foundation: `agency_staff_update_10001`
installs the new internal entities and outbox tables without tenant defaults.
`agency_staff_update_10002` adds delivery-attempt counts and safe timestamps to
existing outbox rows. Run database updates before invoking the new health API.
The update preserves intents and delivery markers; it does not infer earlier
attempts. Rolling code back leaves these additive columns unused and preserves
evidence. Do not drop or rewrite outbox rows as a rollback procedure.

## Supported-core verification

The GitHub matrix pins Drupal 10.6 and 11.3 separately and tests the current supported
11.4 minor. Each functional lane also runs the tenant-string unit check. The Drupal 10.6 leg runs on the package's PHP 8.2 floor. The functional
job runs on the GitHub-hosted Ubuntu 24.04 runner and starts that image's MySQL 8.0
or PostgreSQL 16 server for the leg under test, then checks the server version.
MySQL covers 10.6, 11.3 and 11.4. One more leg repeats Drupal 11.3 on PostgreSQL 16
because lock, savepoint and transaction behavior differs by engine. Each job asserts
the resolved version and candidate symlink and requires executed, non-skipped
functional tests. A separate job runs PHPStan against a Drupal 11.4 analysis host.
Every database lane also executes the domain suite and explicitly checks that
the independent-writer, interrupted-drain, delivery-marker, summary-savepoint,
retention removal and Discovery access-denial cases ran without skips. The
same lanes run `tests/src/Unit/Workforce` and require the named staff
classification and invitation deny cases recorded in
[TEST_PORT.md](TEST_PORT.md). Contact preflight lanes also
require typed email, telephone and address refusals on
`ContactFields`. The domain suite also requires
all named operation-journal cases, including real SQL faults and independent
authority and retry workers. The same lanes run `modules/agency_host` unit and
kernel tests. The 11.x lanes, including
PostgreSQL 16, also run `modules/agency_recruiting`,
`modules/agency_campaign`, `modules/agency_expense` and
`modules/agency_agreement` kernel tests.
The PostgreSQL 16 leg must
execute the deferred clean-origin COMMIT-fault cases; it must not skip them.
smtp and postmark_webhooks cases may skip when those modules are absent.
The CLI-only workers accept kernel-test-prefixed connection options via
standard input and use synthetic identities and signing material.

The Drupal.org pipeline uses the standard contrib templates with previous-major,
previous-minor and maximum-PHP lanes enabled. PHPUnit runs the `agency` group so
MySQL lanes do not execute the explicit PostgreSQL host-qualification test. A
configuration file is not a successful pipeline: read both environments' actual
results before making compatibility or release claims.

Do not expand a version constraint merely because the code looks compatible.
Use the upstream lifecycle and tested dependencies to maintain the support boundary.
