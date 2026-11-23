<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Resolves an existing contact without writing or dumping a payload.
 *
 * Default implementation stays unavailable. Agency registers no contact
 * type. Missing and foreign matches share not_found. A match never
 * creates an opportunity.
 */
interface ContactMatchInterface {

  /**
   * Looks up one contact route for the hatch tenant.
   *
   * Kind is a contact route, not a person or organization
   * discriminator. The answer is never a contact, opportunity or
   * activity payload.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array $contact
   *   Opaque contact of kind/value.
   *
   * @return array{outcome: string}
   *   Found outcome without contact fields.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, or missing and foreign matches.
   */
  public function lookup(
    CommercialPrincipal $principal,
    array $contact,
  ): array;

}
