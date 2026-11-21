<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Typed, task-shaped inputs for the bounded commercial service.
 *
 * Allowed keys replace contrib tool InputDefinition maps. Unknown keys
 * still throw without widening fields or dumping caller payload.
 */
final class ToolOperations {

  /**
   * Lists only operations that the named credential seat can perform.
   *
   * @param string $seat
   *   Generic commercial seat name.
   *
   * @return list<string>
   *   Operation names the seat may invoke.
   */
  public static function forSeat(string $seat): array {
    return match ($seat) {
      'finance' => ['billing_completeness'],
      'marketing' => ['opportunity_list', 'opportunity_get'],
      'outbound' => [
        'opportunity_list',
        'opportunity_get',
        'opportunity_create',
        'opportunity_update',
        'opportunity_transition',
        'contact_create',
        'contact_update',
        'activity_create',
      ],
      'cos', 'engagement' => [
        ...self::forSeat('outbound'),
        'billing_completeness',
      ],
      default => [],
    };
  }

  /**
   * Distinguishes reads from modifying actions for tool discovery.
   *
   * @param string $operation
   *   Operation name.
   *
   * @return bool
   *   TRUE when the operation is a read.
   */
  public static function isRead(string $operation): bool {
    return in_array($operation, [
      'opportunity_list',
      'opportunity_get',
      'billing_completeness',
    ], TRUE);
  }

  /**
   * Adapts named inputs to the authoritative service without widening fields.
   *
   * Optional omissions are dropped. Empty strings are kept so a caller
   * can deliberately clear a supported text field. Retry keys stay
   * outside record fields.
   *
   * @param string $operation
   *   Operation name.
   * @param array<string, mixed> $values
   *   Named inputs supplied for the operation.
   *
   * @return array<string, mixed>
   *   Service payload. Nested under fields when the operation wraps them.
   *
   * @throws \InvalidArgumentException
   *   When the operation or an input name is unknown.
   */
  public static function payload(string $operation, array $values): array {
    $definitions = self::allowedKeys($operation);
    if (array_diff_key($values, $definitions)) {
      throw new \InvalidArgumentException('Unknown CRM action input.');
    }
    // Preserve empty strings so callers can deliberately clear a
    // supported text field. Tool callers resolve absent optionals to NULL.
    $values = array_filter(
      $values,
      static fn(mixed $value): bool => $value !== NULL,
    );
    $outer = match ($operation) {
      'opportunity_update', 'contact_update' => ['id', 'revision'],
      'contact_create' => [],
      'activity_create', 'opportunity_create' => ['request'],
      default => NULL,
    };
    if ($outer === NULL) {
      return $values;
    }
    $keys = array_fill_keys($outer, TRUE);
    return array_intersect_key($values, $keys) + [
      'fields' => array_diff_key($values, $keys),
    ];
  }

  /**
   * Returns the allowed input names for one operation.
   *
   * @param string $operation
   *   Operation name.
   *
   * @return array<string, true>
   *   Allowed input names.
   *
   * @throws \InvalidArgumentException
   *   When the operation is unknown.
   */
  private static function allowedKeys(string $operation): array {
    $keys = match ($operation) {
      'opportunity_create' => [
        'title',
        'owner',
        'source',
        'organization_name',
        'contact',
        'next_action',
        'next_action_at',
        'request',
      ],
      'opportunity_list' => [
        'queue',
        'after',
        'limit',
      ],
      'opportunity_get' => [
        'id',
      ],
      'billing_completeness' => [
        'client',
      ],
      'opportunity_update' => [
        'id',
        'revision',
        'next_action',
        'next_action_at',
        'source',
      ],
      'opportunity_transition' => [
        'id',
        'revision',
        'stage',
        'reason',
      ],
      'contact_create' => [
        'label',
        'organization_name',
        'email',
        'phone',
        'source',
        'contact_restriction',
        'owner',
      ],
      'contact_update' => [
        'id',
        'revision',
        'label',
        'organization_name',
        'email',
        'phone',
        'source',
        'contact_restriction',
      ],
      'activity_create' => [
        'opportunity',
        'type',
        'summary',
        'occurred_at',
        'external_reference',
        'request',
      ],
      default => throw new \InvalidArgumentException('Unknown CRM action.'),
    };
    return array_fill_keys($keys, TRUE);
  }

}
