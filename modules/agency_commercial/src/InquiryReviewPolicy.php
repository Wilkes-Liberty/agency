<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Storage-free human review of a declared inquiry receipt.
 *
 * Webform, Jira delivery state and Drupal roles stay on the host.
 * Access/support forms, stale tokens, machine callers and undeclared
 * inquiry forms fail closed and never create an opportunity.
 */
final class InquiryReviewPolicy {

  /**
   * Forms that must never become sales opportunities.
   */
  private const NOT_INQUIRY = ['account_request', 'ticket_intake'];

  /**
   * Whether a human may approve a source receipt into CRM.
   *
   * @param array<string, mixed> $facts
   *   Host adapter facts. Extra keys are unread.
   *
   * @return array{decision: string, reason: ?string}
   *   allow, or deny with a stable reason.
   */
  public function approve(array $facts): array {
    $form = is_string($facts['form_id'] ?? NULL) ? $facts['form_id'] : '';
    if (($facts['declared'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'undeclared_form'];
    }
    if (in_array($form, self::NOT_INQUIRY, TRUE)
      || ($facts['inquiry_form'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'not_inquiry'];
    }
    if (($facts['crm_mode'] ?? '') !== 'review') {
      return ['decision' => 'deny', 'reason' => 'crm_mode'];
    }
    if (($facts['permitted'] ?? FALSE) !== TRUE
      || ($facts['machine_principal'] ?? FALSE) === TRUE) {
      return ['decision' => 'deny', 'reason' => 'forbidden'];
    }
    if (($facts['token_match'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'stale_token'];
    }
    if (($facts['delivery_certain'] ?? TRUE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'uncertain_delivery'];
    }
    if (($facts['lock_available'] ?? TRUE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'concurrent_source'];
    }
    if (($facts['audit'] ?? FALSE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'audit_rollback'];
    }
    return ['decision' => 'allow', 'reason' => NULL];
  }

}
