<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\Exception;

/**
 * Stable refusal codes contain no public copy or tenant strings.
 *
 * @todo S4 G1 D9 Public refusal strings remain open.
 */
final class EngagementRefusal extends \RuntimeException {

  /**
   * An allowlisted code, never adapter or host diagnostic text.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = $reason === 'unavailable' ? 'unavailable' : 'failed';
    parent::__construct($this->reason);
  }

}
