<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Exception;

/**
 * Stable refusal codes for workforce entity storage, never diagnostic text.
 */
final class WorkforceRefusal extends \RuntimeException {

  /**
   * An allowlisted code, never adapter or database exception text.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = in_array($reason, [
      'invalid',
      'collision',
      'forbidden',
    ], TRUE) ? $reason : 'failed';
    parent::__construct($this->reason);
  }

}
