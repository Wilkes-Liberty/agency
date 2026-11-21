<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Tenant-scoped operating-seat provisioner. Never creates a user.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Unknown seats and unbounded owner scope fail closed. Machine
 * credentials never inherit human grants.
 */
interface SeatProvisionerInterface {

  /**
   * Provisions an isolated operating-seat identity.
   *
   * Default implementation never writes a user, consumer or role.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $seat
   *   Operating seat name.
   * @param list<int> $owners
   *   Explicit owner identifiers. Empty is unbounded and fails closed.
   *
   * @return array{outcome: string}
   *   Found outcome without account or secret fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, unknown seat, or no visible identity.
   */
  public function provision(
    CommercialPrincipal $principal,
    string $seat,
    array $owners,
  ): array;

}
