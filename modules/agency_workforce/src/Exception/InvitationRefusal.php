<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Exception;

/**
 * Stable refusal codes contain no contact, bearer or provider failure text.
 */
final class InvitationRefusal extends \RuntimeException {

  public function __construct(
    public readonly string $reason,
  ) {
    parent::__construct($reason);
  }

}
