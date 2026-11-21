<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Minimized portal membership audit. Leak probes stay off the payload.
 *
 * Host Audit Chain signing stays on the adapter. This object names
 * the operation and reason only.
 */
final class MembershipAudit {

  /**
   * Distinctive fields that must never appear in membership evidence.
   */
  public const FORBIDDEN = [
    'email',
    'sub',
    'token',
    'filename',
    'phone',
  ];

  /**
   * Bind refuse evidence.
   *
   * @param string $reason
   *   Ceremony reason. Not not_found.
   *
   * @return array{operation: string, metadata: array{reason: string}}
   *   Minimized bind_refused row.
   */
  public function bindRefused(string $reason): array {
    if ($reason === '' || $reason === EngagementDecision::REASON_NOT_FOUND) {
      throw new \InvalidArgumentException('invalid');
    }
    return [
      'operation' => 'portal.bind_refused',
      'metadata' => ['reason' => $reason],
    ];
  }

  /**
   * Role grant evidence.
   *
   * @param int $subject_uid
   *   Account id that received visitor roles.
   *
   * @return array{operation: string, metadata: array{subject_uid: int}}
   *   Minimized roles_reconciled row.
   */
  public function rolesReconciled(int $subject_uid): array {
    if ($subject_uid < 1) {
      throw new \InvalidArgumentException('invalid');
    }
    return [
      'operation' => 'portal.roles_reconciled',
      'metadata' => ['subject_uid' => $subject_uid],
    ];
  }

  /**
   * Encodes a row and confirms leak probes are absent.
   *
   * @param array<string, mixed> $row
   *   Audit evidence.
   * @param list<string> $probes
   *   Distinctive strings that must not appear.
   *
   * @return void
   *   No return; throws when the row is not minimized.
   */
  public function assertMinimized(array $row, array $probes): void {
    $metadata = $row['metadata'] ?? [];
    if (!is_array($metadata)) {
      throw new \InvalidArgumentException('invalid');
    }
    foreach (self::FORBIDDEN as $field) {
      if (array_key_exists($field, $row)
        || array_key_exists($field, $metadata)) {
        throw new \InvalidArgumentException('invalid');
      }
    }
    $encoded = json_encode($row, JSON_THROW_ON_ERROR);
    foreach ($probes as $probe) {
      if (str_contains($encoded, $probe)) {
        throw new \InvalidArgumentException('invalid');
      }
    }
  }

}
