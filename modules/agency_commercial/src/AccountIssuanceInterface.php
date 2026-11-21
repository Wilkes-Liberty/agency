<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Tenant-scoped account-number issuance. Never issues a live identity.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Operating seats are not portal roles. OAuth consumers stay host.
 */
interface AccountIssuanceInterface {

  /**
   * Issues one account number for the hatch tenant.
   *
   * Default implementation never returns an account number.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $seat
   *   Operating seat name.
   *
   * @return array{outcome: string}
   *   Found outcome without account numbers or secrets.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, unknown seat, or no visible account.
   */
  public function issue(
    CommercialPrincipal $principal,
    string $seat,
  ): array;

}
