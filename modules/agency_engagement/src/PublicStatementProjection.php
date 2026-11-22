<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Public statement snapshot. Private notes and rates stay unread.
 *
 * Host PDF, bearer transport and GraphQL stay on the adapter.
 */
final class PublicStatementProjection {

  /**
   * Public line keys a statement feed may emit.
   */
  public const KEYS = [
    'public_summary',
    'seconds',
    'date',
  ];

  /**
   * Private time keys that must never appear on a public statement.
   */
  public const PRIVATE_KEYS = [
    'note',
    'hourly_rate_minor',
    'rate_reference',
    'filename',
  ];

  /**
   * Maps one caller-supplied line to public scalars.
   *
   * @param array<string, mixed> $line
   *   Snapshot line from the host adapter.
   *
   * @return array<string, mixed>
   *   Allowlisted public keys only.
   */
  public static function line(array $line): array {
    $seconds = $line['seconds'] ?? NULL;
    return [
      'public_summary' => self::text($line['public_summary'] ?? NULL),
      'seconds' => is_int($seconds) && $seconds > 0 ? $seconds : NULL,
      'date' => self::text($line['date'] ?? NULL),
    ];
  }

  /**
   * Non-empty string, or NULL.
   *
   * @param mixed $value
   *   Caller-supplied text.
   */
  private static function text(mixed $value): ?string {
    return is_string($value) && trim($value) !== '' ? trim($value) : NULL;
  }

}
