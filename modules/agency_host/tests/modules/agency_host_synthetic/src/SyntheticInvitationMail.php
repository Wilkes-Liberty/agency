<?php

declare(strict_types=1);

namespace Drupal\agency_host_synthetic;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\Delivery\InvitationMailInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Captures synthetic attempts and deterministic faults without any mail socket.
 */
final class SyntheticInvitationMail implements InvitationMailInterface {

  public function __construct(
    private readonly InvitationMailInterface $inner,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function prepare(array $content): array {
    $this->guard();
    if ($this->state->get('agency_synthetic_delivery_fault', 'none') === 'preparation') {
      throw new Refusal('delivery_transport_unavailable');
    }
    return $this->inner->prepare($content);
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $envelope): string {
    $this->guard();
    // No token, recipient or body enters the general fixture state store.
    $this->state->set('agency_synthetic_delivery_attempts',
      (int) $this->state->get('agency_synthetic_delivery_attempts', 0) + 1);
    return $this->state->get('agency_synthetic_delivery_fault', 'none') === 'unknown' ? 'unknown' : 'captured';
  }

  /**
   * This fixture can never be repurposed as a production mail adapter.
   */
  private function guard(): void {
    if (Settings::get('agency_synthetic_host') !== TRUE || Settings::get('agency_delivery_synthetic') !== TRUE
      || (Settings::get('agency_host_invitation_delivery', [])['mode'] ?? NULL) !== 'capture') {
      throw new Refusal('delivery_disabled');
    }
  }

}
