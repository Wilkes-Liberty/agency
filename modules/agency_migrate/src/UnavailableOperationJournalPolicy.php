<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

/**
 * Supplies no journal assurance until a host explicitly qualifies protection.
 */
final class UnavailableOperationJournalPolicy implements OperationJournalPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function declaration(string $domain, string $entityType, string $bundle): ?array {
    return NULL;
  }

}
