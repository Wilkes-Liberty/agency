<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

/**
 * Explicit capability to enumerate the complete target population.
 */
interface InventoryCopierInterface extends CopierInterface {

  /**
   * Loads targets in strictly increasing integer id order after the cursor.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   At most the requested number of current default target records.
   */
  public function loadTargetBatch(int $after_id, int $limit): array;

}
