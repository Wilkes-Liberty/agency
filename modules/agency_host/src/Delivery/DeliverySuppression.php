<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;

/**
 * Uses the installed public policy; absence is never consent.
 */
final class DeliverySuppression implements DeliverySuppressionInterface {

  public function __construct(private readonly ?SuppressionPolicyInterface $policy = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function permits(string $recipient): bool {
    try {
      $decision = $this->policy?->decide($recipient);
      return $decision !== NULL && !$decision->suppressed && $decision->reason === 'no_active_suppression';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
