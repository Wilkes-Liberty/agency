<?php

declare(strict_types=1);

namespace Drupal\agency_host\Hook;

use Drupal\agency_host\EvidenceHealthMonitor;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Checks delivery health through Agency's public aggregate port.
 */
final class EvidenceHealthHooks {

  public function __construct(private readonly EvidenceHealthMonitor $monitor) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->monitor->check();
  }

}
