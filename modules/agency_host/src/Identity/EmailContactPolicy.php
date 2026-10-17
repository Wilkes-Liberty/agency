<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Permits a well-formed email and refuses every other contact kind.
 *
 * Hosts replace this service when contact resolution needs a CRM or directory.
 */
final class EmailContactPolicy implements ContactPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    return ($contact['kind'] ?? NULL) === 'email'
      && is_string($contact['value'] ?? NULL)
      && filter_var($contact['value'], FILTER_VALIDATE_EMAIL);
  }

}
