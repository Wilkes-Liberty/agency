<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

/**
 * Requires authoritative suppression clearance in addition to contact consent.
 */
interface DeliverySuppressionInterface {

  /**
   * Unknown, disabled and unavailable suppression policy all refuse delivery.
   */
  public function permits(string $recipient): bool;

}
