<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

/**
 * Records required invitation-custody transitions.
 *
 * Hosts may replace this with a site audit recorder. Failure must throw.
 */
interface DeliveryAuditInterface {

  /**
   * Persists minimized delivery evidence or throws.
   */
  public function record(string $operation, array $metadata): void;

}
