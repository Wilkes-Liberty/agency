<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free client deliverable and invoice listing.
 *
 * Host GraphQL, File Gate and flood stay on the adapter. This object
 * filters caller-supplied records and never emits pay or download
 * fields. Record types stay unregistered.
 */
final class ClientDocumentListing {

  /**
   * Authorized listing. Items may still be empty.
   */
  public const STATUS_OK = 'OK';

  /**
   * Subject is not bound to a portal account.
   */
  public const STATUS_NOT_LINKED = 'NOT_LINKED';

  /**
   * Bound account is not a client (or is blocked).
   */
  public const STATUS_NOT_CLIENT = 'NOT_CLIENT';

  /**
   * Invoice-kind documents the invoice listing may expose.
   */
  public const INVOICE_KIND = 'invoice';

  /**
   * Deliverable item keys. Metadata only; no URL.
   */
  public const DELIVERABLE_KEYS = [
    'id',
    'kind',
    'filename',
    'sizeBytes',
    'createdAt',
    'expiresAt',
    'fileUuid',
  ];

  /**
   * Invoice item keys. Metadata only; no pay rails.
   */
  public const INVOICE_KEYS = [
    'id',
    'filename',
    'sizeBytes',
    'createdAt',
    'fileUuid',
  ];

  /**
   * Pay, ledger and download keys that must never appear on an item.
   */
  public const PAY_KEYS = [
    'payUrl',
    'paymentUrl',
    'pay',
    'amount',
    'balance',
    'paid',
    'openBalance',
    'charge',
    'paymentIntent',
    'url',
    'downloadUrl',
  ];

  /**
   * Empty listing for an unresolved or unauthorized-empty identity.
   *
   * @return array{status: string, account: null, items: array{}}
   *   Status with no account and no items.
   */
  public static function empty(string $status): array {
    return [
      'status' => $status,
      'account' => NULL,
      'items' => [],
    ];
  }

  /**
   * Deliverables listing. Every published, unexpired, reachable record.
   *
   * @param array<string, mixed> $subject
   *   Identity facts from the host adapter.
   * @param list<array<string, mixed>> $records
   *   Caller-supplied document records.
   *
   * @return array
   *   Allowlisted items when OK; otherwise empty items.
   */
  public static function deliverables(array $subject, array $records): array {
    return self::list($subject, $records, FALSE);
  }

  /**
   * Invoice listing. Invoice-kind records only; missing kind is empty.
   *
   * @param array<string, mixed> $subject
   *   Identity facts from the host adapter.
   * @param list<array<string, mixed>> $records
   *   Caller-supplied document records.
   *
   * @return array
   *   Allowlisted invoice items when OK; otherwise empty items.
   */
  public static function invoices(array $subject, array $records): array {
    return self::list($subject, $records, TRUE);
  }

  /**
   * Filters records and projects the ratified item shape.
   *
   * @param array<string, mixed> $subject
   *   Identity facts from the host adapter.
   * @param list<array<string, mixed>> $records
   *   Caller-supplied document records.
   * @param bool $invoices_only
   *   TRUE to keep invoice-kind records only.
   *
   * @return array
   *   Listing body.
   */
  private static function list(
    array $subject,
    array $records,
    bool $invoices_only,
  ): array {
    if (($subject['authorized'] ?? FALSE) !== TRUE) {
      throw new \InvalidArgumentException('not_authorized');
    }
    if (($subject['flood'] ?? FALSE) === TRUE) {
      throw new \InvalidArgumentException('unavailable');
    }
    $status = $subject['status'] ?? '';
    if ($status !== self::STATUS_OK) {
      return self::empty(is_string($status) && $status !== ''
        ? $status
        : self::STATUS_NOT_LINKED);
    }
    $account = $subject['account'] ?? NULL;
    if (!is_string($account) || $account === '') {
      return self::empty(self::STATUS_NOT_LINKED);
    }
    $today = $subject['today'] ?? '';
    if (!is_string($today) || $today === '') {
      throw new \InvalidArgumentException('invalid');
    }
    $keys = $invoices_only ? self::INVOICE_KEYS : self::DELIVERABLE_KEYS;
    $items = [];
    foreach ($records as $record) {
      $item = self::item($record, $today, $invoices_only, $keys);
      if ($item !== NULL) {
        $items[] = $item;
      }
    }
    return [
      'status' => self::STATUS_OK,
      'account' => $account,
      'items' => $items,
    ];
  }

  /**
   * Projects one record or skips it.
   *
   * @param array<string, mixed> $record
   *   One caller-supplied document.
   * @param string $today
   *   Site-timezone calendar day (Y-m-d).
   * @param bool $invoices_only
   *   TRUE to require invoice kind.
   * @param list<string> $keys
   *   Ratified item keys.
   *
   * @return array<string, mixed>|null
   *   Allowlisted item, or NULL when the record is omitted.
   */
  private static function item(
    array $record,
    string $today,
    bool $invoices_only,
    array $keys,
  ): ?array {
    if (($record['published'] ?? FALSE) !== TRUE) {
      return NULL;
    }
    if (($record['reachable'] ?? FALSE) !== TRUE) {
      return NULL;
    }
    $kind = $record['kind'] ?? NULL;
    if ($invoices_only && $kind !== self::INVOICE_KIND) {
      return NULL;
    }
    $expires = $record['expires'] ?? $record['expiresAt'] ?? NULL;
    if (is_string($expires) && $expires !== ''
      && substr($expires, 0, 10) < $today) {
      return NULL;
    }
    $id = $record['id'] ?? NULL;
    $filename = $record['filename'] ?? NULL;
    $file_uuid = $record['fileUuid'] ?? $record['file_uuid'] ?? NULL;
    if (!is_string($id) || $id === ''
      || !is_string($filename) || $filename === ''
      || !is_string($file_uuid) || $file_uuid === '') {
      return NULL;
    }
    $item = [
      'id' => $id,
      'filename' => $filename,
      'sizeBytes' => self::intOrNull(
        $record['sizeBytes'] ?? $record['size'] ?? NULL,
      ),
      'createdAt' => self::textOrNull($record['createdAt'] ?? NULL),
      'fileUuid' => $file_uuid,
    ];
    if (!$invoices_only) {
      $item['kind'] = is_string($kind) && $kind !== '' ? $kind : NULL;
      $item['expiresAt'] = is_string($expires) && $expires !== ''
        ? $expires
        : NULL;
    }
    $ordered = [];
    foreach ($keys as $key) {
      $ordered[$key] = $item[$key] ?? NULL;
    }
    foreach (self::PAY_KEYS as $forbidden) {
      unset($ordered[$forbidden]);
    }
    return $ordered;
  }

  /**
   * Integer size, or NULL when absent.
   *
   * @param mixed $value
   *   Caller-supplied size.
   */
  private static function intOrNull(mixed $value): ?int {
    return is_int($value) ? $value : NULL;
  }

  /**
   * Non-empty string, or NULL.
   *
   * @param mixed $value
   *   Caller-supplied text.
   */
  private static function textOrNull(mixed $value): ?string {
    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
