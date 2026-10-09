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
```

See [.github/workflows/ci.yml](../.github/workflows/ci.yml) for the complete executable
setup, including version assertions and test-result checks.

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

## Supported-core verification

The GitHub matrix pins Drupal 10.6 and 11.3 separately and tests the current supported
11.4 minor. The Drupal 10.6 leg runs on the package's PHP 8.2 floor. Those legs use
MySQL 8.0; one more leg repeats Drupal 11.3 on PostgreSQL 16 because lock, savepoint
and transaction behavior differs by engine. Each job asserts the resolved version and
candidate symlink and requires executed, non-skipped functional tests. A separate job
runs PHPStan against a Drupal 11.4 analysis host.
Every database lane also executes the domain suite and explicitly checks that
the independent-writer, delivery-marker and summary-savepoint cases ran without
skips. The CLI-only workers accept kernel-test-prefixed connection options via
standard input and use synthetic identities and signing material.

The Drupal.org pipeline uses the standard contrib templates with previous-major and
previous-minor lanes enabled. A configuration file is not a successful pipeline:
read both environments' actual results before making compatibility or release claims.

Do not expand a version constraint merely because the code looks compatible.
Use the upstream lifecycle and tested dependencies to maintain the support boundary.
