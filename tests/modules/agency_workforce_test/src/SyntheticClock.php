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

  public function getCurrentTime(): int {
    return (int) $this->config->get('agency_workforce_test.settings')->get('clock');
  }

  public function getRequestTime(): int {
    return $this->getCurrentTime();
  }

  public function getCurrentMicroTime(): float {
    return (float) $this->getCurrentTime();
  }

  public function getRequestMicroTime(): float {
    return $this->getCurrentMicroTime();
  }

}
