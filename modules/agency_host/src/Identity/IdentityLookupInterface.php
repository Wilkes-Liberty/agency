<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Optional bounded exact-contact lookup, distinct from subject authority.
 */
interface IdentityLookupInterface {

  /**
   * Returns exactly one freshly read eligible candidate, or no selection.
   *
   * @throws \Drupal\agency_host\Identity\IdentityDirectoryUnavailable
   *   The configured service could not answer authoritatively.
   */
  public function findEmail(string $email): ?array;

}
