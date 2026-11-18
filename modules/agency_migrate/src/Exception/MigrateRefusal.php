<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Exception;

/**
 * Carries an allowlisted public reason without diagnostic payload.
 */
final class MigrateRefusal extends \RuntimeException {

  /**
   * Stable refusal reason, without database diagnostics.
   */
  public readonly string $reason;

  public function __construct(string $reason, ?self $previous = NULL) {
    $this->reason = $reason;
    parent::__construct($reason, 0, $previous);
  }

}
