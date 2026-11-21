<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Allow/deny contact lookup. Never returns contact data.
 *
 * CRM transfer §4. Slice 3 G1 is accepted. This package still ships no
 * entity types. Kind is a contact route, not a person or organization
 * discriminator. The signature does not encode opportunity amount,
 * activity revisionability or an authority-row name.
 */
interface ContactPolicyReaderInterface {

  /**
   * Whether the typed contact may be reached. Allow or deny only.
   *
   * Ambiguous matches fail closed. The answer is a boolean, never a
   * contact, tax identifier, opportunity or activity payload.
   *
   * @param array $contact
   *   Opaque contact of kind/value. Not a type discriminator.
   *
   * @return bool
   *   TRUE only when contact is allowed. Default implementation throws.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   */
  public function mayContact(array $contact): bool;

}
