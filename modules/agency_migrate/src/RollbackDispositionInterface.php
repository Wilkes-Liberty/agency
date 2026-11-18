<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Connection;

/**
 * Qualifies and reverses a domain's additional host-owned SQL resources.
 *
 * The participant uses Agency's exact connection and existing fence. All
 * effects belong to that SQL transaction. Native transaction control and
 * schema changes belong outside the callbacks. A provider owns its immutable
 * host receipt.
 */
interface RollbackDispositionInterface {

  /**
   * Qualifies this domain and prospective generation inside the cutover fence.
   */
  public function qualifyCutover(Connection $connection, string $domain, int $prospectiveGeneration): void;

  /**
   * Reads the exact resource state without mutation or lock acquisition.
   */
  public function plan(Connection $connection, string $domain, int $generation): RollbackDispositionPlan;

  /**
   * Applies and verifies this plan inside the existing rollback transaction.
   *
   * Returns the lowercase SHA-256 digest of the immutable host receipt. The
   * digest remains privately bound to the generation in Agency control state.
   */
  public function applyAndVerify(Connection $connection, RollbackDispositionPlan $plan): string;

  /**
   * Rechecks an immutable committed host receipt without reading live grants.
   */
  public function verifyCommitted(Connection $connection, string $domain, int $generation, string $digest): void;

}
