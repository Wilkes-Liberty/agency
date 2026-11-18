<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * An internal plan for additional host resources in one Agency generation.
 *
 * A provider may subclass this plan to include immutable private proof or
 * state. The coordinator creates its own plan even after a preview, and
 * exposes only the pending count to operators.
 */
abstract readonly class RollbackDispositionPlan {

  public function __construct(
    public string $domain,
    public int $generation,
    public int $pendingCount,
  ) {
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $domain) || $generation < 1 || $pendingCount < 0) {
      throw new \InvalidArgumentException('Invalid rollback disposition plan.');
    }
  }

}
