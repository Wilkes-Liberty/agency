<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Property marking that must travel with personnel and profile evidence.
 */
final class FieldMarking {

  public const PUBLIC = 'public';
  public const INTERNAL = 'internal';
  public const CONFIDENTIAL = 'confidential';
  public const PERSONAL = 'personal';
  public const FINANCIAL = 'financial';

  public const ALLOWED = [
    self::PUBLIC,
    self::INTERNAL,
    self::CONFIDENTIAL,
    self::PERSONAL,
    self::FINANCIAL,
  ];

  public function __construct(
    public readonly string $id,
  ) {
    if (!in_array($id, self::ALLOWED, TRUE)) {
      throw new Refusal('invalid');
    }
  }

}
