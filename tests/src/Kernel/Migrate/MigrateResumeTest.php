<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Interrupted runs resume without duplicates; a second run is a no-op.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateResumeTest extends MigrateKernelTestBase {

  /**
   * A failed batch preserves earlier completed batches and resume continues.
   */
  public function testInterruptedRunResumesWithoutDuplicates(): void {
    $this->seedSources();
    $this->container->get('state')->set('agency_migrate_test.interrupt_title', 'Beta');
    $this->assertRefused(fn () => $this->container->get('agency_migrate.runner')->run('synthetic_item', 1, TRUE), 'interrupted');
    $this->assertNotNull($this->target(1));
    $this->assertNull($this->target(2));
    $this->assertNull($this->target(3));
    $failed = $this->container->get('database')
      ->select('agency_migrate_run', 'r')
      ->fields('r')
      ->orderBy('started', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    $this->assertSame('failed', $failed['status']);
    $this->assertSame('interrupted', $failed['error']);
    $this->assertSame(1, (int) $failed['copied']);

    $this->container->get('state')->delete('agency_migrate_test.interrupt_title');
    $resumed = $this->container->get('agency_migrate.runner')->resume($failed['run_id'], TRUE);
    $this->assertSame('completed', $resumed['status']);
    $this->assertSame($failed['run_id'], $resumed['run_id']);
    $this->assertNotNull($this->target(2));
    $this->assertNotNull($this->target(3));
    $this->assertSame($this->source(1)->uuid(), $this->target(1)->uuid());
    $this->assertSame($this->source(2)->uuid(), $this->target(2)->uuid());
    $this->assertSame(3, (int) $this->container->get('agency_migrate.lineage')->countPair(
      'agency_migrate_legacy_item',
      'agency_migrate_agency_item',
    ));
  }

  /**
   * A second complete run copies nothing.
   */
  public function testSecondRunIsNoOp(): void {
    $this->seedSources();
    $first = $this->copyAll();
    $second = $this->copyAll();
    $this->assertSame('completed', $second['status']);
    $this->assertNotSame($first['run_id'], $second['run_id']);
    $this->assertSame(0, $second['copied']);
    $this->assertSame(3, $second['skipped']);
    $this->assertSame(3, (int) $this->container->get('agency_migrate.lineage')->countPair(
      'agency_migrate_legacy_item',
      'agency_migrate_agency_item',
    ));
  }

  /**
   * Omitting the execute flag writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    $this->seedSources();
    $plan = $this->container->get('agency_migrate.runner')->run('synthetic_item', 50, FALSE);
    $this->assertSame('dry_run', $plan['status']);
    $this->assertNull($this->target(1));
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_run', 'r')->countQuery()->execute()->fetchField());
  }

}
