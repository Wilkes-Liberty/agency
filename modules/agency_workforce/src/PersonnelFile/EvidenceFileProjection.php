<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

/**
 * Protected in-memory projection of one revision-bound evidence file field.
 */
final readonly class EvidenceFileProjection {

  /**
   * Qualified file items in source delta order.
   *
   * @var list<\Drupal\agency_workforce\PersonnelFile\EvidenceFileItem>
   */
  public array $items;

  /**
   * Constructs a protected projection.
   *
   * @param \Drupal\agency_workforce\PersonnelFile\EvidenceFileBinding $binding
   *   The source edge and revision.
   * @param array<array-key, mixed> $items
   *   Qualified file items in source delta order.
   */
  public function __construct(
    public EvidenceFileBinding $binding,
    array $items,
  ) {
    if (!array_is_list($items)) {
      throw new PersonnelFileRefusal('file_projection_drift');
    }
    $qualified = [];
    foreach ($items as $item) {
      if (!$item instanceof EvidenceFileItem) {
        throw new PersonnelFileRefusal('file_projection_drift');
      }
      $qualified[] = $item;
    }
    $this->items = $qualified;
  }

}
