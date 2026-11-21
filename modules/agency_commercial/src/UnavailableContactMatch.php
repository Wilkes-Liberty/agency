<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default contact-match port. Throws until a host adapter is supplied.
 *
 * Deny-closed scaffolding. Claims never create an opportunity.
 */
final class UnavailableContactMatch implements ContactMatchInterface {

  /**
   * {@inheritdoc}
   */
  public function lookup(
    CommercialPrincipal $principal,
    array $contact,
  ): array {
    $principal->requireTenant();
    throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
  }

}
