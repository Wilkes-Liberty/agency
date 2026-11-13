<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Default contact-policy port. Throws until a host adapter is supplied.
 *
 * Scaffolding only. Opportunity and activity types stay unregistered.
 *
 * @todo S3 G1 D4 Activity revisionability is not encoded here.
 * @todo S3 G1 D5 Opportunity amount and currency are not encoded here.
 */
final class UnavailableContactPolicyReader implements ContactPolicyReaderInterface {

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    throw new CommercialRefusal('unavailable');
  }

}
