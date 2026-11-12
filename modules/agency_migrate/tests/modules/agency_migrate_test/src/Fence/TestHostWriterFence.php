<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Fence;

use Drupal\agency_migrate\HostWriterFenceInterface;
use Drupal\Core\Database\Connection;

/**
 * Test double for transition/replay mechanics, never proof of host coverage.
 *
 * This explicitly does not fence source/target tables. Only the operational
 * authority row serialization is exercised by the independent-process tests.
 */
final class TestHostWriterFence implements HostWriterFenceInterface {

  public function __construct(private readonly Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public function assertAvailable(string $domain): void {}

  /**
   * {@inheritdoc}
   */
  public function acquire(string $domain): void {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('A caller transaction is required.');
    }
  }

}
