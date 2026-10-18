<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Opaque host account handle. Agency does not mint a uid.
 */
final class HostAccountBinding {

  /**
   * Host account kind. Discovery and person kinds are refused.
   */
  public const ACCOUNT = 'account';

  /**
   * Typed host binding.
   */
  public readonly string $kind;

  /**
   * Host-owned identifier, never a Discovery relationship id.
   */
  public readonly string $value;

  public function __construct(string $kind, string $value) {
    $this->kind = WorkforceInput::text($kind, 32);
    if ($this->kind !== self::ACCOUNT) {
      throw new Refusal('invalid');
    }
    $this->value = WorkforceInput::text($value, 255);
  }

}
