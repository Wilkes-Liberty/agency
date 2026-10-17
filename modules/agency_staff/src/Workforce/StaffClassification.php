<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Workforce kind supplied by the issuer, never chosen by the invitee.
 */
final class StaffClassification {

  public const EMPLOYEE = 'employee';
  public const CONTRACTOR = 'contractor';

  public function __construct(
    public readonly string $id,
  ) {
    if ($id !== self::EMPLOYEE && $id !== self::CONTRACTOR) {
      throw new Refusal('invalid');
    }
  }

}
