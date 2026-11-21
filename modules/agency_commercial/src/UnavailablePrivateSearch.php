<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default private-search port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Query text and client account numbers are
 * never dumped. Live indexes stay host.
 */
final class UnavailablePrivateSearch implements PrivateSearchInterface {

  /**
   * {@inheritdoc}
   */
  public function lookup(CommercialPrincipal $principal, string $query): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

  /**
   * {@inheritdoc}
   */
  public function filter(
    CommercialPrincipal $principal,
    array $criteria,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
