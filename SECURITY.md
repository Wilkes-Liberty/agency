# Security policy

## Reporting a vulnerability

Agency is in development. Security advisory coverage is not automatic on a
stable release: the project must meet Drupal's eligibility and opt-in rules.
Confirm coverage for the affected release on the
[project page](https://www.drupal.org/project/agency).

For development or uncovered releases, contact the maintainer privately at
<jmcerda@wilkesliberty.com> with the affected version and reproduction steps.
For releases explicitly covered by Drupal's advisory policy, follow the Drupal
Security Team reporting process:

<https://www.drupal.org/security-team/report-issue>

Coverage policy: <https://www.drupal.org/docs/develop/managing-a-drupalorg-theme-module-or-distribution-project/security-coverage>.

Do not open a public issue for a suspected vulnerability.

For anything that is not a vulnerability, use the public issue queue:
<https://www.drupal.org/project/issues/agency>

## Scope

Agency handles business relationships and workflows that may contain protected
or commercially sensitive data. Reports are in scope when Agency grants access
without the required relationship, exposes one participant's record to another,
accepts a revoked or replayed entitlement, or records a governed action as
successful when required evidence was not persisted.

Tenant configuration must supply the agency name and operating policy. Missing
configuration is reported as an error and must not be treated as permission to
invite someone or render a participant workspace.
