<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\Tests\agency\Fixtures\MigrateBatchSqlFault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Failed batches cannot separate copies, progress and evidence intents.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateBatchAtomicityTest extends MigrateKernelTestBase {

  /**
   * Real SQL failures preserve the prior batch and allow an exact resume.
   *
   * @dataProvider faultPoints
   */
  #[DataProvider('faultPoints')]
  public function testFailedBatchIsAtomic(string $point): void {
    $this->seedSources();
    $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item')->create([
      'title' => 'Delta',
      'payload' => 'body-d',
      'access_class' => 'open',
    ])->save();
    $database = $this->container->get('database');
    $fault = new MigrateBatchSqlFault($database, $point);
    $fault->install();
    try {
      $this->assertRefused(
        fn () => $this->container->get('agency_migrate.runner')->run('synthetic_item', 2, TRUE),
        'interrupted',
      );
      $this->assertFalse($database->inTransaction());
    }
    finally {
      $fault->remove();
    }
    $failed = $database->select('agency_migrate_run', 'r')->fields('r')->execute()->fetchAssoc();
    $this->assertSame('failed', $failed['status']);
    $this->assertSame('interrupted', $failed['error']);
    $this->assertSame(2, (int) $failed['cursor_source_id']);
    $this->assertSame(2, (int) $failed['copied']);
    $this->assertSame(0, (int) $failed['skipped']);
    $this->assertNotNull($this->target(1));
    $this->assertNotNull($this->target(2));
    $this->assertNull($this->target(3));
    $this->assertNull($this->target(4));
    $this->assertSame(2, (int) $database->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField());
    $this->assertSame([[1, 2, 2]], $this->windows($failed['run_id']));

    $receipt = $this->container->get('agency_migrate.runner')->resume($failed['run_id'], TRUE);
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(4, $receipt['cursor_source_id']);
    $this->assertSame(4, $receipt['copied']);
    $this->assertSame(0, $receipt['skipped']);
    $this->assertSame(4, (int) $database->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField());
    foreach ([1, 2, 3, 4] as $id) {
      $this->assertSame($this->source($id)->uuid(), $this->target($id)->uuid());
    }
    $this->assertSame([[1, 2, 2], [3, 4, 2]], $this->windows($failed['run_id']));
    $again = $this->container->get('agency_migrate.runner')->resume($failed['run_id'], TRUE);
    $this->assertSame($receipt, $again);
    $this->assertSame([[1, 2, 2], [3, 4, 2]], $this->windows($failed['run_id']));
  }

  /**
   * Boundaries after target copy, after cursor update and after intent insert.
   */
  public static function faultPoints(): array {
    return [
      'target before cursor' => ['target_before_cursor'],
      'cursor before outbox' => ['cursor_before_outbox'],
      'outbox insert failure' => ['outbox_failure'],
    ];
  }

  /**
   * Reads independently persisted, minimized intent windows.
   *
   * @return list<array{int, int, int}>
   *   Source range and copied count for each window.
   */
  private function windows(string $run_id): array {
    $result = $this->container->get('database')->select('agency_migrate_outbox', 'o')
      ->fields('o', ['metadata'])->condition('operation', 'lineage_batch')->execute();
    $windows = [];
    while ($row = $result->fetchAssoc()) {
      $metadata = json_decode($row['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
      if ($metadata['run_id'] === $run_id) {
        $windows[] = [$metadata['id_from'], $metadata['id_to'], $metadata['count']];
      }
    }
    sort($windows);
    return $windows;
  }

}
