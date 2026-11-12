<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Deterministic synthetic host time, shared through saved configuration.
 */
final class SyntheticClock implements TimeInterface {

  public function __construct(private readonly ConfigFactoryInterface $config) {}

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
    return $this->getCurrentTime();
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
    return $this->getCurrentMicroTime();
  }

}
