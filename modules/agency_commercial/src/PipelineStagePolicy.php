<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Storage-free opportunity stage order.
 *
 * Host records, Views and Gin stay on the adapter. Lost and parked
 * cannot skip unfinished service stages. Direct saves cannot mark a
 * record won.
 */
final class PipelineStagePolicy {

  /**
   * Forward service order. inbound is the reopen target from lost.
   */
  public const STAGES = [
    'inbound',
    'qualified',
    'discovery',
    'sow',
    'won',
  ];

  /**
   * Parked and lost are holding states, not skips.
   */
  public const HOLDING = ['lost', 'parked'];

  /**
   * Whether a move from one stage to another is allowed.
   *
   * @param array<string, mixed> $facts
   *   Host adapter facts. Extra keys are unread.
   *
   * @return array{decision: string, reason: ?string}
   *   allow, or deny with a stable reason.
   */
  public function transition(array $facts): array {
    if (($facts['delete'] ?? FALSE) === TRUE) {
      return ['decision' => 'deny', 'reason' => 'delete_denied'];
    }
    if (($facts['direct_save'] ?? FALSE) === TRUE) {
      return ['decision' => 'deny', 'reason' => 'direct_stage_bypass'];
    }
    if (($facts['owner_match'] ?? TRUE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'wrong_owner'];
    }
    if (($facts['revision_match'] ?? TRUE) !== TRUE) {
      return ['decision' => 'deny', 'reason' => 'stale_revision'];
    }
    $from = is_string($facts['from'] ?? NULL) ? $facts['from'] : '';
    $to = is_string($facts['to'] ?? NULL) ? $facts['to'] : '';
    if ($from === $to) {
      return ['decision' => 'deny', 'reason' => 'same_stage'];
    }
    if ($from === 'lost' && $to === 'inbound') {
      return ['decision' => 'allow', 'reason' => NULL];
    }
    if ($from === 'parked' && $this->index($to) !== NULL
      && $this->index($to) < $this->index('sow')) {
      return ['decision' => 'allow', 'reason' => NULL];
    }
    if (in_array($from, self::HOLDING, TRUE)
      && $this->index($to) !== NULL
      && $this->index($to) >= $this->index('sow')) {
      return ['decision' => 'deny', 'reason' => 'skip_from_holding'];
    }
    $from_i = $this->index($from);
    $to_i = $this->index($to);
    if ($from_i === NULL || $to_i === NULL || $to_i !== $from_i + 1) {
      if (in_array($to, self::HOLDING, TRUE) && $from_i !== NULL) {
        return ['decision' => 'allow', 'reason' => NULL];
      }
      return ['decision' => 'deny', 'reason' => 'illegal_transition'];
    }
    return ['decision' => 'allow', 'reason' => NULL];
  }

  /**
   * Position in the forward service order, or NULL.
   */
  private function index(string $stage): ?int {
    $i = array_search($stage, self::STAGES, TRUE);
    return $i === FALSE ? NULL : $i;
  }

}
