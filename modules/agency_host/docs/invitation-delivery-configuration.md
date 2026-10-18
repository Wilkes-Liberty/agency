# Invitation delivery configuration

The [delivery contract](invitation-delivery-contract.md) separates invitation
issuance, encrypted custody, coordinator approval, mail handoff and participant
acceptance. Delivery is disabled by default. Existing captured-link operations
remain available. No existing relationship is automatically assigned a recipient.

## Installation and upgrade

Install the locked dependencies and the normal host module dependencies, including
`chronicle_audit_coverage`. New installations create the host-owned
`agency_host_delivery` table. Existing installations run
`agency_host_update_10002`; it adds this table without changing Agency data,
backfilling principals or granting permissions. Rebuild routes/caches after the
update. Explicitly enable `chronicle_audit_coverage` before updating an older
host that lacks it. The SMTP module is required only for qualified `smtp` mode,
not for synthetic capture through the default plain-text formatter. No exported
configuration enables mail.

Host schema `10002` retains the reviewed identity-history epoch of `10001`:
the update adds delivery storage and changes no identity writer or binding
history. Existing valid qualification, sticky revocations and exact-command
receipts remain intact without rewriting stored fingerprints or epochs. All
other source, provider, mapping, configuration and schema checks still apply;
unknown later host schemas require separate review. Missing, mismatched or
invalidated qualification is never repaired by this compatibility rule.

Verify upgrade continuity from the prior installation before enabling delivery:
replay an observed preparation and issuance with their original command keys,
check existing access/revocation behavior, and confirm a second update is a
no-op. Preserve the original receipts. If an earlier candidate already
invalidated qualification or recorded a different schema fingerprint, stop and
use the reviewed qualification process; do not reseed, overwrite its fingerprint
or erase an invalidation to make the upgrade pass.

The restricted `approve agency discovery invitation delivery` permission must be
granted separately to an eligible human coordinator. Assignment and permission
are checked again before preview and dispatch. Participant roles are unchanged.

## Private settings

`agency_host_invitation_origin` remains an explicitly selected HTTPS origin.
`agency_host_invitation_delivery` is a private settings array:

| Key | Required value |
| --- | --- |
| `mode` | `capture` for synthetic capture or explicitly qualified `smtp` |
| `key_id` | Identifier of the current encryption key |
| `keys` | Key identifier to base64-encoded 32-byte private key; retain old keys while their payloads remain needed |
| `payload_retention_seconds` | Explicitly approved positive integer; no shipped operational default |
| `sender.email` | Approved tenant sender address |
| `sender.name` | Approved tenant display name |
| `sender.reply_to` | Approved support reply address |
| `deployment_gate` | Explicit `TRUE` for SMTP; disable before database restore and until reconciliation finishes |
| `smtp_qualification` | SHA-256 semantic fingerprint of the qualified non-secret routing projection below |

Provision keys through the existing private settings/secret mechanism. Never put
them in exported Drupal configuration. Payloads use AES-256-GCM with a fresh nonce,
key identifier and authenticated row/relationship context. Cron only removes
expired encrypted payloads; it never sends, retries or removes attempt fences.
Missing retention, missing keys and unavailable old keys refuse custody/recovery.

The existing public `postmark_webhooks.suppression_policy` must be available and
enabled. Its current decision and the normal mail-alter suppression hook must
allow the recipient. Disabled or unavailable suppression is not consent. The
identity directory and CRM contact permission remain independent requirements.

SMTP qualification covers the effective values of `smtp_on`, `smtp_host`,
`smtp_hostbackup`, `smtp_port`, `smtp_protocol`, `smtp_autotls`, `smtp_timeout`,
`smtp_from`, `smtp_fromname`, `smtp_client_hostname`, `smtp_client_helo`,
`smtp_allowhtml`, `smtp_reroute_address`, `smtp_debugging`, `smtp_keepalive` and
`smtp_queue`. Use `DeliveryConfiguration::fingerprint()` on this exact associative
projection, including `NULL` for an absent setting. Credentials are excluded.
Qualification does not approve credentials, provider routing or real delivery.

The projection also contains `handoff_class` with the exact value
`Drupal\agency_host\Delivery\PrivateSmtp`. Only the existing
`SMTPMailSystem` formatter and that host-owned handoff are qualified. The
handoff preserves parent envelope construction and avoids the general SMTP
helper's provider-error logging. Failure is unknown; there is no transport
fallback. Explicit sender settings
must match the SMTP sender configuration. One host, required TLS (`ssl` or `tls`),
default certificate verification and a positive timeout of at most 30 seconds
are required. Backup routing, rerouting, HTML/attachments, debug output,
keepalive and queueing are refused. Formatting and mail-alter hooks run before
preview; a changed final envelope invalidates approval. Sender, Return-Path,
Reply-to, permitted headers and selected mail plugin are visible in the review.

## Recovery and rollback

An unresolved issuance can be reconciled only by the exact original command.
A committed issuance whose token was not durably captured is unavailable for
delivery. Neither receipt replay nor email equality reconstructs token custody.
An attempted delivery is never automatically repeated, including on a new
approval key. Accepted by transport does not mean delivered or accepted by the
participant. The transport may provide no correlation sufficient to resolve an
unknown outcome; this implementation does not fabricate one.

The responsible coordinator must review available mail evidence before a
separate explicit replacement-generation decision. Earlier mail may already be
in flight. Its attempt fence remains intact, and revocation cannot recall mail.

A data-preserving code rollback keeps the ledger and its fences. A database
restore can lose newer attempts: disable delivery externally before restore and
keep it disabled until retained attempt/provider evidence is reconciled. Do not
drop the host ledger, reset it, or enable a restored backup as an automatic retry.
Rollback code must retain this reviewed identity-schema compatibility when the
installed schema remains `10002`; older code that compares the raw schema number
will refuse existing qualification. Do not lower schema versions to bypass that
refusal.

## Synthetic qualification

Use a separate disposable host with `settings.delivery.synthetic.php`, the
existing synthetic host module and the public suppression module. Its public
fixture key and one-hour payload interval are synthetic only. The adapter opens
no mail connection, even if the normal site mail plugin is SMTP. Its approved
message remains encrypted in the host ledger and is labelled captured for
testing, never sent or delivered.

The synthetic-only state control `agency_synthetic_delivery_fault` accepts
`none`, `preparation` or `unknown`. `preparation` refuses before attempt;
`unknown` records one simulated attempt and an uncertain outcome. The minimized
`agency_synthetic_delivery_attempts` counter proves retries did not call transport
again; it stores no recipient, token or message. Restore `none` after each case.
Prepare a new relationship through the real coordinator form so the explicit
identity/contact mapping is established. The legacy seeded relationships have
no delivery mapping and must remain unsendable through this adapter.

Qualification includes real Form API CSRF, explicit approval, unchanged public
domain state after refusals, two independent PostgreSQL connections for the
stale-review claim race, actual SMTP pre-send envelope capture without sockets,
keyboard/focus, 320-pixel layout and separately observed screen-reader output.
Synthetic evidence does not authorize or prove deployed mail delivery.
