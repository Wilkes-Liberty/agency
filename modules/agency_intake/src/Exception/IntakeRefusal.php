<?php

declare(strict_types=1);

namespace Drupal\agency_intake\Exception;

/**
 * Carries an allowlisted public reason without intake payload.
 */
final class IntakeRefusal extends \RuntimeException {

  /**
   * Stable refusal reason, without diagnostic payload.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = $reason;
    parent::__construct($reason);
  }

}
