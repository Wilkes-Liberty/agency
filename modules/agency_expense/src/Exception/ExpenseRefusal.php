<?php

declare(strict_types=1);

namespace Drupal\agency_expense\Exception;

/**
 * Carries an allowlisted public reason without expense payload.
 */
final class ExpenseRefusal extends \RuntimeException {

  /**
   * Stable refusal reason, without diagnostic payload.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = in_array($reason, [
      'forbidden',
      'invalid',
      'timeout',
      'not_found',
      'collision',
      'failed',
    ], TRUE) ? $reason : 'failed';
    parent::__construct($this->reason);
  }

}
