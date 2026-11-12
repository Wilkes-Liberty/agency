<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Connection;

/**
 * Fresh owning-storage authority and exclusion through the root transaction.
 *
 * The compatibility adapter permits verified legacy/shadow storage only.
 * No parallel authority register or positive production adapter is supplied.
 */
interface InvitationWriterBoundaryInterface {

  /**
   * Locks/checks fresh authority and excludes all covered issuers/consumers.
   *
   * The supplied connection already owns a root transaction. Exclusion must
   * survive until root commit/rollback and must not use a short expiring lease.
   */
  public function acquire(Connection $connection, string $trustedScope): void;

}
