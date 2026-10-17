<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Per-domain authority the host adapter reads for workforce records.
 */
final class AuthorityState {

  public const LEGACY_AUTHORITATIVE = 'legacy_authoritative';
  public const SHADOW = 'shadow';
  public const AGENCY_AUTHORITATIVE = 'agency_authoritative';
  public const LEGACY_REMOVED = 'legacy_removed';

  public const ALLOWED = [
    self::LEGACY_AUTHORITATIVE,
    self::SHADOW,
    self::AGENCY_AUTHORITATIVE,
    self::LEGACY_REMOVED,
  ];

  public function __construct(
    public readonly string $id,
  ) {
    if (!in_array($id, self::ALLOWED, TRUE)) {
      throw new Refusal('invalid');
    }
  }

  /**
   * Agency records must not authorize reads in these states.
   */
  public function agencyReads(): bool {
    return in_array($this->id, [self::AGENCY_AUTHORITATIVE, self::LEGACY_REMOVED], TRUE);
  }

  /**
   * Agency may persist parallel copies only in shadow or later states.
   */
  public function agencyWrites(): bool {
    return $this->id !== self::LEGACY_AUTHORITATIVE;
  }

}
