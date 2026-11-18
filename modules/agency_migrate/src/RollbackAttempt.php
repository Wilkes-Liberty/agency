<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * State shared only by the rollback coordinator and its fenced transition.
 *
 * @internal
 */
final class RollbackAttempt {

  /**
   * Legacy rows that may need a cache reset after a failed root transaction.
   */
  public array $effects = [];

  /**
   * Domain copiers keyed by target entity type.
   */
  public array $targets = [];

  /**
   * The sealed handover reached the root transaction commit boundary.
   */
  public bool $readyToCommit = FALSE;

}
