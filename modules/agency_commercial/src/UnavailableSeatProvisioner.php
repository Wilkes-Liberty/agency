<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default seat-provisioner port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Users, OAuth consumers and MCP roles are
 * never created.
 */
final class UnavailableSeatProvisioner implements SeatProvisionerInterface {

  /**
   * {@inheritdoc}
   */
  public function provision(
    CommercialPrincipal $principal,
    string $seat,
    array $owners,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
