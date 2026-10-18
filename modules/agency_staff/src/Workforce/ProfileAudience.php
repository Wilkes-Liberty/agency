<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Allowlisted profile projection audience. Host transport stays on the host.
 */
final class ProfileAudience {

  public const OWN = 'own';
  public const ACCOUNT_SUMMARY = 'account_summary';

  public const ALLOWED = [
    self::OWN,
    self::ACCOUNT_SUMMARY,
  ];

  public function __construct(
    public readonly string $id,
  ) {
    if (!in_array($id, self::ALLOWED, TRUE)) {
      throw new Refusal('invalid');
    }
  }

}
