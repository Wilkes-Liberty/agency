<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Vendor-neutral intake dispatch. Host adapters supply the transport.
 *
 * Timeout and 5xx are uncertain delivery. Retry reuses the original
 * key. The interface names no vendor. Default gateway stays unavailable.
 */
interface IntakeDispatchGatewayInterface {

  /**
   * Hands one dispatch key to host transport without choosing a vendor.
   *
   * @param string $dispatch_key
   *   Opaque idempotency key for this intent.
   * @param mixed $intent
   *   Opaque payload. Shape is not frozen.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Default implementation throws unavailable.
   * @throws \Drupal\agency_intake\Exception\UncertainDelivery
   *   Timeout or 5xx. Occupancy becomes uncertain. Retry uses this key.
   */
  public function dispatch(string $dispatch_key, mixed $intent): void;

}
