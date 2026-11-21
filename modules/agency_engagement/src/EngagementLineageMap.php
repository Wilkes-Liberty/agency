<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Proposed identity-preserving lineage. Persist stays refused.
 *
 * DEV-992 Option A copy waits on registered agency engagement types.
 * This map names the same id, UUID and revision on both sides and
 * writes nothing.
 */
final class EngagementLineageMap {

  /**
   * Identity keys a later copy must preserve exactly.
   */
  public const IDENTITY_KEYS = [
    'id',
    'uuid',
    'revision_id',
  ];

  /**
   * Names the lineage row a later pin would write after a successful copy.
   *
   * @param array<string, mixed> $identity
   *   Proposed id, UUID and revision id only.
   *
   * @return array<string, mixed>
   *   Same integers, UUID and revision on both sides.
   */
  public function propose(array $identity): array {
    if (array_diff(array_keys($identity), self::IDENTITY_KEYS) !== []
      || array_diff(self::IDENTITY_KEYS, array_keys($identity)) !== []) {
      throw new \InvalidArgumentException('unmapped_property');
    }
    foreach (self::IDENTITY_KEYS as $key) {
      if ($identity[$key] === '' || $identity[$key] === NULL
        || $identity[$key] === 0 || $identity[$key] === '0') {
        throw new \InvalidArgumentException('unmapped_property');
      }
    }
    return [
      'source_id' => $identity['id'],
      'source_uuid' => $identity['uuid'],
      'source_revision_id' => $identity['revision_id'],
      'target_id' => $identity['id'],
      'target_uuid' => $identity['uuid'],
      'target_revision_id' => $identity['revision_id'],
    ];
  }

  /**
   * Persist is refused while engagement types are unregistered.
   *
   * @param array<string, mixed> $identity
   *   Proposed id, UUID and revision id.
   *
   * @return array{outcome: string, reason: string, lineage: array{}}
   *   Operator-visible stop. Empty lineage. Not not_found.
   */
  public function persist(array $identity): array {
    try {
      $this->propose($identity);
    }
    catch (\InvalidArgumentException) {
      return $this->stopped();
    }
    return $this->stopped();
  }

  /**
   * Shared refuse body.
   *
   * @return array{outcome: string, reason: string, lineage: array{}}
   *   Unmapped stop.
   */
  private function stopped(): array {
    return [
      'outcome' => 'refused',
      'reason' => 'unmapped_property',
      'lineage' => [],
    ];
  }

}
