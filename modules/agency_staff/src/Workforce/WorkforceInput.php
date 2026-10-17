<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

use Drupal\agency_staff\Exception\Refusal;

/**
 * Shared structural checks for Slice 2 command inputs.
 *
 * @internal
 */
final class WorkforceInput {

  /**
   * Bounds plain text without treating it as HTML or instruction.
   */
  public static function text(mixed $value, int $limit, bool $empty = FALSE): string {
    if (!is_string($value) || mb_strlen($value) > $limit || str_contains($value, "\0") || (!$empty && trim($value) === '')) {
      throw new Refusal('invalid');
    }
    return trim($value);
  }

  /**
   * Validates a typed contact route without choosing an identity from it.
   */
  public static function contact(array $contact): array {
    $kind = self::text($contact['kind'] ?? '', 32);
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $kind)) {
      throw new Refusal('invalid');
    }
    return ['kind' => $kind, 'value' => self::text($contact['value'] ?? '', 255)];
  }

  /**
   * Validates an opaque UUID used as a public identifier.
   */
  public static function uuid(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
      throw new Refusal('invalid');
    }
    return strtolower($value);
  }

  /**
   * Validates a 64-hex invitation bearer, matching Discovery token length.
   */
  public static function token(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
      throw new Refusal('invalid');
    }
    return $value;
  }

  /**
   * Requires POST so GET inspect cannot be confused with acceptance.
   */
  public static function postMethod(mixed $value): string {
    if (!is_string($value) || strtoupper($value) !== 'POST') {
      throw new Refusal('method_not_allowed');
    }
    return 'POST';
  }

  /**
   * Validates a non-negative relationship version.
   */
  public static function version(mixed $value): int {
    if (!is_int($value) || $value < 0) {
      throw new Refusal('invalid');
    }
    return $value;
  }

}
