<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Storage-free human release of one held billing instruction.
 *
 * HTTP, OAuth tokens, CSRF forms, flood and audit stay on the host
 * adapter. This policy names the refuse/retry contract from
 * InstructionReleaseTest without talking to a payment service.
 */
final class InstructionReleasePolicy {

  /**
   * States a human may release from. Accepted and refused are terminal.
   */
  public const RELEASABLE = ['held', 'uncertain', 'failed'];

  /**
   * Seconds after which an unfinished sending claim may be retried.
   */
  public const STALE_AFTER = 300;

  /**
   * Conflict codes that refuse rather than retry.
   */
  private const CONFLICTS = ['ineligible', 'idempotency_conflict'];

  /**
   * Decides whether a release may proceed, or names the recorded outcome.
   *
   * @param array<string, mixed> $facts
   *   Host adapter facts. Extra keys are unread.
   *
   * @return array{state: string, reason: ?string}
   *   Dispatch state after the attempt. Secrets never appear.
   *
   * @throws \LogicException
   *   Not configured, not found, in progress, not releasable, or forbidden
   *   when nothing was sent.
   * @throws \RuntimeException
   *   Claim unrecorded or outcome unrecorded when audit cannot persist.
   */
  public function release(array $facts): array {
    if (($facts['machine_principal'] ?? FALSE) === TRUE
      || ($facts['permitted'] ?? FALSE) !== TRUE
      || ($facts['csrf'] ?? TRUE) !== TRUE) {
      throw new \LogicException('forbidden');
    }
    if (($facts['enrolled'] ?? FALSE) !== TRUE
      || ($facts['https'] ?? FALSE) !== TRUE
      || ($facts['environment_match'] ?? FALSE) !== TRUE) {
      throw new \LogicException('not_configured');
    }
    $id = $facts['instruction_id'] ?? NULL;
    if (!is_string($id) || !$this->uuid($id)
      || ($facts['profile_match'] ?? FALSE) !== TRUE
      || ($facts['digest_match'] ?? FALSE) !== TRUE) {
      throw new \LogicException('not_found');
    }
    $state = is_string($facts['state'] ?? NULL) ? $facts['state'] : '';
    $stale = ($facts['stale_sending'] ?? FALSE) === TRUE;
    if ($state === 'sending' && !$stale) {
      throw new \LogicException('in_progress');
    }
    if ($state !== 'sending' && !in_array($state, self::RELEASABLE, TRUE)) {
      throw new \LogicException('not_releasable');
    }
    if (($facts['claim_audit'] ?? FALSE) !== TRUE) {
      throw new \RuntimeException('claim_unrecorded');
    }
    $outcome = $this->outcome($facts);
    if (($facts['outcome_audit'] ?? FALSE) !== TRUE) {
      throw new \RuntimeException('outcome_unrecorded');
    }
    if (isset($outcome['client_secret']) || isset($outcome['access_token'])) {
      throw new \LogicException('forbidden');
    }
    return $outcome;
  }

  /**
   * Whether a listed row may be released now.
   *
   * @param array<string, mixed> $row
   *   Listed dispatch values.
   */
  public function releasable(array $row): bool {
    $state = is_string($row['state'] ?? NULL) ? $row['state'] : '';
    return in_array($state, self::RELEASABLE, TRUE)
      || ($state === 'sending' && ($row['stale_sending'] ?? FALSE) === TRUE);
  }

  /**
   * Maps a scripted payment response onto a dispatch outcome.
   *
   * @param array<string, mixed> $facts
   *   Host adapter facts after the claim.
   *
   * @return array{state: string, reason: ?string}
   *   Recorded outcome. Retryable 404 stays failed, not refused.
   */
  private function outcome(array $facts): array {
    $status = $facts['http_status'] ?? NULL;
    $code = $facts['error'] ?? NULL;
    if ($status === 200 && ($facts['obligation_valid'] ?? FALSE) === TRUE) {
      return ['state' => 'accepted', 'reason' => NULL];
    }
    if ($status === 200) {
      return ['state' => 'uncertain', 'reason' => 'unrecognized_response'];
    }
    if ($status === 400) {
      return ['state' => 'refused', 'reason' => 'fault_invalid_request'];
    }
    if ($status === 401) {
      return ['state' => 'failed', 'reason' => 'issuer_unauthorized'];
    }
    if ($status === 404) {
      return ['state' => 'failed', 'reason' => 'not_found_or_denied'];
    }
    if ($status === 409 && is_string($code) && in_array($code, self::CONFLICTS, TRUE)) {
      return ['state' => 'refused', 'reason' => $code];
    }
    if (is_int($status)) {
      return ['state' => 'uncertain', 'reason' => 'http_' . $status];
    }
    return ['state' => 'uncertain', 'reason' => 'transport'];
  }

  /**
   * UUID shape used by instruction identifiers.
   */
  private function uuid(string $value): bool {
    return (bool) preg_match(
      '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
      $value,
    );
  }

}
