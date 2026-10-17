<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reconciliation detects field drift, broken references and access mismatch.
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

}
