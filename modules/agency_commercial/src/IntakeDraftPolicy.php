<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Storage-free CRM draft gate for stored receipts.
 *
 * Webform, Jira, outbox and attribution stay on the host. Undeclared
 * forms write nothing. Claim-only intake never drafts an opportunity.
 */
final class IntakeDraftPolicy {

  /**
   * Whether a stored receipt may create a CRM draft.
   *
   * @param array<string, mixed> $facts
   *   Host adapter facts. Extra keys are unread.
   *
   * @return array{decision: string, reason: ?string}
   *   allow, or deny with a stable reason. No opportunity payload.
   */
  public function draft(array $facts): array {
    if (($facts['declared'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'undeclared_form'];
    }
    if (($facts['claim_only'] ?? FALSE) === TRUE) {
      return ['decision' => 'deny', 'reason' => 'claim_only'];
    }
    if (($facts['owner_present'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'owner_missing'];
    }
    if (($facts['audit'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'audit_rollback'];
    }
    if (($facts['lock_available'] ?? TRUE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'concurrent_first_arrival'];
    }
    if (($facts['crm_mode'] ?? 'draft') !== 'draft') {
      return ['decision' => 'deny', 'reason' => 'crm_mode'];
    }
    return ['decision' => 'allow', 'reason' => NULL];
  }

}
