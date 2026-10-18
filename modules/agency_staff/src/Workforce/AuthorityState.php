<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Per-domain authority the host adapter reads for workforce records.
 *
 * Machine names match agency_migrate's register: legacy, shadow, agency
 * and removed. See docs/MIGRATION.md. English labels such as
 * "legacy-authoritative" stay in prose and must not appear as ids.
 */
final class AuthorityState {

  public const LEGACY = 'legacy';
  public const SHADOW = 'shadow';
  public const AGENCY = 'agency';
  public const REMOVED = 'removed';

  public const ALLOWED = [
    self::LEGACY,
    self::SHADOW,
    self::AGENCY,
    self::REMOVED,
  ];

  public function __construct(
    public readonly string $id,
  ) {
    if (!in_array($id, self::ALLOWED, TRUE)) {
      throw new Refusal('invalid');
    }
  }

  /**
   * Agency records authorize reads only after cutover.
   */
  public function agencyReads(): bool {
    return in_array($this->id, [self::AGENCY, self::REMOVED], TRUE);
  }

  /**
   * Agency may persist copies in shadow and later states.
   */
  public function agencyWrites(): bool {
    return $this->id !== self::LEGACY;
  }

  /**
   * Legacy remains writable until Agency is authoritative.
   */
  public function legacyWrites(): bool {
    return in_array($this->id, [self::LEGACY, self::SHADOW], TRUE);
  }

  /**
   * Legacy remains readable until the drain state.
   */
  public function legacyReads(): bool {
    return $this->id !== self::REMOVED;
  }

}
