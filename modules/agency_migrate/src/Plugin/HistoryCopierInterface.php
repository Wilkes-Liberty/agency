<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Explicit capability to verify complete revision and translation history.
 */
interface HistoryCopierInterface extends CopierInterface {

  /**
   * Snapshots all revisions, including the actual current default selection.
   *
   * Implementations must load persisted revisions rather than a current-only
   * projection. The runner compares both sides and the committed manifest.
   * Version 1 requires integer id and default_revision_id, string uuid, and a
   * numerically ordered revisions map keyed by integer revision id. Each entry
   * contains a SHA-256 digest of canonical fields and a boolean default flag.
   * Exactly the current default has default=TRUE; historical default metadata
   * remains in the canonical fields. Nonrevisionable records use their id.
   *
   * @return array<string, mixed>
   *   Versioned manifest with identity, default selection and revision digests.
   */
  public function historyManifest(ContentEntityInterface $entity): array;

}
