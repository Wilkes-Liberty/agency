<?php

declare(strict_types=1);

namespace Drupal\agency_host\Hook;

use Drupal\agency_host\Console\ConsoleStateStore;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Removes orphan console metadata only after confirmed Core session removal.
 */
final class ConsoleCleanupHooks {

  public function __construct(private readonly ConsoleStateStore $store) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->store->collectOrphans();
  }

}
