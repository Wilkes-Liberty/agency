<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Deterministic synthetic host time, shared through saved configuration.
 */
final class SyntheticClock implements TimeInterface {

  /**
   * Fixed request time, distinct from the fresh current clock after waiting.
   */
  private readonly int $requestTime;

  public function __construct(private readonly ConfigFactoryInterface $config) {
    $this->requestTime = $this->getCurrentTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return (int) $this->config->get('agency_workforce_test.settings')->get('clock');
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return $this->requestTime;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) $this->getCurrentTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) $this->requestTime;
  }

}
