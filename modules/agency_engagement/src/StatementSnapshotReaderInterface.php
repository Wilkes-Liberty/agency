<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Statement snapshot read for an entitled reviewer or coordinator.
 *
 * Scaffolding refuses. This is a snapshot read, not an invoice.
 * Slice 4 G1 is open.
 *
 * @todo S4 G1 D6 Statement versus invoice-draft placement remains open.
 */
interface StatementSnapshotReaderInterface {

  /**
   * Reads one statement snapshot.
   *
   * @param string $snapshot_id
   *   Synthetic snapshot identifier. Not an entity load.
   */
  public function readStatementSnapshot(string $snapshot_id): void;

}
