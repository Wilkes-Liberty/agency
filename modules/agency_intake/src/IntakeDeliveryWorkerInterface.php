<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Tenant-scoped queued delivery port. Never performs remote work.
 *
 * Default implementation stays unavailable. Host adapters replace it.
 * Vendor transport stays a host adapter. Pending occupancy reuses
 * UncertainDelivery. Consumed and missing keys share not_found.
 */
interface IntakeDeliveryWorkerInterface {

  /**
   * Processes one queued delivery without remote work.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string}
   *   Processed outcome without payload, dispatch key or vendor fields.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim, missing id, foreign tenant, or consumed key.
   * @throws \Drupal\agency_intake\Exception\UncertainDelivery
   *   Reserved or uncertain occupancy. Retry reuses this identifier.
   */
  public function process(IntakePrincipal $principal, string $id): array;

}
