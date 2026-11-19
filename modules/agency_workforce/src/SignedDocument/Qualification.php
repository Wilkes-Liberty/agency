<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\SignedDocument;

use Drupal\Component\Uuid\Uuid;

/**
 * Portable scalar and identity checks for private signed-document evidence.
 *
 * @internal
 */
final class Qualification {

  /**
   * Refuses without including provider evidence or identifiers in an error.
   *
   * @phpstan-assert true $condition
   */
  public static function require(bool $condition, string $reason): void {
    if (!$condition) {
      throw new \UnexpectedValueException('signed_document_' . $reason);
    }
  }

  /**
   * Requires all reviewed source fields and refuses unknown extensions.
   */
  public static function keys(array $row, array $keys, string $reason): void {
    self::require(count($row) === count($keys) && !array_diff($keys, array_keys($row)), $reason);
  }

  /**
   * Checks exact lowercase GUIDs retained by the original envelope mapping.
   */
  public static function envelope(mixed $value): bool {
    return is_string($value)
      && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
  }

  /**
   * Checks a canonical digest.
   *
   * @phpstan-assert-if-true string $value
   */
  public static function digest(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
  }

  /**
   * Checks a positive source field identity.
   */
  public static function id(mixed $value): bool {
    return is_int($value) && $value > 0;
  }

  /**
   * Checks source time and count values without coercing numeric strings.
   */
  public static function quantity(mixed $value): bool {
    return is_int($value) && $value >= 0;
  }

  /**
   * Checks a source UUID supplied by the trusted inventory.
   */
  public static function uuid(mixed $value): bool {
    return is_string($value) && Uuid::isValid($value);
  }

  /**
   * Validates a civil date or canonical UTC source datetime.
   */
  public static function date(mixed $value, string $format = 'Y-m-d'): bool {
    if (!is_string($value) || str_contains($value, "\0")) {
      return FALSE;
    }
    $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
    return $date !== FALSE && $date->format($format) === $value;
  }

  /**
   * Checks a retained retry key without normalizing the source value.
   */
  public static function retryKey(mixed $value): bool {
    return is_string($value) && $value !== '' && $value === trim($value) && mb_strlen($value) <= 64;
  }

}
