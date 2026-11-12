<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\agency_workforce\InvitationWriterBoundaryInterface;
use Drupal\Core\Database\Connection;

/**
 * Synthetic owning-storage authority; never a production coverage claim.
 */
final class SqlWriterBoundary implements InvitationWriterBoundaryInterface {

  public function acquire(Connection $connection, string $trustedScope): void {
    if (!$connection->inTransaction()) {
      throw new \LogicException('A root transaction is required.');
    }
    $query = $connection->select('agency_workforce_test_authority', 'a')->fields('a')
      ->condition('scope', $trustedScope);
    $query->forUpdate();
    $row = $query->execute()->fetchAssoc();
    if (!$row || !in_array($row['state'], ['legacy', 'shadow'], TRUE)) {
      throw new InvitationRefusal('authority');
    }
  }

}
