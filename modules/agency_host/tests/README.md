# Discovery host failure cases

These failure cases precede implementation. Exercise them through synthetic
host requests and IdP HTTP responses, not real identities or invitations.

- Valid JWT with missing/disabled/rebound subject: no authority.
- Unknown `kid` inside the per-issuer forced JWKS refresh window: reject
  without another discovery or JWKS fetch. After the window, one refetch is
  enough to accept a rotated key. Concurrent unknown-kid requests share that
  one refetch.
- No Drupal account: no client, partner, staff or Profile provisioning needed.
- Wrong issuer, invalid proof and failed readback: refuse before writes.
- Contact address: never discovers a subject; explicit subject readback only.
- Effective restricted/mixed/service credential: cannot inherit full authority.
- CRM absence differs from outage; ambiguity, role mailbox, unspecified and
  do-not-contact deny. Only the recorded contact rule permits.
- Participant channel cannot invoke coordinator commands or caller actor IDs.
- Method, CSRF, body size and rate-limit refusals happen before mutations.
- Workspace reads cannot consume the separate invitation acceptance budget.
- Every response is private/no-store and no-referrer, including denials.
- Routing and OAuth failures return stable refusal envelopes, not core exception messages.
- Console named-route checks use the current request when none is passed; an
  empty request stack returns an uncacheable denial without throwing. An explicit
  request takes precedence, and missing cookie authority still denies access.
- A response-layer server failure remains unavailable on reads and uncertain on mutations.
- Missing, unrelated and revoked records have the same public denial shape.
- Conflict/timeouts/terminal retry do not become success or recreate grants.
- Missing configuration never activates a tenant policy or grants roles.
- A lost mutation response must not duplicate the transition on same-key retry.
- A stale Stop must still remove access; failed persistence must not claim Stop succeeded.
- After failed Stop, coordinator suspension must deny subsequent participant reads.
- Same-key preparation for a changed verified subject must refuse, even with the same verified contact; exact retry must replay.
- Unsupported or missing invitation language must be refused before identity lookup.
- Valid wider domain policy must reach actual workspace projections unchanged.
- Overlapping identical Prepare captures must dispatch the retained key and
  recover the same receipt, never report a definitive refusal caused by a new key.
- Invitation preview and reload must not issue an invitation. The entry and
  confirmation have different labels; an earlier preview cannot assert no
  invitation exists after another request issues one.
- Last agency action shows elapsed time and its exact timestamp, including
  second/minute/hour/day boundaries; a future observation has no negative age.

The host Kernel suite also connects coordinator proposal, participant pause and
resume, reassignment with former-owner denial, explicit consent, closure,
summary revocation and time-based expiry through public controllers. A captured
committed Stop receipt retains its original pending-delivery evidence even after
summary expiry. These are controller integration checks, not browser evidence.

Domain transition concurrency belongs to Agency's suite. This host suite also
checks binding-history serialization against decorated and direct authmap writes
on independent PostgreSQL connections. Browser/accessibility and live IdP
qualification remain integration gates after synthetic host verification.

## Runtime configuration preflight

`DiscoveryHostTest::testRuntimeReadiness*` exercises missing configuration,
qualified and absent history, an unresolved independently committed guard, and
complete synthetic configuration on PostgreSQL 16. It compares retained tables
before and after inspection and verifies no IdP lookup or secret output. The
complete case also discovers and autowires `agency:readiness` through Drush,
executes its annotated command, parses the JSON, and checks success/failure exit
codes. Complete prerequisites pass with participant entry disabled; the reported
entry state changes independently and the preflight does not enable it. A fixture
with missing configuration must fail, not silently qualify.

The policy fixture used for a successful preflight explicitly sets `langcode:
en`; Drupal requires it for translatable configuration. Duplicate close-reason
identifiers are refused. These are local configuration checks, not connected
IdP, browser, delivery or speech acceptance. See
[the runtime guide](../../../../../docs/AGENCY_RUNTIME_CONFIGURATION.md).

## PostgreSQL integration suite

`DiscoveryHostTest::testDisabledPortalClient*` covers real route parameter
conversion (including provider aliases), valid callback state, effective
settings overrides, and actual contributed authorization/link completion with
a retained enabled entity after disablement or deletion. Successful linking
and staff routes are positive controls; refused completion must preserve user
claims, mappings and session state. These tests do not prove atomic cutover
fencing or qualify historical identity records.

