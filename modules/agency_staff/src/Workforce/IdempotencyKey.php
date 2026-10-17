<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * One user intent key; same shape as Discovery command keys.
 */
final class IdempotencyKey {

  public function __construct(
    public readonly string $value,
  ) {
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $value)) {
      throw new Refusal('invalid');
    }
  }

}
