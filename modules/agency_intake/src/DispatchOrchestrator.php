<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\UncertainDelivery;

/**
 * Reserves a key, hands it to host transport, and records occupancy.
 *
 * Timeout and 5xx stay uncertain and retry the original key. The public
 * body never includes that key. Constructed in tests.
 */
final class DispatchOrchestrator {

  /**
   * Constructs an orchestrator over a key store and host gateway.
   *
   * @param \Drupal\agency_intake\DispatchKeyStoreInterface $keys
   *   Occupancy and digest binding. Default container stays unavailable.
   * @param \Drupal\agency_intake\IntakeDispatchGatewayInterface $gateway
   *   Vendor-neutral transport. Default container stays unavailable.
   */
  public function __construct(
    private readonly DispatchKeyStoreInterface $keys,
    private readonly IntakeDispatchGatewayInterface $gateway,
  ) {}

  /**
   * Sends one intent, reusing the original key after uncertain delivery.
   *
   * @param string $key
   *   Opaque idempotency key for this intent.
   * @param mixed $intent
   *   Opaque payload. Shape is not frozen.
   * @param string $intent_digest
   *   Digest bound to this key.
   *
   * @return array{outcome: string}
   *   Sent outcome without the dispatch identifier.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Duplicate sent key, new key for a bound digest, or empty input.
   * @throws \Drupal\agency_intake\Exception\UncertainDelivery
   *   Host transport timed out or returned 5xx. Occupancy is uncertain.
   */
  public function send(
    string $key,
    mixed $intent,
    string $intent_digest = '',
  ): array {
    $this->keys->reserve($key, $intent_digest);
    try {
      $this->gateway->dispatch($key, $intent);
    }
    catch (UncertainDelivery $exception) {
      $this->keys->rememberUncertain($key);
      throw $exception;
    }
    $this->keys->rememberSent($key);
    return ['outcome' => 'sent'];
  }

}
