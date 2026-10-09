<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Exception;

/**
 * Carries an allowlisted public reason without sensitive diagnostic details.
 */
final class Refusal extends \RuntimeException {

  /**
   * Stable refusal reason, without database diagnostics.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = $reason;
    parent::__construct($reason);
  }

}
