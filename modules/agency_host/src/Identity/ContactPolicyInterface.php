<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Decides whether Discovery may contact an intended person.
 *
 * Hosts that resolve contacts against a CRM implement this port. The default
 * implementation permits a well-formed email when no other policy is present.
 */
interface ContactPolicyInterface {

  /**
   * Whether the typed contact may receive a Discovery invitation.
   */
  public function mayContact(array $contact): bool;

}
