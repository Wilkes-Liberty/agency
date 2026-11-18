<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\agency_migrate\PostCutoverWriter;
use Drupal\agency_migrate\RollbackAttempt;
use Drupal\agency_migrate\RollbackService;
use Drupal\agency_migrate_rollback_test\RollbackTestStorage;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\agency\Fixtures\MigrateOperationSqlFault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

/**
 * Ordered rollback proves the journal, applies it and seals the handover.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateQualifiedRollbackTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['agency_migrate_operation_test', 'agency_migrate_rollback_test'];

  private const DOMAIN = 'synthetic';
  private const SOURCE = 'agency_migrate_legacy_item';
  private const TARGET = 'agency_migrate_agency_item';
  private const REASON = 'Qualified reversal rehearsal';

  /**
   * Interleaved revised and deleted records retain exact durable outcomes.
   */
  public function testOrderedReplayPreservesHistoryProvenanceAndReceipt(): void {
    $generation = $this->prepareDomain();
    $first = $this->create($generation, 'Created after cutover');
    $second = $this->create($generation, 'Transient post-cutover record');
    $original = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $original);
    $existing = $this->target(2);
    $this->assertInstanceOf(ContentEntityInterface::class, $existing);
    $this->update($generation, $original, 'Updated copied record');
    $this->update($generation, $this->target($first['entity_id']), 'Updated created record');
    $deleted = $this->delete($generation, $this->target($second['entity_id']));
    $removed = $this->delete($generation, $existing);
    $original_lineage = $this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, 2);
    $this->assertIsArray($original_lineage);
    $preview = $this->rollback()->rollback(self::DOMAIN, self::REASON, FALSE, $generation);
    $this->assertSame('dry_run', $preview['status']);
    $this->assertSame(6, $preview['pending']);
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);

    $receipt = $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertSame('legacy', $receipt['authority']);
    $this->assertSame(6, $receipt['replayed']);
    $this->assertSame([
      ['restore', $first['entity_id']],
      ['restore', $second['entity_id']],
      ['restore', 1],
      ['restore', $first['entity_id']],
      ['delete', $second['entity_id']],
      ['delete', 2],
    ], RollbackTestStorage::$effects);
    $this->assertSame($generation + 1, $receipt['generation']);
    $this->assertSame($receipt, $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation));
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, 'Different reason', TRUE, $generation), 'rollback_stale_generation');
    foreach ([1, $first['entity_id']] as $id) {
      $source = $this->history()->captureHistory($this->source($id));
      $source['source_type'] = self::TARGET;
      $this->assertSame($source, $this->history()->captureHistory($this->target($id)));
    }
    $database = $this->container->get('database');
    $storage = $this->container->get('entity_type.manager')->getStorage(self::SOURCE);
    foreach ([2 => $removed, $second['entity_id'] => $deleted] as $id => $operation) {
      $this->assertNull($storage->load($id));
      $this->assertNull($this->target($id));
      $tombstone = $database->select('agency_migrate_rollback_record', 'r')->fields('r')
        ->condition('domain', self::DOMAIN)->condition('entity_id', $id)->execute()->fetchAssoc();
      $this->assertIsArray($tombstone);
      $this->assertSame($operation['wid'], (int) $tombstone['delete_wid']);
      $this->assertSame($operation['entity_uuid'], $tombstone['entity_uuid']);
    }
    $this->assertSame($original_lineage, $this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, 2));
    $this->assertNull($this->container->get('agency_migrate.lineage')->bySource(self::SOURCE, $first['entity_id']));
    $this->assertSame([], $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertTrue($this->container->get('agency_migrate.reconciler')->compare('synthetic_item')->fullPassed());
    $authority = $this->container->get('agency_migrate.authority');
    $authority->transition(self::DOMAIN, 'shadow', 'Return to shadow');
    $authority->transition(self::DOMAIN, 'agency', 'Second Agency generation');
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_stale_generation');
  }

  /**
   * A second cutover and return uses the first sealed effective histories.
   */
  public function testEffectiveProvenanceSupportsSecondRollback(): void {
    $generation = $this->prepareDomain();
    $lineage = $this->container->get('agency_migrate.lineage');
    $original = $lineage->bySource(self::SOURCE, 1);
    $created = $this->create($generation, 'Created during first Agency generation');
    $this->update($generation, $this->target(1), 'First rollback update');
    $this->delete($generation, $this->target(2));
    $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $reconciler = $this->container->get('agency_migrate.reconciler');
    $this->assertTrue($reconciler->compare('synthetic_item')->fullPassed());

    $authority = $this->container->get('agency_migrate.authority');
    $authority->transition(self::DOMAIN, 'shadow', 'Review effective histories');
    $authority->transition(self::DOMAIN, 'agency', 'Second qualified cutover');
    $next = $authority->status()[self::DOMAIN]['version'];
    $this->update($next, $this->target($created['entity_id']), 'Changed after second cutover');
    $this->delete($next, $this->target(1));
    $new = $this->create($next, 'Created during second Agency generation');
    $this->assertSame(3, $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $next)['replayed']);
    $this->assertTrue($reconciler->compare('synthetic_item')->fullPassed());
    $this->assertSame('Changed after second cutover', $this->source($created['entity_id'])->get('payload')->value);
    $this->assertSame('Created during second Agency generation', $this->source($new['entity_id'])->get('title')->value);
    $this->assertNull($this->target(1));
    $this->assertNull($this->target(2));
    $this->assertSame($original, $lineage->bySource(self::SOURCE, 1));
    $this->assertNull($lineage->bySource(self::SOURCE, $created['entity_id']));
  }

  /**
   * Relabeling effective provenance cannot create a new reconciliation baseline.
   */
  public function testReconciliationRefusesAlteredEffectiveProvenance(): void {
    $generation = $this->prepareDomain();
    $created = $this->create($generation, 'Covered by retained provenance');
    $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $reconciler = $this->container->get('agency_migrate.reconciler');
    $this->assertTrue($reconciler->compare('synthetic_item')->fullPassed());
    $this->container->get('database')->update('agency_migrate_rollback_record')->fields(['history_digest' => str_repeat('0', 64)])
      ->condition('entity_id', $created['entity_id'])->execute();
    $this->assertRefused(fn () => $reconciler->compare('synthetic_item'), 'coverage_unverified');
  }

  /**
   * Delivered intents expose a lost create/delete pair even after both vanish.
   */
  public function testDeliveredIntentsExposeMissingCreateDeletePair(): void {
    $generation = $this->prepareDomain();
    $record = $this->create($generation, 'Transient payload');
    $this->delete($generation, $this->target($record['entity_id']));
    $database = $this->container->get('database');
    $database->update('agency_migrate_outbox')->fields(['delivered' => 123])->condition('operation', 'post_cutover_write')->execute();
    $database->delete('agency_migrate_write_log')->condition('domain', self::DOMAIN)->execute();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, $execute, $generation), 'rollback_unqualified');
    }
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * A valid prefix cannot apply before a later operation is fully checked.
   */
  public function testInvalidLaterOperationLeavesLegacyUntouched(): void {
    $generation = $this->prepareDomain();
    $entity = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $this->update($generation, $entity, 'Must remain on Agency');
    $later = $this->create($generation, 'Malformed later journal row');
    $database = $this->container->get('database');
    $database->update('agency_migrate_write_log')->fields(['envelope' => '{}'])->condition('wid', $later['wid'])->execute();
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
    $this->assertSame([], RollbackTestStorage::$effects);
    $this->assertCount(2, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * A journal row without its exact retained intent refuses before effects.
   */
  public function testRollbackMissingOperationIntentRefuses(): void {
    $generation = $this->prepareDomain();
    $this->create($generation, 'Requires its operation intent');
    $this->container->get('database')->delete('agency_migrate_outbox')->condition('operation', 'post_cutover_write')->execute();
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertSame([], RollbackTestStorage::$effects);
  }

  /**
   * A changed snapshot qualification cannot authorize replay of an old row.
   */
  public function testRollbackRefusesChangedJournalQualification(): void {
    $generation = $this->prepareDomain();
    $this->update($generation, $this->target(1), 'Committed under the original contract');
    $pending = $this->container->get('agency_migrate.writes')->pending(self::DOMAIN);
    $this->assertCount(1, $pending);
    $this->container->get('state')->set('agency_operation.policy_case', 'changed_contract');
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, $execute, $generation), 'rollback_unqualified');
    }
    $this->assertSame([], RollbackTestStorage::$effects);
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
    $this->assertSame($pending, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * Drift in an untouched copier refuses before a valid journal prefix writes.
   */
  public function testRollbackRejectsUntouchedCopierDrift(): void {
    $this->container->get('state')->set('agency_rollback.second_pair', TRUE);
    $manager = $this->container->get('entity_type.manager');
    $manager->clearCachedDefinitions();
    $this->container->get('plugin.manager.agency_migrate.copier')->clearCachedDefinitions();
    $this->setSetting('agency_migrate_copier_domains', ['synthetic' => ['synthetic_item', 'synthetic_other']]);
    $this->installEntitySchema('agency_rollback_other_source');
    $this->installEntitySchema('agency_rollback_other_target');
    $other = $manager->getStorage('agency_rollback_other_source')->create(['title' => 'Other original']);
    $other->save();
    $this->container->get('agency_migrate.runner')->run('synthetic_other', 50, TRUE);
    $generation = $this->prepareDomain();
    $this->create($generation, 'Valid first operation');
    $target = $manager->getStorage('agency_rollback_other_target')->load($other->id());
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $target->set('payload', 'Unjournaled drift outside the affected copier');
    $target->save();
    $prior_effects = RollbackTestStorage::$effects;
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_conflict');
    $this->assertSame($prior_effects, RollbackTestStorage::$effects);
  }

  /**
   * A foreign historical revision can collide with a later planned operation.
   */
  public function testRollbackRejectsLaterRevisionCollisionBeforeLegacyWrites(): void {
    $generation = $this->prepareDomain();
    $this->update($generation, $this->target(1), 'Earlier valid edit');
    $this->update($generation, $this->target(2), 'Later colliding edit');
    $target = $this->target(2);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $foreign = $this->source(3);
    $foreign->setNewRevision(TRUE);
    $foreign->set('revision_id', $target->getRevisionId());
    $foreign->isDefaultRevision(FALSE);
    $foreign->save();
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_conflict');
    $this->assertSame([], RollbackTestStorage::$effects);
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
  }

  /**
   * References must resolve at their own operation in global journal order.
   */
  public function testRollbackRefusesForwardReferenceBeforeReplay(): void {
    $generation = $this->prepareDomain();
    $first = $this->writer()->create(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, [
      'title' => 'Refers to the following record',
      'related_id' => 5,
    ]);
    $second = $this->create($generation, 'Later reference destination');
    $this->assertSame(4, $first['entity_id']);
    $this->assertSame(5, $second['entity_id']);
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_conflict');
    $this->assertSame([], RollbackTestStorage::$effects);
  }

  /**
   * Failed final access parity undoes applied history and keeps Agency serving.
   */
  public function testRollbackAccessMismatchRestoresLegacy(): void {
    $generation = $this->prepareDomain();
    $before = $this->history()->captureHistory($this->source(1));
    $this->update($generation, $this->target(1), 'Would change access after replay');
    $this->container->get('state')->set('agency_rollback.access_mismatch', 1);
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_conflict');
    $this->assertSame([['restore', 1]], RollbackTestStorage::$effects);
    $this->assertSame($before, $this->history()->captureHistory($this->source(1)));
    $this->assertCount(1, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
  }

  /**
   * Unknown or prematurely marked operations cannot be treated as a zero log.
   *
   * @dataProvider inconsistentMarkers
   */
  #[DataProvider('inconsistentMarkers')]
  public function testRollbackRefusesInconsistentGenerationAndMarkers(string $case): void {
    $generation = $this->prepareDomain();
    $record = $this->create($generation, 'Do not lose this operation');
    $database = $this->container->get('database');
    if ($case === 'stale') {
      $generation += 2;
      $database->update('agency_migrate_authority_state')->fields(['version' => $generation])->condition('domain', self::DOMAIN)->execute();
    }
    else {
      $database->update('agency_migrate_write_log')->fields(['replayed' => 123])->condition('wid', $record['wid'])->execute();
    }
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertSame([], RollbackTestStorage::$effects);
  }

  /**
   * Retained evidence cannot repair stale generation or false replay state.
   */
  public static function inconsistentMarkers(): array {
    return [
      'old pending generation' => ['stale'],
      'false replay marker' => ['marked'],
    ];
  }

  /**
   * The schema upgrade preserves the original unqualified journal bytes.
   */
  public function testRollbackUpgradePreservesOldJournal(): void {
    $database = $this->container->get('database');
    $this->container->get('agency_migrate.writes')->record(self::DOMAIN, self::TARGET, 17, $this->container->get('uuid')->generate());
    $original = $this->container->get('agency_migrate.writes')->pending(self::DOMAIN);
    $database->schema()->dropTable('agency_migrate_rollback_receipt');
    $database->schema()->dropTable('agency_migrate_rollback_record');
    require_once $this->container->get('extension.list.module')->getPath('agency_migrate') . '/agency_migrate.install';
    agency_migrate_update_10005();
    agency_migrate_update_10005();
    $this->assertSame($original, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame(0, $this->countRows('agency_migrate_rollback_receipt'));
    $this->assertSame(0, $this->countRows('agency_migrate_rollback_record'));
  }

  /**
   * Faults after real legacy writes undo the entire handover.
   *
   * @dataProvider rollbackFaults
   */
  #[DataProvider('rollbackFaults')]
  public function testQualifiedRollbackFaultIsAtomic(string $stage, string $reason): void {
    $generation = $this->prepareDomain();
    $before = $this->history()->captureHistory($this->source(1));
    $deleted = $this->history()->captureHistory($this->source(2));
    $this->update($generation, $this->target(1), 'Do not leave a committed prefix');
    $this->delete($generation, $this->target(2));
    $database = $this->container->get('database');
    $evidence = $this->countRows('agency_migrate_outbox');
    $events = $this->countRows('agency_migrate_authority_event');
    $fault = in_array($stage, ['outbox_insert', 'outbox_extra'], TRUE)
      ? new MigrateOperationSqlFault($database, 'agency_migrate_outbox', $stage) : NULL;
    RollbackTestStorage::$interrupt = $stage;
    $fault?->install();
    try {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), $reason);
    }
    finally {
      $fault?->remove();
      RollbackTestStorage::$interrupt = NULL;
    }
    $this->assertFalse($database->inTransaction());
    $this->assertContains(['restore', 1], RollbackTestStorage::$effects);
    $this->assertSame($before, $this->history()->captureHistory($this->source(1)));
    $this->assertSame($deleted, $this->history()->captureHistory($this->source(2)));
    $this->assertCount(2, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $this->assertSame(0, $this->countRows('agency_migrate_rollback_receipt'));
    $this->assertSame(0, $this->countRows('agency_migrate_rollback_record'));
    $this->assertSame($evidence, $this->countRows('agency_migrate_outbox'));
    $this->assertSame($events, $this->countRows('agency_migrate_authority_event'));
    $this->assertSame('agency', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
    $this->assertSame('completed', $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation)['status']);
  }

  /**
   * Local SQL and evidence failure boundaries.
   */
  public static function rollbackFaults(): array {
    return [
      'restore after SQL' => ['restore', 'rollback_failed'],
      'delete after SQL' => ['delete', 'rollback_failed'],
      'authority intent insert' => ['outbox_insert', 'rollback_failed'],
      'authority intent changed' => ['outbox_extra', 'rollback_unqualified'],
    ];
  }

  /**
   * A lost commit acknowledgement returns the original result on retry.
   */
  public function testQualifiedRollbackLostCommitAcknowledgement(): void {
    $generation = $this->prepareDomain();
    $record = $this->create($generation, 'Exactly one committed record');
    RollbackTestStorage::$interrupt = 'commit';
    try {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_commit_uncertain');
    }
    finally {
      RollbackTestStorage::$interrupt = NULL;
    }
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame('legacy', $this->container->get('agency_migrate.authority')->get(self::DOMAIN));
    $receipt = $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(1, $receipt['replayed']);
    $this->assertSame([['restore', $record['entity_id']]], RollbackTestStorage::$effects);
    $this->assertSame(1, $this->countRows('agency_migrate_rollback_receipt'));
  }

  /**
   * An independent Agency writer stays blocked until the root handover commits.
   */
  public function testQualifiedRollbackExcludesConcurrentAgencyWriter(): void {
    $generation = $this->prepareDomain();
    $this->assertSame(3, $generation);
    $created = $this->create($generation, 'Original operation');
    $database = $this->container->get('database');
    $prefix = sys_get_temp_dir() . '/agency-rollback-' . bin2hex(random_bytes(8));
    $ready = $prefix . '-ready';
    $release = $prefix . '-release';
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $worker = new Process([
      PHP_BINARY,
      dirname(__DIR__, 3) . '/fixtures/migrate/operation-worker.php',
      $this->root,
      $vendor,
    ]);
    $worker->setInput(json_encode([
      'database' => $database->getConnectionOptions(),
      'mode' => 'new operation',
      'operation_id' => $this->container->get('uuid')->generate(),
      'ready' => $ready,
      'release' => $release,
    ], JSON_THROW_ON_ERROR))->setTimeout(35);
    $worker->start();
    try {
      $deadline = microtime(TRUE) + 20;
      while (!is_file($ready)) {
        if (!$worker->isRunning() || microtime(TRUE) > $deadline) {
          $this->fail('Independent writer did not boot.');
        }
        usleep(20000);
      }
      $observed_lock = FALSE;
      RollbackTestStorage::$compete = static function () use ($ready, $release, $database, &$observed_lock): void {
        touch($release);
        $pid = (int) file_get_contents($ready);
        $deadline = microtime(TRUE) + 10;
        do {
          if ($database->driver() === 'pgsql') {
            $database->query('SELECT pg_stat_clear_snapshot()');
            $info = $database->query("SELECT query FROM pg_stat_activity WHERE pid = :pid AND wait_event_type = 'Lock'", [':pid' => $pid])->fetchField();
          }
          else {
            $info = $database->query("SELECT INFO FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = :pid AND COMMAND = 'Query'", [':pid' => $pid])->fetchField();
          }
          $observed_lock = is_string($info) && str_contains($info, 'agency_migrate_authority_state') && str_contains($info, 'FOR UPDATE');
          if (!$observed_lock) {
            usleep(20000);
          }
        } while (!$observed_lock && microtime(TRUE) < $deadline);
        if (!$observed_lock) {
          throw new \RuntimeException('The competing writer did not wait.');
        }
      };
      $this->assertSame('completed', $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation)['status']);
      $this->assertTrue($observed_lock);
      $this->assertSame(0, $worker->wait());
      $this->assertSame(['status' => 'refused', 'reason' => 'agency_writer_refused'], json_decode($worker->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR));
      $this->assertSame(1, $this->countRows('agency_migrate_write_log'));
      $this->assertSame([['restore', $created['entity_id']]], RollbackTestStorage::$effects);
    }
    finally {
      RollbackTestStorage::$compete = NULL;
      touch($release);
      $worker->stop(1);
      @unlink($ready);
      @unlink($release);
    }
  }

  /**
   * The generic transition cannot bypass a nonempty rollback preflight.
   */
  public function testAuthorityTransitionRefusesDirectRollback(): void {
    $generation = $this->prepareDomain();
    $this->create($generation, 'Must replay first');
    $authority = $this->container->get('agency_migrate.authority');
    $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'legacy', self::REASON, TRUE), 'rollback_unqualified');
    $this->assertRefused(fn () => $authority->runQualifiedRollback($this->rollback(), self::DOMAIN, self::REASON, $generation, TRUE, new RollbackAttempt()), 'rollback_unqualified');
    $this->assertSame('agency', $authority->get(self::DOMAIN));
    $this->assertSame($generation, $authority->status()[self::DOMAIN]['version']);
  }

  /**
   * Copies an exact original baseline and advances through real authority.
   */
  private function prepareDomain(): int {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register(self::DOMAIN, 'Register synthetic records');
    $authority->transition(self::DOMAIN, 'shadow', 'Begin shadow');
    $authority->transition(self::DOMAIN, 'agency', 'Complete cutover');
    return $authority->status()[self::DOMAIN]['version'];
  }

  /**
   * Creates a post-cutover record through the durable journal writer.
   */
  private function create(int $generation, string $title): array {
    return $this->writer()->create(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, ['title' => $title]);
  }

  /**
   * Updates a post-cutover record through the durable journal writer.
   */
  private function update(int $generation, ?ContentEntityInterface $entity, string $payload): void {
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    $this->writer()->update(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, (int) $entity->id(), $entity->uuid(), ['payload' => $payload]);
  }

  /**
   * Deletes a post-cutover record through the durable journal writer.
   */
  private function delete(int $generation, ?ContentEntityInterface $entity): array {
    $this->assertInstanceOf(ContentEntityInterface::class, $entity);
    return $this->writer()->delete(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), self::TARGET, (int) $entity->id(), $entity->uuid());
  }

  /**
   * Returns the serving journal writer.
   */
  private function writer(): PostCutoverWriter {
    return $this->container->get('agency_migrate.post_cutover_writer');
  }

  /**
   * Counts the isolated test site's persisted rows.
   */
  private function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table, 'r')->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the serving rollback coordinator.
   */
  private function rollback(): RollbackService {
    return $this->container->get('agency_migrate.rollback');
  }

  /**
   * Returns the history snapshot writer.
   */
  private function history(): IdentityPreservingWriter {
    return $this->container->get('agency_migrate.identity_writer');
  }

}
