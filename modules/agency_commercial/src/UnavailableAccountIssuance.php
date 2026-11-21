<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default account-issuance port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Account numbers and OAuth consumers are
 * never issued.
 */
final class UnavailableAccountIssuance implements AccountIssuanceInterface {

  /**
   * {@inheritdoc}
   */
  public function issue(
    CommercialPrincipal $principal,
    string $seat,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
