<?php

declare(strict_types=1);

namespace Drupal\agency_campaign\Exception;

/**
 * Carries an allowlisted public reason without campaign payload.
 */
final class CampaignRefusal extends \RuntimeException {

  /**
   * Stable refusal reason, without diagnostic payload.
   */
  public readonly string $reason;

  public function __construct(string $reason) {
    $this->reason = in_array($reason, [
      'forbidden',
      'invalid',
      'timeout',
      'not_found',
      'collision',
      'failed',
    ], TRUE) ? $reason : 'failed';
    parent::__construct($this->reason);
  }

}
