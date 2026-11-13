<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\Exception;

/**
 * Carries an allowlisted public reason without contact payload.
 */
final class CommercialRefusal extends \RuntimeException {

  /**
   * Stable refusal reason, without diagnostic payload.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = $reason;
    parent::__construct($reason);
  }

}
