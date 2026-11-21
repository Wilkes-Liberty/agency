<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\Exception\UncertainDelivery;

/**
 * Occupancy-aware queued delivery. Never calls host transport.
 *
 * Reserved and uncertain occupancy stay UncertainDelivery so a retry
 * cannot mint a second intent. Sent and missing keys share not_found.
 * Constructed in tests.
 */
final class IntakeDeliveryWorker implements IntakeDeliveryWorkerInterface {

  /**
   * Constructs a worker over a dispatch-key occupancy store.
   *
   * @param \Drupal\agency_intake\DispatchKeyStoreInterface $keys
   *   Occupancy and digest binding. Default container stays unavailable.
   */
  public function __construct(
    private readonly DispatchKeyStoreInterface $keys,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(IntakePrincipal $principal, string $id): array {
    $principal->requireTenant();
    $state = $this->keys->occupancy($id === '' ? NULL : $id);
    if ($state === 'uncertain' || $state === 'reserved') {
      throw new UncertainDelivery();
    }
    throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
  }

}
