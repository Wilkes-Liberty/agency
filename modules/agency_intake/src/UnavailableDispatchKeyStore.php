<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Default dispatch-key store. Throws until a host adapter is supplied.
 */
final class UnavailableDispatchKeyStore implements DispatchKeyStoreInterface {

  /**
   * {@inheritdoc}
   */
  public function reserve(string $dispatch_key): void {
    throw new IntakeRefusal('unavailable');
  }

}
