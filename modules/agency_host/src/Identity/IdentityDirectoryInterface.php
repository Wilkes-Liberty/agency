<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Reads current identity-provider state without creating or linking accounts.
 */
interface IdentityDirectoryInterface {

  /**
   * Returns a current subject representation, NULL when it does not exist.
   *
   * @throws \Drupal\agency_host\Identity\IdentityDirectoryUnavailable
   *   When the provider cannot establish a current answer.
   */
  public function read(string $subject): ?array;

}
