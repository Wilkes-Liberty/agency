<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Identity;

/**
 * A fresh host-authenticated identity, never hydrated from request parameters.
 */
final class Principal {

  public function __construct(
    public readonly string $issuer,
    public readonly string $subject,
    public readonly bool $active = TRUE,
  ) {
    if ($issuer === '' || $subject === '' || strlen($issuer) > 255 || strlen($subject) > 255) {
      throw new \InvalidArgumentException('Invalid principal.');
    }
  }

  /**
   * Returns a length-unambiguous identity key, without normalizing either part.
   */
  public function key(): string {
    return hash('sha256', json_encode([$this->issuer, $this->subject], JSON_THROW_ON_ERROR));
  }

}
