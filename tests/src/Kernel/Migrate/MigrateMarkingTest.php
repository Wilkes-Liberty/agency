<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * An unmapped property fails the run before any target write.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateMarkingTest extends MigrateKernelTestBase {

  /**
   * Missing markings stop the run with no target rows and no lineage.
   *
   * The refused write still records a minimized outbox intent.
   */
  public function testUnmappedMarkingStopsBeforeWrites(): void {
    $this->seedSources();
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.runner')->run('synthetic_item_unmapped', 50, TRUE),
      'unmapped_marking',
    );
    $this->assertNull($this->target(1));
    $this->assertNull($this->target(2));
    $this->assertNull($this->target(3));
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_run', 'r')->countQuery()->execute()->fetchField());
    $events = $this->assertMinimizedMigrateEvidence('unmapped_marking');
    $this->assertCount(1, $events);
    $this->assertSame('unmapped_marking', $events[0]['reason']);
    $this->assertSame('synthetic_item_unmapped', $events[0]['plugin_id']);
  }

}
