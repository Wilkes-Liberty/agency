<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

use Drupal\Core\Session\AccountInterface;

/**
 * Host authorization, revision lookup and protected file qualification.
 */
interface PersonnelFileSourceInterface {

  /**
   * Reads a fresh field only after authorizing the parent edge and child.
   *
   * The host verifies the exact revision and translation on both sides of
   * the edge and refuses unmapped stored columns before returning values.
   *
   * @return array<array-key, mixed>
   *   Raw stored file field items in their original delta order.
   *
   * @throws \Drupal\agency_workforce\PersonnelFile\PersonnelFileRefusal
   *   When access, mapping or source provenance cannot be verified.
   */
  public function readItems(EvidenceFileBinding $binding, AccountInterface $actor): array;

  /**
   * Verifies a fresh private file and its existing gate and grant inventory.
   *
   * This method only inspects the host's authoritative file and grants.
   *
   * @throws \Drupal\agency_workforce\PersonnelFile\PersonnelFileRefusal
   *   When file identity or protection cannot be verified.
   */
  public function qualifyFile(int $fid): QualifiedFile;

}
