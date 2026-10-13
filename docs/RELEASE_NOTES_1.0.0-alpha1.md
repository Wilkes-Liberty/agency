# Agency 1.0.0-alpha1 — draft release notes

This initial prerelease provides the invitation-only Discovery domain. It is an
integration candidate, with APIs and host integration still subject to change.
It is not a complete workforce, recruitment, agreement or payment application.

## Included

- Tenant-neutral `agency` configuration and the `agency_staff` Discovery domain.
- Prospective relationships with explicit owners, next actions, response,
  pause/resume, withdrawal, closure and consent to a proposed next step.
- Exact issuer/subject identity binding, single-use invitations, fresh access
  checks, scoped workspace reads and separately authorized expiring summaries.
- Durable transactional evidence, denial-first access reduction and retryable
  delivery through Audit Chain's public keyed logger.
- Minimized evidence health: pending counts, oldest-undelivered age, safe failure
  timing and an operator-configured alert threshold.
- Typed support contacts, calendar-aware policy durations and preparation retries
  bound to the freshly verified intended principal.

## Integration and upgrade requirements

The host supplies authentication, current identity/contact eligibility, private
transport and screens. The default identity adapter denies access. Enabling the
package creates no accounts, roles, relationships or messages. Discovery grants
no employment classification, assignment or staff access.

Configure the agency display name, operating periods, retained-information text,
typed support contact and close reasons before use. No tenant policy values ship
as defaults. A summary viewing window controls access; it is not a deletion date.

Run pending Drupal database updates when moving from an earlier development
snapshot. The additive evidence-health update preserves existing intent and
delivery state. Preparation receipts created before principal-intent binding
fail closed when they cannot be safely replayed; reconcile them through the host
without silently creating a replacement invitation.

Positive mutations require durable local evidence or roll back. Access-reducing
commands require durable denial and intent; downstream delivery failure leaves
access denied and evidence pending. Monitor the public health projection and
provide an accountable response to delayed evidence. Hosts must not read Agency
internal tables to implement health or business decisions.

## Compatibility and limits

Requires PHP 8.2+, Drupal `^10.6 || ^11.3`, Audit Chain `^1.10` and Field Guard
`^1.3`. The supported-core CI matrix includes MySQL 8.0 and PostgreSQL 16;
independent-connection tests exercise transaction and drain behavior. Host/browser,
identity-provider lifecycle, accessibility and deployment acceptance are separate
integration responsibilities.

This package does not deliver notifications or expose an Agency MCP tool.
Application ownership/claiming, interview decisions, signatures, roster access,
clearance, payroll and payments are outside this release. No security-advisory
coverage claim follows from prerelease publication; see [Security](../SECURITY.md).

Publication requires reviewed source/history, passing release checks, maintainer
authorization and verified package availability. See [Maintaining](MAINTAINING.md).
