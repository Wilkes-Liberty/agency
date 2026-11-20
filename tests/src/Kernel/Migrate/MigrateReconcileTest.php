<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\ReconciliationReport;
use Drupal\agency_migrate_test\Entity\SyntheticItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reconciliation detects field drift, identity drift and access mismatch.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateReconcileTest extends MigrateKernelTestBase {

  /**
   * A faithful copy reports pass for matching access decisions.
   */
  public function testCleanCopyPasses(): void {
    $this->seedSources();
    $this->source(2)->set('related_id', 1)->save();
    $this->copyAll();
    $report = $this->container->get('agency_migrate.reconciler')->compare(
      'synthetic_item',
      [1, 2, 3],
      ['owner-2', 'other'],
    );
    $this->assertTrue($report->passed());
    $payload = $report->toArray();
    $this->assertSame(3, $payload['counts']['source']);
    $this->assertSame(3, $payload['counts']['target']);
    $this->assertSame(3, $payload['counts']['matched']);
    $this->assertSame([], $payload['exceptions']);
  }

  /**
   * Injected drift, a dangling reference and an access mismatch each fail.
   */
  public function testDetectsDriftBrokenReferenceAndAccessMismatch(): void {
    $this->seedSources();
    $this->copyAll();
    $drift = $this->target(1);
    $drift->set('payload', 'injected-drift');
    $drift->save();
    $broken = $this->target(2);
    $broken->set('related_id', 99);
    $broken->save();
    $access = $this->target(3);
    $access->set('access_class', 'restricted');
    $access->save();

    $report = $this->container->get('agency_migrate.reconciler')->compare(
      'synthetic_item',
      [1, 2, 3],
      ['owner-3', 'other'],
    );
    $this->assertFalse($report->passed());
    $types = array_column($report->exceptions(), 'type');
    $this->assertContains('field_drift', $types);
    $this->assertContains('broken_reference', $types);
    $this->assertContains('access_mismatch', $types);
    $drift_rows = array_values(array_filter($report->exceptions(), static fn (array $row): bool => $row['type'] === 'field_drift'));
    $this->assertSame('payload', $drift_rows[0]['field']);
    $this->assertSame(1, $drift_rows[0]['id']);
    $broken_rows = array_values(array_filter($report->exceptions(), static fn (array $row): bool => $row['type'] === 'broken_reference'));
    $this->assertSame('target', $broken_rows[0]['side']);
    $this->assertSame(99, $broken_rows[0]['referenced_id']);
    $access_rows = array_values(array_filter($report->exceptions(), static fn (array $row): bool => $row['type'] === 'access_mismatch'));
    $this->assertSame(3, $access_rows[0]['id']);
    $this->assertSame('other', $access_rows[0]['principal']);
    $this->assertTrue($access_rows[0]['source']);
    $this->assertFalse($access_rows[0]['target']);
  }

  /**
   * A sampled target with the right id cannot hide a different UUID.
   */
  public function testSampleReportsIdentityDriftWhenTargetUuidChanges(): void {
    $this->seedSources();
    $this->copyAll();
    $this->assertTrue($this->compareSample()->passed());
    $this->container->get('database')->update('agency_migrate_agency_item')
      ->fields(['uuid' => $this->container->get('uuid')->generate()])
      ->condition('id', 1)->execute();
    $report = $this->compareSample();
    $this->assertFalse($report->passed());
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
    $this->assertSame([], array_values(array_filter(
      $report->exceptions(),
      static fn (array $row): bool => $row['type'] === 'field_drift' && ($row['field'] ?? NULL) === 'uuid',
    )));
  }

  /**
   * A sampled target with the right id cannot hide a different revision.
   */
  public function testSampleReportsIdentityDriftWhenTargetRevisionChanges(): void {
    $this->seedSources();
    $this->copyAll();
    $target = $this->target(1);
    $this->assertInstanceOf(SyntheticItem::class, $target);
    $before = (int) $target->getRevisionId();
    $target->setNewRevision(TRUE);
    $target->setRevisionLogMessage('identity probe');
    $target->save();
    $this->assertNotSame($before, (int) $this->target(1)->getRevisionId());
    $report = $this->compareSample();
    $this->assertFalse($report->passed());
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Source and target UUIDs are compared even when each side matches lineage.
   */
  public function testSampleReportsIdentityDriftWhenLineageMatchesDivergentUuids(): void {
    $this->seedSources();
    $this->copyAll();
    $uuid = $this->container->get('uuid')->generate();
    $this->container->get('database')->update('agency_migrate_agency_item')
      ->fields(['uuid' => $uuid])->condition('id', 1)->execute();
    $this->container->get('database')->update('agency_migrate_lineage')
      ->fields(['target_uuid' => $uuid])->condition('source_id', 1)->execute();
    $report = $this->compareSample();
    $this->assertFalse($report->passed());
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * A lineage revision id is compared to live records, not just row totals.
   */
  public function testSampleReportsIdentityDriftWhenLineageRevisionDiverges(): void {
    $this->seedSources();
    $this->copyAll();
    $this->container->get('database')->update('agency_migrate_lineage')
      ->fields(['target_revision_id' => 99])->condition('source_id', 1)->execute();
    $report = $this->compareSample();
    $this->assertFalse($report->passed());
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Compares one sampled synthetic pair.
   *
   * @param list<int> $ids
   *   Source ids to inspect.
   */
  private function compareSample(array $ids = [1]): ReconciliationReport {
    return $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', $ids);
  }

}
