<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_disposition_test;

use Drupal\agency_migrate\RollbackDispositionPlan;

/**
 * Keeps the fixture's resource identities private to its implementation.
 */
final readonly class LocalDispositionPlan extends RollbackDispositionPlan {

  /**
   * Creates an immutable synthetic resource plan.
   *
   * @param string $domain
   *   The covered domain.
   * @param int $generation
   *   The Agency generation.
   * @param list<int> $ids
   *   Synthetic resource identities observed in this plan.
   */
  public function __construct(string $domain, int $generation, private array $ids) {
    parent::__construct($domain, $generation, count($ids));
  }

  /**
   * Supplies the exact private targets to the fixture participant.
   *
   * @return list<int>
   *   Identifiers resolved during preflight.
   */
  public function resourceIds(): array {
    return $this->ids;
  }

}
