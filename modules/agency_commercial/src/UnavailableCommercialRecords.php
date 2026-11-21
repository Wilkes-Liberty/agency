<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default records port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Existing opportunity rows are never dumped.
 */
final class UnavailableCommercialRecords implements
  CommercialRecordsInterface {

  /**
   * {@inheritdoc}
   */
  public function load(CommercialPrincipal $principal, string $id): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function update(
    CommercialPrincipal $principal,
    string $id,
    array $values,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
