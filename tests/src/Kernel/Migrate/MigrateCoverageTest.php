<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\ReconciliationReport;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Full qualification compares actual populations; samples remain limited.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateCoverageTest extends MigrateKernelTestBase {

  /**
   * An extra target must fail even when every source still compares cleanly.
   */
  public function testFullReportRejectsExtraTarget(): void {
    $this->seedSources();
    $this->copyAll();
    $this->targetStorage()->create(['id' => 99, 'title' => 'Extra target'])->save();
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('count_mismatch', array_column($report->exceptions(), 'type'));
    $this->assertContains('inventory_mismatch', array_column($report->exceptions(), 'type'));
    $this->assertFalse($report->toArray()['coverage']['complete']);
  }

  /**
   * New source records cannot disappear from full coverage after a copy.
   */
  public function testFullReportRejectsNewSource(): void {
    $this->seedSources();
    $this->copyAll();
    $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item')->create(['title' => 'New source'])->save();
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('inventory_mismatch', array_column($report->exceptions(), 'type'));
    $this->assertFalse($report->fullPassed());
  }

  /**
   * Missing lineage is distinct from matching source and target contents.
   */
  public function testFullReportRejectsMissingLineage(): void {
    $this->seedSources();
    $this->copyAll();
    $this->container->get('database')->delete('agency_migrate_lineage')->condition('source_id', 1)->execute();
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('missing_lineage', array_column($report->exceptions(), 'type'));
    $this->assertContains('count_mismatch', array_column($report->exceptions(), 'type'));
  }

  /**
   * Equal population sizes cannot conceal different entity identifier sets.
   */
  public function testFullReportRejectsEqualCountsWithDifferentTargetSet(): void {
    $this->seedSources();
    $this->copyAll();
    $this->target(3)->delete();
    $this->targetStorage()->create(['id' => 99, 'title' => 'Replacement target'])->save();
    $report = $this->compare();
    $this->assertSame($report->toArray()['counts']['source'], $report->toArray()['counts']['target']);
    $this->assertFalse($report->passed());
    $this->assertContains('inventory_mismatch', array_column($report->exceptions(), 'type'));
  }

  /**
   * Orphaned lineage cannot qualify the remaining clean source/target pairs.
   */
  public function testFullReportRejectsOrphanedLineage(): void {
    $this->seedSources();
    $this->copyAll();
    $this->source(3)->delete();
    $this->target(3)->delete();
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('inventory_mismatch', array_column($report->exceptions(), 'type'));
    $this->assertContains('count_mismatch', array_column($report->exceptions(), 'type'));
  }

  /**
   * A lineage mapping is compared to both inventories, not just row totals.
   */
  public function testFullReportRejectsChangedLineageIdentitySet(): void {
    $this->seedSources();
    $this->copyAll();
    $this->container->get('database')->update('agency_migrate_lineage')->fields(['target_id' => 99])->condition('source_id', 1)->execute();
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('inventory_mismatch', array_column($report->exceptions(), 'type'));
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Sampling a clean record does not qualify an uninspected extra target.
   */
  public function testSampleIsDeduplicatedAndNeverFullQualification(): void {
    $this->seedSources();
    $this->copyAll();
    $this->targetStorage()->create(['id' => 99, 'title' => 'Unsampled target'])->save();
    $report = $this->compare([1, 1]);
    $this->assertTrue($report->passed());
    $this->assertFalse($report->fullPassed());
    $payload = $report->toArray();
    $this->assertSame(1, $payload['counts']['sampled']);
    $this->assertSame('sample', $payload['coverage']['scope']);
    $this->assertFalse($payload['coverage']['complete']);
    $this->assertFalse($payload['full_passed']);
  }

  /**
   * Matching complete populations can qualify a full read-only comparison.
   */
  public function testFullReportIdentifiesCompleteCoverage(): void {
    $this->seedSources();
    $this->copyAll();
    $report = $this->compare();
    $this->assertTrue($report->passed());
    $this->assertTrue($report->fullPassed());
    $payload = $report->toArray();
    $this->assertSame('full', $payload['coverage']['scope']);
    $this->assertTrue($payload['coverage']['complete']);
    $this->assertSame(3, $payload['coverage']['inspected']);
    $this->assertSame(3, $payload['coverage']['total_source']);
    $this->assertSame($payload['coverage']['source_inventory_digest'], $payload['coverage']['target_inventory_digest']);
  }

  /**
   * An empty full scan is qualified only after checking all three inventories.
   */
  public function testEmptyFullReportCanPass(): void {
    $report = $this->compare();
    $this->assertTrue($report->passed());
    $this->assertTrue($report->fullPassed());
    $this->assertSame(0, $report->toArray()['coverage']['inspected']);
  }

  /**
   * A truncated source scan cannot silently stand in for the full population.
   */
  public function testIncompleteSourcePaginationRefusesQualification(): void {
    $this->seedSources();
    $this->copyAll();
    $this->container->get('state')->set('agency_migrate_test.short_source_inventory', TRUE);
    $report = $this->compare();
    $this->assertFalse($report->passed());
    $this->assertContains('coverage_unverified', array_column($report->exceptions(), 'type'));
  }

  /**
   * Duplicate and nonadvancing pages terminate rather than loop or qualify.
   */
  public function testNonAdvancingInventoryPaginationRefusesQualification(): void {
    $this->seedSources();
    $this->copyAll();
    foreach (['repeat_source_inventory', 'repeat_target_inventory'] as $flag) {
      $this->container->get('state')->set('agency_migrate_test.' . $flag, TRUE);
      $report = $this->compare();
      $this->assertFalse($report->passed());
      $this->assertContains('coverage_unverified', array_column($report->exceptions(), 'type'));
      $this->container->get('state')->delete('agency_migrate_test.' . $flag);
    }
  }

  /**
   * Existing adapters cannot qualify an inventory they cannot enumerate.
   */
  public function testAdapterWithoutTargetInventoryCannotQualifyFullReport(): void {
    $this->seedSources();
    $this->copyAll();
    $report = $this->container->get('agency_migrate.reconciler')->compare('legacy_contract');
    $this->assertFalse($report->passed());
    $this->assertContains('coverage_unverified', array_column($report->exceptions(), 'type'));
  }

  /**
   * A report constructed directly still refuses inconsistent full totals.
   */
  public function testReportDefensivelyRejectsCountMismatch(): void {
    $report = new ReconciliationReport(['source' => 1, 'target' => 2, 'matched' => 1, 'sampled' => 1], [], []);
    $this->assertFalse($report->passed());
    $this->assertFalse($report->fullPassed());
  }

  /**
   * Equal totals without inventory evidence cannot qualify a full report.
   */
  public function testEqualCountsWithoutCoverageCannotQualify(): void {
    $report = new ReconciliationReport(['source' => 1, 'target' => 1, 'matched' => 1, 'sampled' => 1], [], []);
    $this->assertFalse($report->fullPassed());
    $this->assertFalse($report->passed());
  }

  /**
   * Unsaved cached changes are not persisted inventory identities.
   */
  public function testInventoryDiscardsUnsavedEntityCacheChanges(): void {
    $this->seedSources();
    $this->copyAll();
    $cached = $this->target(1);
    $cached->set('uuid', $this->container->get('uuid')->generate());
    $this->assertTrue($this->compare()->fullPassed());
  }

  /**
   * Cached old values cannot conceal changed persisted inventory identities.
   */
  public function testInventoryReadsPersistedChangesBehindCache(): void {
    $this->seedSources();
    $this->copyAll();
    $this->target(1);
    $this->container->get('database')->update('agency_migrate_agency_item')->fields(['uuid' => $this->container->get('uuid')->generate()])->condition('id', 1)->execute();
    $report = $this->compare();
    $this->assertFalse($report->fullPassed());
    $this->assertFalse($report->toArray()['coverage']['complete']);
    $this->assertContains('identity_drift', array_column($report->exceptions(), 'type'));
  }

  /**
   * Compares the synthetic pair using full or explicit sample semantics.
   */
  private function compare(array $ids = []): ReconciliationReport {
    return $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', $ids);
  }

  /**
   * Gets the target's installed storage for synthetic population changes.
   */
  private function targetStorage(): ContentEntityStorageInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_agency_item');
    $this->assertInstanceOf(ContentEntityStorageInterface::class, $storage);
    return $storage;
  }

}
