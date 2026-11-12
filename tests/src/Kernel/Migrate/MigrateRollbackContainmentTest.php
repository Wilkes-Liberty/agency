<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\RollbackService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Qualified empty rollback is real; legacy journals remain unqualified.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRollbackContainmentTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item']]);
  }

  /**
   * Four-argument records remain readable but do not prove replay safety.
   */
  public function testLegacyJournalRefusesPreviewAndExecution(): void {
    $this->prepareDomain();
    $target = $this->target(1);
    $target->set('payload', 'Unqualified post-cutover change');
    $target->save();
    $this->record(1, $target->uuid());
    $before = $this->snapshot();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', $execute), 'rollback_unqualified');
      $this->assertSame($before, $this->snapshot());
    }
  }

  /**
   * All clean registered copier populations permit a concrete zero-write exit.
   */
  public function testQualifiedZeroJournalRollbackSucceeds(): void {
    $this->prepareDomain();
    $this->assertTrue($this->container->get('agency_migrate.reconciler')->compare('synthetic_item')->fullPassed());
    $plan = $this->rollback()->rollback('synthetic', 'rehearsal', FALSE);
    $this->assertSame('dry_run', $plan['status']);
    $this->assertSame(0, $plan['pending']);
    $receipt = $this->rollback()->rollback('synthetic', 'rehearsal', TRUE);
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(0, $receipt['replayed']);
    $this->assertSame('legacy', $receipt['authority']);
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
  }

  /**
   * A clean sample cannot hide an unqualified full domain.
   */
  public function testPassingSampleCannotQualifyRollback(): void {
    $this->prepareDomain();
    $this->container->get('entity_type.manager')->getStorage('agency_migrate_agency_item')->create([
      'id' => 99,
      'title' => 'Extra',
    ])->save();
    $sample = $this->container->get('agency_migrate.reconciler')->compare('synthetic_item', [1]);
    $this->assertTrue($sample->passed());
    $this->assertFalse($sample->fullPassed());
    $before = $this->snapshot();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_unqualified');
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * Logged UUIDs are checked against the exact current target in both modes.
   */
  public function testWrongJournalUuidRefuses(): void {
    $this->prepareDomain();
    $this->record(1, $this->container->get('uuid')->generate());
    $before = $this->snapshot();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', $execute), 'rollback_conflict');
      $this->assertSame($before, $this->snapshot());
    }
  }

  /**
   * Deletion and same-id replacement cannot resurrect an unrelated legacy row.
   */
  public function testReplacedTargetCannotConsumeEarlierJournalRecord(): void {
    $this->prepareDomain();
    $this->record(1, $this->target(1)->uuid());
    $this->source(1)->delete();
    $this->target(1)->delete();
    $this->container->get('entity_type.manager')->getStorage('agency_migrate_agency_item')->create([
      'id' => 1,
      'title' => 'Replacement',
    ])->save();
    $before = $this->snapshot();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_conflict');
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * A later bad identity cannot permit an earlier valid-looking prefix write.
   */
  public function testCompletePreflightPreventsPrefixMutation(): void {
    $this->prepareDomain();
    $target = $this->target(1);
    $target->set('payload', 'Must not replay');
    $target->save();
    $this->record(1, $target->uuid());
    $this->record(2, $this->container->get('uuid')->generate());
    $before = $this->snapshot();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_conflict');
    $this->assertSame($before, $this->snapshot());
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
  }

  /**
   * An undeclared registry never derives membership from type or domain names.
   */
  public function testMissingDomainRegistryRefuses(): void {
    $this->prepareDomain();
    $this->setSetting('agency_migrate_copier_domains', []);
    $before = $this->snapshot();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', $execute), 'rollback_unqualified');
      $this->assertSame($before, $this->snapshot());
    }
  }

  /**
   * Missing expected copiers cannot turn a partial registry into a full one.
   */
  public function testMissingExpectedCopierRefuses(): void {
    $this->prepareDomain();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item', 'missing']]);
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_unqualified');
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get('synthetic'));
  }

  /**
   * Explicit registry entries must also match the copier's declared domains.
   */
  public function testCopierOutsideRequestedDomainRefuses(): void {
    $this->prepareDomain();
    $this->container->get('state')->set('agency_migrate_test.copier_domains', ['other']);
    $this->container->get('plugin.manager.agency_migrate.copier')->clearCachedDefinitions();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_unqualified');
  }

  /**
   * Two declared copiers for one target cannot be resolved by discovery order.
   */
  public function testAmbiguousDomainTargetRefuses(): void {
    $this->prepareDomain();
    $this->container->get('state')->set('agency_migrate_test.extra_domain_copier', 'agency_migrate_agency_item');
    $this->container->get('plugin.manager.agency_migrate.copier')->clearCachedDefinitions();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item', 'extra_domain']]);
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_ambiguous');
  }

  /**
   * Every explicit domain copier must qualify, not only the first clean pair.
   */
  public function testEveryDomainCopierMustPassFullReconciliation(): void {
    $this->prepareDomain();
    $this->container->get('state')->set('agency_migrate_test.extra_domain_copier', 'user');
    $this->container->get('plugin.manager.agency_migrate.copier')->clearCachedDefinitions();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item', 'extra_domain']]);
    $before = $this->snapshot();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_unqualified');
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * An observed journal change at fence acquisition invalidates earlier checks.
   */
  public function testExecutionRechecksJournalInsideFence(): void {
    $this->prepareDomain();
    $this->assertSame('dry_run', $this->rollback()->rollback('synthetic', 'rehearsal', FALSE)['status']);
    $this->container->get('state')->set('agency_migrate_test.fence_journal_row', [
      'id' => 1,
      'uuid' => $this->target(1)->uuid(),
    ]);
    $before = $this->snapshot();
    $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', TRUE), 'rollback_unqualified');
    $fence = $this->container->get('agency_migrate.host_writer_fence');
    $this->assertTrue($fence->journalWasInjected());
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * A target-only lookup cannot supply the missing authority domain.
   */
  public function testTargetLookupRequiresExplicitQualifiedDomain(): void {
    $manager = $this->container->get('plugin.manager.agency_migrate.copier');
    $this->assertRefused(fn () => $manager->copierForTarget('agency_migrate_agency_item'), 'rollback_unqualified');
    $this->assertSame('synthetic_item', $manager->copierForTarget('agency_migrate_agency_item', 'synthetic')->getPluginId());
  }

  /**
   * A valid entity from an undeclared target type is not a domain write.
   */
  public function testJournalTargetTypeOutsideDomainRefuses(): void {
    $this->prepareDomain();
    $this->container->get('agency_migrate.writes')->record('synthetic', 'user', (int) $this->operator->id(), $this->operator->uuid());
    $before = $this->snapshot();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback('synthetic', 'rehearsal', $execute), 'rollback_unqualified');
      $this->assertSame($before, $this->snapshot());
    }
  }

  /**
   * Sets up a faithful synthetic domain before adding each explicit fault.
   */
  private function prepareDomain(): void {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'register');
    $authority->transition('synthetic', 'shadow', 'shadow');
    $authority->transition('synthetic', 'agency', 'cutover');
  }

  /**
   * Exercises the unchanged four-argument journal API.
   */
  private function record(int $id, string $uuid): void {
    $this->container->get('agency_migrate.writes')->record('synthetic', 'agency_migrate_agency_item', $id, $uuid);
  }

  /**
   * Gets the service under test.
   */
  private function rollback(): RollbackService {
    return $this->container->get('agency_migrate.rollback');
  }

  /**
   * Captures legacy records, journal markers and authority/evidence state.
   */
  private function snapshot(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item');
    $storage->resetCache();
    $snapshot = ['legacy' => [], 'authority' => $this->container->get('agency_migrate.authority')->status()];
    foreach ([1, 2, 3] as $id) {
      $snapshot['legacy'][$id] = $storage->load($id)?->toArray();
    }
    foreach (['agency_migrate_write_log', 'agency_migrate_authority_event', 'agency_migrate_outbox'] as $table) {
      $snapshot[$table] = [];
      $result = $this->container->get('database')->select($table, 't')->fields('t')->execute();
      while ($row = $result->fetchAssoc()) {
        $snapshot[$table][] = $row;
      }
    }
    return $snapshot;
  }

}
