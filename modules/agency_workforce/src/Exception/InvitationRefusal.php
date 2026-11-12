<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Exception;

/**
 * Stable refusal codes contain no contact, bearer or provider failure text.
 */
final class InvitationRefusal extends \RuntimeException {

  /**
   * An allowlisted code, never arbitrary adapter exception text.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = in_array($reason, [
      'invalid_invitation', 'forbidden', 'configuration', 'unavailable',
      'collision', 'failed', 'transaction_boundary', 'storage_conflict',
      'language', 'authority',
    ], TRUE) ? $reason : 'failed';
    parent::__construct($this->reason);
  }

}
