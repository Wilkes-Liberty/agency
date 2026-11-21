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
  public function reserve(string $key, string $intent_digest = ''): void {
    throw new IntakeRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function occupancy(?string $key): ?string {
    throw new IntakeRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function rememberUncertain(string $key): void {
    throw new IntakeRefusal('unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function rememberSent(string $key): void {
    throw new IntakeRefusal('unavailable');
  }

}
