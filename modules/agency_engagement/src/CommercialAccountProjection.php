<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Allowlisted commercial scalars for a client-account feed.
 *
 * Storage-free. Host transport keeps GraphQL and Profile reads. This
 * projection never copies tax, address, contact, terms, instruments,
 * financial totals, opportunities or entity dumps.
 */
final class CommercialAccountProjection {

  /**
   * GraphQL keys this projection may emit.
   */
  public const KEYS = [
    'accountNumber',
    'billingLegalName',
    'paymentMethodLabel',
  ];

  /**
   * Payment-method machine values that may resolve to a label.
   */
  private const METHODS = [
    'invoice',
    'ach',
    'wire',
    'check',
    'card',
    'crypto',
  ];

  /**
   * Null commercial summary used when identity or the record is absent.
   *
   * @return array
   *   Exactly the ratified keys, all null.
   */
  public static function empty(): array {
    return [
      'accountNumber' => NULL,
      'billingLegalName' => NULL,
      'paymentMethodLabel' => NULL,
    ];
  }

  /**
   * Maps one caller-supplied record to the ratified commercial scalars.
   *
   * Missing fields and blank values are null. Unknown payment machine
   * values do not pass through. Extra keys on the record are unread.
   *
   * @param array<string, mixed>|null $record
   *   Scalar fields from the host adapter. NULL is an empty projection.
   *
   * @return array
   *   Allowlisted keys only.
   */
  public static function fromRecord(?array $record): array {
    if ($record === NULL) {
      return self::empty();
    }
    $method = $record['payment_method'] ?? $record['paymentMethod'] ?? NULL;
    $labels = $record['payment_labels'] ?? [];
    if (!is_array($labels)) {
      $labels = [];
    }
    return [
      'accountNumber' => self::text(
        $record['account_number'] ?? $record['accountNumber'] ?? NULL,
      ),
      'billingLegalName' => self::text(
        $record['billing_legal_name'] ?? $record['billingLegalName'] ?? NULL,
      ),
      'paymentMethodLabel' => self::paymentLabel($method, $labels),
    ];
  }

  /**
   * Reads one scalar, treating blank values as absent.
   */
  private static function text(mixed $value): ?string {
    return is_string($value) && trim($value) !== '' ? trim($value) : NULL;
  }

  /**
   * Returns the configured label for an allowlisted payment method.
   *
   * @param array<string, mixed> $labels
   *   Machine value to label map supplied by the host adapter.
   */
  private static function paymentLabel(mixed $method, array $labels): ?string {
    if (!is_string($method) || !in_array($method, self::METHODS, TRUE)) {
      return NULL;
    }
    $label = $labels[$method] ?? NULL;
    return is_string($label) && trim($label) !== '' ? trim($label) : NULL;
  }

}
