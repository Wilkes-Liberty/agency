<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default contact-policy port. Throws until a host adapter is supplied.
 *
 * Scaffolding only. Opportunity and activity types stay unregistered.
 * Slice 3 G1 is accepted. This package still ships no entity types.
 */
final class UnavailableContactPolicyReader implements ContactPolicyReaderInterface {

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    throw new CommercialRefusal('unavailable');
  }

}
