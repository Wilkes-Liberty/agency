<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;

/**
 * Refuses cutover until the host supplies and verifies all writer coverage.
 */
final class UnavailableHostWriterFence implements HostWriterFenceInterface {

  /**
   * {@inheritdoc}
   */
  public function assertAvailable(string $domain): void {
    throw new MigrateRefusal('writer_fence_unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function acquire(string $domain): void {
    throw new MigrateRefusal('writer_fence_unavailable');
  }

}