The Kernel host reads `fixtures/synthetic-host.json`. Its default tenant is
`Synthetic Agency Host`, and its only IdP subjects are `person-one` and
`person-two`. Run the Kernel integration suite through PostgreSQL with:

```bash
docker run --rm -d --name chronicle-agency-test-pg \
  -e POSTGRES_PASSWORD=synthetic \
  -e POSTGRES_DB=agency_host_test \
  -p 15436:5432 postgres:16
SIMPLETEST_DB=pgsql://postgres:synthetic@127.0.0.1:15436/agency_host_test \
  vendor/bin/phpunit -c web/core \
  web/modules/custom/agency_host/tests/src/Kernel
docker stop chronicle-agency-test-pg
```

`testSyntheticFailureInjection` makes the synthetic IdP return an outage and
then makes the test-only recording connection refuse inserts. It proves `identity_unavailable`
and `recording_unavailable` without adding runtime failure switches.

`testContractBoundaryEnvelope` supplies `P2W`, exactly 1,500 retained-text
characters, contact kind `chat`, and a 259-character owner name. Agency policy
accepts the first three; real participant workspace reads preserve them.
A tenant display name exceeding 200 characters also reaches the real workspace.
The additional owner-name envelope tests transport preservation only. Chronicle's User schema cannot originate the long owner name, so
that value is transport evidence only. The default tenant seed remains inside
the currently merged UI's narrower bounds.

Preparation requires the exact `language: "en"` input key. The controller
refuses other or missing values before IdP lookup and strips the accepted key
before calling Agency, because the pinned package has no locale field. This makes the
slice explicitly English-only; ES and pt-BR remain blocked on catalogs and
named operator reviewers.

## Runnable synthetic HTTP host

Use a dedicated disposable checkout and an empty PostgreSQL database named
`agency_host_http_test` on loopback. This is a real Drupal HTTP host with its
normal OAuth, routing and persistence. Participant proof validation, current IdP readback and a recording-failure connection
are supplied by the hidden `agency_host_synthetic` test module.
The fixture is not a demonstration of a real IdP sign-in or credential setup.
Never enable the test module in an application environment.

Run on the development server, using existing dependencies and a PostgreSQL 16 runtime:

```bash
docker run --rm -d --name agency-synthetic-http-pg \
  -e POSTGRES_PASSWORD=synthetic-only \
  -e POSTGRES_DB=agency_host_http_test \
  -p 127.0.0.1:15439:5432 postgres:16
export AGENCY_SYNTHETIC_DB=pgsql://postgres:synthetic-only@127.0.0.1:15439/agency_host_http_test
test ! -e web/sites/default/settings.local.php
cp web/modules/custom/agency_host/tests/fixtures/settings.synthetic.php \
  web/sites/default/settings.local.php
php -d memory_limit=512M vendor/drush/drush/drush.php site:install minimal --account-name=synthetic-admin \
  --account-pass=synthetic-only --site-name='Synthetic Agency Host' -y
php -d memory_limit=512M vendor/drush/drush/drush.php pm:enable agency_host_synthetic -y
php -d memory_limit=512M vendor/drush/drush/drush.php php:script "$(pwd)/web/modules/custom/agency_host/tests/fixtures/seed.php" \
  > /tmp/agency-synthetic-seed.json
(cd web && php -d memory_limit=512M -S 127.0.0.1:8889 .ht.router.php)
```

The server's exact start command is the last line. Starting inside `web` is required
for Drupal plugin discovery. Keep `AGENCY_SYNTHETIC_DB`
in that process environment. The seed contains only `person-one` and
`person-two`, two invitation tokens and their fixture identity proofs, plus the
synthetic tenant name. It gives the participant subjects no Drupal accounts or staff privileges.
`synthetic-coordinator` / `synthetic-only` is the fixture coordinator login.

Obtain the real Drupal BFF token from the running host:

```bash
curl -sS http://127.0.0.1:8889/oauth/token \
  --data-urlencode grant_type=client_credentials \
  --data-urlencode client_id=synthetic-bff \
  --data-urlencode client_secret=synthetic-only \
  --data-urlencode scope=discovery
```

