<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

/**
 * Vendor-neutral intake dispatch. Host adapters supply the transport.
 *
 * CRM transfer §5. The interface names no vendor. Payload shape, retry
 * and uncertain-delivery semantics stay open.
 *
 * @todo S3 G1 D7 Retry and uncertain-delivery semantics stay open.
 */
interface IntakeDispatchGatewayInterface {

  /**
   * Hands one dispatch key to host transport without choosing a vendor.
   *
   * @param string $dispatch_key
   *   Opaque idempotency key for this intent.
   * @param mixed $intent
   *   Opaque payload. Shape is not frozen while G1 is open.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   */
  public function dispatch(string $dispatch_key, mixed $intent): void;

}
