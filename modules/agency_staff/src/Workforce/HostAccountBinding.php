<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Opaque host account handle. Agency does not mint a uid.
 */
final class HostAccountBinding {

  /**
   * Typed host binding, for example a host account kind.
   */
  public readonly string $kind;

  /**
   * Host-owned identifier, never a Discovery relationship id.
   */
  public readonly string $value;

  public function __construct(string $kind, string $value) {
    $this->kind = WorkforceInput::text($kind, 32);
    $this->value = WorkforceInput::text($value, 255);
  }

}