For participant requests supply `Authorization: Bearer <access_token>` and
`X-Agency-Identity-Token: <identity_token from seed>`. Inspect
`GET /api/agency/discovery/invitations/<invitation_token>`; accept with
`POST /api/agency/discovery/invitations/<invitation_token>/accept` and
`{"key":"synthetic-accept-one"}`. Then read
`GET /api/agency/discovery/workspaces/<relationship_id>`. The other subject's
proof must receive the same denial as an absent relationship.

Inject and clear faults without a runtime API or real remote request:

```bash
AGENCY_SYNTHETIC_FAULT=identity php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/fault.php"
AGENCY_SYNTHETIC_FAULT=recording php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/fault.php"
AGENCY_SYNTHETIC_FAULT=none php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/fault.php"
```

Identity failure returns `identity_unavailable` through the production identity
provider on participant reads and coordinator preparation. Recording failure refuses
new inserts through the public outbox constructor connection seam; mutations return `recording_unavailable` without
claiming success. Existing evidence rows remain intact. Stop the HTTP process
and remove only this disposable database/container after testing.

## Evidence delivery monitoring failure cases

- Public aggregate health must use an explicit positive threshold; zero is invalid.
- A degraded queue delivers a minimized alert to the configured trusted receiver.
- Failed alert delivery remains retryable; it must not mark the transition delivered.
- Draining to the keyed Audit Chain produces a delivered recovery alert.
- Unavailable telemetry is an outage, never an empty healthy queue.

The runnable synthetic host can exercise real alert delivery and keyed recovery:

```bash
python3 web/modules/custom/agency_host/tests/fixtures/alert-receiver.py
# In another shell, with the same AGENCY_SYNTHETIC_DB:
export DRUPAL_AGENCY_EVIDENCE_ALERT_URL=http://127.0.0.1:8890/alerts
export DRUPAL_AGENCY_EVIDENCE_ALERT_AFTER_SECONDS=300
touch /tmp/agency-synthetic-alert-receiver-fail
AGENCY_SYNTHETIC_EVIDENCE=check php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/evidence.php"
rm /tmp/agency-synthetic-alert-receiver-fail
AGENCY_SYNTHETIC_EVIDENCE=check php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/evidence.php"
AGENCY_SYNTHETIC_EVIDENCE=recover php -d memory_limit=512M vendor/drush/drush/drush.php php:script \
  "$(pwd)/web/modules/custom/agency_host/tests/fixtures/evidence.php"
cat /tmp/agency-synthetic-alerts.jsonl
```

The first check must fail delivery without losing the retry. The second sends
`degraded`; keyed outbox drain then sends `recovered`. The 300-second threshold
is explicitly chosen for this fixture, not a runtime default or ratified live policy. The fixture permits loopback
HTTP only with `agency_synthetic_host` explicitly enabled.

`fixtures/binding-probe.php` exercises public authmap replacement and deletion
against the two-subject seed. It requires available never-bound/bound positive controls and typed `not_found`
denials after replacement, ordinary relinking and deletion. A uniform outage
cannot satisfy this probe. Run it only against a freshly qualified synthetic
origin; production history qualification remains a separate pre-live gate.

Export actual current participant responses after committing the exporter:

```bash
python3 web/modules/custom/agency_host/tests/fixtures/export-projections.py \
  /tmp/agency-participant-projections.json
```

The artifact names its exporter SHA and requested package SHA, capture command,
real HTTP envelopes and limited current-state coverage. Producing server SHAs
are explicitly null and producer verification is not established; local Git and
lock metadata cannot attest the process listening on port 8889. It refuses a dirty tracked worktree and
contains no OAuth credential or identity proof. It is captured evidence, not
a handwritten or exhaustive golden catalog.

## Cold production-image qualification

The CI `Agency cold production image` job uses the unmodified production
Dockerfile at reviewed core `c4b7da5afc77f649c866a9cee3e3f652dcde4ccd` and the exact
PR head. It creates a fresh BuildKit builder, supplies the dedicated Agency
reader only as a build secret, checks the runtime package reference without
network access, and scans image configuration, history and every layer for the
credential and auth files. Image and builder cleanup precede a pass receipt.
The job is separate from cold Composer DIST/SOURCE checks and does not deploy.
The generic qualifier and its archive-boundary tests were imported unchanged
from the independently reviewed rollout artifact `96496635ccaffb8a08858d7455d1af90a52e021a`, with safe path-only failure
diagnostics from reviewed artifact `1f726f1779d9da3df7336ae171a152376dfa4108`
and the exact path/size/hash upstream empty-fixture exception from independently
reviewed artifact `abb3c323dae07111e45f812304ba00bfbd612f11`.

