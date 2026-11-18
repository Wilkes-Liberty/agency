<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

use Drupal\Core\Session\AccountInterface;

/**
 * Projects ordered protected file items and validates inverse replay inputs.
 */
final class PersonnelFileProjector {

  public const SOURCE_PROPERTIES = ['target_id', 'display', 'description'];

  public function __construct(
    private readonly PersonnelFileSourceInterface $source,
  ) {}

  /**
   * Verifies source properties and resolves each original file identity.
   */
  public function project(EvidenceFileBinding $binding, AccountInterface $actor): EvidenceFileProjection {
    $source_items = $this->source->readItems($binding, $actor);
    if (!array_is_list($source_items)) {
      throw new PersonnelFileRefusal('unmapped_property');
    }

    $items = [];
    $files = [];
    foreach ($source_items as $item) {
      if (!is_array($item) || array_diff(array_keys($item), self::SOURCE_PROPERTIES) !== []) {
        throw new PersonnelFileRefusal('unmapped_property');
      }
      $fid = self::fid($item['target_id'] ?? NULL);
      $display = $item['display'] ?? NULL;
      $description = $item['description'] ?? NULL;
      if (!in_array($display, [NULL, TRUE, FALSE, 0, 1, '0', '1'], TRUE)
        || ($description !== NULL && !is_string($description))) {
        throw new PersonnelFileRefusal('unmapped_property');
      }
      $file = $files[$fid] ??= $this->source->qualifyFile($fid);
      if ($file->fid !== $fid) {
        throw new PersonnelFileRefusal('unqualified_file');
      }
      $items[] = new EvidenceFileItem($file, $display === NULL ? NULL : (bool) $display, $description);
    }
    return new EvidenceFileProjection($binding, $items);
  }

  /**
   * Rechecks protection and provenance before returning original field values.
   *
   * A caller that writes values must separately hold its writer fence over the
   * whole operation, including this check. Keep this projection request-local.
   *
   * @return list<array{target_id: int, display: ?bool, description: ?string}>
   *   The original Drupal value at each source delta.
   */
  public function preflightInverse(EvidenceFileProjection $projection, AccountInterface $actor): array {
    $fresh = $this->project($projection->binding, $actor);
    if (count($fresh->items) !== count($projection->items)) {
      throw new PersonnelFileRefusal('file_projection_drift');
    }

    $values = [];
    foreach ($fresh->items as $delta => $item) {
      $old = $projection->items[$delta];
      $value = $item->sourceValues();
      if ($old->sourceValues() !== $value
        || $old->file->uuid !== $item->file->uuid
        || $old->file->grantClass !== $item->file->grantClass
        || !hash_equals($old->file->grantDigest, $item->file->grantDigest)) {
        throw new PersonnelFileRefusal('file_projection_drift');
      }
      $values[] = $value;
    }
    return $values;
  }

  /**
   * Normalizes Drupal's persisted integer entity reference.
   */
  private static function fid(mixed $value): int {
    if (is_string($value) && (string) (int) $value === $value) {
      $value = (int) $value;
    }
    if (!is_int($value) || $value < 1) {
      throw new PersonnelFileRefusal('unqualified_file');
    }
    return $value;
  }

}