```bash
PYTHONDONTWRITEBYTECODE=1 python3 scripts/test-qualify-agency-image.py
```

## Binding history qualification boundary

Installing the module does **not** qualify existing identity history. The host
records sticky issuer/version-scoped revocations and all observed UID/provider
associations through Externalauth's public mutation service, the admin deletion
form, and actual provider deletion. It freshly checks retained accounts and
serializes current-map inspection and changes on the primary database.

Discovery qualification requires PostgreSQL. The host's independent primary
connection records permanent epoch invalidations and durable verification guards.
It arms a unique guard before observing map/config drift. A failed invalidation
therefore leaves a durable unresolved guard even if Agency rolls back and the old
map/config is restored. A normal uniqueness refusal may resolve its own guard only
after a confirmed clean rollback; unknown storage or rollback failures cannot.
Ordinary unconfigured host login/linking and deletion remain possible, while
Discovery stays unavailable. Disabling Discovery does not bypass history tracking.

A production qualification must be a separately reviewed migration under a write
freeze. It must reconcile **all** covered Externalauth mappings, including orphan
UIDs, and establish authoritative pre-bootstrap revocation coverage or a provably
new identity origin. An empty current table is not evidence that a subject was
never bound. No production qualification command or convenience boolean is
provided in this slice. Synthetic seed qualification is confined to the hidden
test module and a fresh isolated database. Real production qualification remains
blocked on that reviewed migration and history evidence.

Qualification binds exact reviewed mutation-source hashes, dependency references,
installed schema state, issuer/version, provider authority endpoints, the HMAC key
identity even with no mappings, and the current mapping snapshot. Legacy OIDC
mapping-table presence refuses coverage even if updates report completion. Key or
authority changes require explicit requalification preserving prior revocations;
ordinary linking never resets an invalidated epoch. Arbitrary privileged database
writes that change and restore state wholly between observations are unsupported;
the fence detects observed drift and serializes writes during authority decisions,
it is not a database-administrator tamper log.

The PostgreSQL suite uses actual independent connections for outer-rollback
persistence, failed invalidation INSERTs, and conflicting covered/raw authmap
writes. SQLite substitutes an in-memory invalidation port for decision coverage
and skips those PostgreSQL-specific cases; it is not durability evidence.

## Dedicated identity authority failures

- Correctly signed Agency proofs must not pass the ordinary portal/delegated
  validator; portal proofs must not pass the Agency validator, even when both
  issuers use the same signing key. Wrong audience, subject, expiry or signature
  still denies. Missing Agency authority never falls back to portal settings.
- In unbound-only mode, any existing covered Drupal authmap denies identity,
  including an active matching account. Unbound subjects remain eligible.
- Issuer, audience or binding-mode drift invalidates qualified coverage.
  Configuration inspection must agree with request-time identity checks.

## Scheduled evidence worker failure contract

Before adding the trusted CLI worker, exercise these failures against the real
PostgreSQL outbox and public health API: absent monitoring configuration, audit
append failure, failed degraded/recovery alert delivery, and another connection
holding the worker lock. No overlap may consume or modify evidence. Failed
append and recovery notification must remain retryable and must not produce a
successful worker result. Successful recovery must drain no more than the fixed
batch and retain ordinary outbox history. Also exercise a claim failure before
attempt recording, a second connection holding the monitor lock, stale cached
transition state, and real Drush discovery and exit status. These checks use
synthetic data only.

## Initial-origin qualification failures

Exercise stale approvals, reused issuer or incarnation, active old coverage,
concurrent authmap/configuration writers and commits between the two fences.
Fail each reservation/completion write and its deferred COMMIT. A failed
coverage commit must leave the independent reservation and guard, publish no
new coverage, and run root callbacks with FALSE. Lost completion acknowledgements
require a fresh primary read; an uncommitted completion on the old session must
not look successful. Also verify protected Drush input, disabled entry, additive
schema updates, historical completed retries and permanent invalidation.

The PostgreSQL commit-failure regression separately exercises shutdown cleanup
and subsequent connection reuse. All fixtures and injected faults are synthetic.
