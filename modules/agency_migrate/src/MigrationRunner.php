<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\agency_migrate\Plugin\HistoryCopierInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Idempotent, resumable copy in bounded batches.
 *
 * Each batch commits its copies, lineage, resume progress and evidence intent
 * together. A failed batch leaves the last completed window intact. Host
 * adapters supply one copier per source type; the runner does not invent types.
 */
final class MigrationRunner {

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly LineageStore $lineage,
    private readonly MigrateOutbox $outbox,
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Copies one plugin's source type, or reports the plan when execute is FALSE.
   *
   * @return array<string, mixed>
   *   Run receipt with counts and status.
   */
  public function run(string $plugin_id, int $batch_size = 50, bool $execute = FALSE): array {
    $this->assertRunAccess();
    $batch_size = $this->boundedBatch($batch_size);
    $copier = $this->copier($plugin_id);
    $this->assertMarkings($copier);
    $plan = [
      'plugin_id' => $plugin_id,
      'source_type' => $copier->sourceEntityType(),
      'target_type' => $copier->targetEntityType(),
      'source_count' => $copier->countSource(),
      'batch_size' => $batch_size,
      'execute' => $execute,
    ];
    if (!$execute) {
      return $plan + ['status' => 'dry_run'];
    }
    $run_id = $this->uuid->generate();
    $this->insertRun($run_id, $plugin_id, $batch_size);
    return $this->process($run_id, $copier, $batch_size, 0);
  }

  /**
   * Continues an interrupted or failed run without duplicating copies.
   *
   * @return array<string, mixed>
   *   Run receipt with counts and status.
   */
  public function resume(string $run_id, bool $execute = FALSE): array {
    $this->assertRunAccess();
    $run = $this->loadRun($run_id);
    if ($run === NULL) {
      throw new MigrateRefusal('plugin_missing');
    }
    $copier = $this->copier($run['plugin_id']);
    $this->assertMarkings($copier);
    if (!$execute) {
      return [
        'run_id' => $run_id,
        'plugin_id' => $run['plugin_id'],
        'status' => 'dry_run',
        'cursor_source_id' => (int) $run['cursor_source_id'],
        'execute' => FALSE,
      ];
    }
    if ($run['status'] === 'completed') {
      return $this->receipt($run);
    }
    $this->database->update('agency_migrate_run')->fields([
      'status' => 'running',
      'error' => NULL,
      'updated' => $this->time->getCurrentTime(),
    ])->condition('run_id', $run_id)->execute();
    return $this->process($run_id, $copier, (int) $run['batch_size'], (int) $run['cursor_source_id']);
  }

  /**
   * Copies or skips one bounded window, emitting one lineage event per batch.
   *
   * @return array<string, mixed>
   *   Run receipt.
   */
  private function process(string $run_id, CopierInterface $copier, int $batch_size, int $cursor): array {
    try {
      while (TRUE) {
        $batch = $copier->loadSourceBatch($cursor, $batch_size);
        if ($batch === []) {
          $this->finish($run_id, 'completed', NULL);
          break;
        }
        $id_from = (int) $batch[0]->id();
        $id_to = (int) $batch[0]->id();
        $batch_copied = 0;
        $transaction = $this->database->startTransaction();
        try {
          foreach ($batch as $source) {
            $result = $this->copyOne($run_id, $copier, $source);
            $cursor = (int) $source->id();
            $id_to = $cursor;
            if ($result === 'copied') {
              $batch_copied++;
            }
            $this->touchRun($run_id, $cursor, $result === 'copied' ? 1 : 0, $result === 'skipped' ? 1 : 0);
          }
          $this->emitBatch($run_id, $copier, $id_from, $id_to, $batch_copied);
          TransactionBoundary::commit($transaction);
        }
        catch (\Throwable $exception) {
          try {
            $transaction?->rollBack();
          }
          finally {
            unset($transaction);
          }
          throw $exception;
        }
        unset($transaction);
      }
    }
    catch (MigrateRefusal $exception) {
      $this->finish($run_id, 'failed', $exception->reason);
      throw $exception;
    }
    catch (\Throwable) {
      $this->finish($run_id, 'failed', 'interrupted');
      throw new MigrateRefusal('interrupted');
    }
    $run = $this->loadRun($run_id);
    return $this->receipt($run ?? ['run_id' => $run_id, 'status' => 'failed']);
  }

  /**
   * Copies one source inside a transaction, or skips an existing faithful copy.
   */
  private function copyOne(string $run_id, CopierInterface $copier, ContentEntityInterface $source): string {
    $existing = $this->lineage->bySource($source->getEntityTypeId(), (int) $source->id());
    if ($existing !== NULL) {
      $this->assertFaithfulCopy($copier, $source, $existing);
      return 'skipped';
    }
    if ($source->getEntityType()->isRevisionable() && !$copier instanceof HistoryCopierInterface) {
      throw new MigrateRefusal('history_unverified');
    }
    if ($copier->targetOccupiedByDifferentUuid((int) $source->id(), $source->uuid())) {
      throw new MigrateRefusal('identity_conflict');
    }
    $transaction = $this->database->startTransaction();
    try {
      $manifest = $copier instanceof HistoryCopierInterface ? $copier->historyManifest($source) : NULL;
      $target = $copier->copyToTarget($source);
      if ($manifest !== NULL) {
        if ($manifest !== $copier->historyManifest($target)
          || $manifest !== $copier->historyManifest($source)) {
          throw new MigrateRefusal('history_drift');
        }
      }
      $this->lineage->record($run_id, $source, $target, $copier->contentDigest($source), $manifest);
      TransactionBoundary::commit($transaction);
      return 'copied';
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      throw $exception;
    }
  }

  /**
   * Existing lineage is a claim to verify, not permission to skip stale copies.
   */
  private function assertFaithfulCopy(CopierInterface $copier, ContentEntityInterface $source, array $row): void {
    $target = $copier->loadTarget((int) $source->id());
    if (!$target instanceof ContentEntityInterface
      || $target->uuid() !== $source->uuid()
      || $row['source_uuid'] !== $source->uuid()
      || $row['target_uuid'] !== $target->uuid()
      || $row['target_entity_type'] !== $copier->targetEntityType()
      || (int) $row['target_id'] !== (int) $target->id()
      || (int) $row['source_revision_id'] !== (int) ($source->getRevisionId() ?? $source->id())
      || (int) $row['target_revision_id'] !== (int) ($target->getRevisionId() ?? $target->id())) {
      throw new MigrateRefusal('history_drift');
    }
    if ($copier instanceof HistoryCopierInterface) {
      $manifest = $this->lineage->manifest($row);
      if ($manifest === NULL) {
        throw new MigrateRefusal('history_unverified');
      }
      if ($manifest !== $copier->historyManifest($source)
        || $manifest !== $copier->historyManifest($target)
        || $row['content_digest'] !== $copier->contentDigest($source)) {
        throw new MigrateRefusal('history_drift');
      }
      return;
    }
    if ($source->getEntityType()->isRevisionable()) {
      throw new MigrateRefusal('history_unverified');
    }
    if ($row['content_digest'] !== $copier->contentDigest($source)
      || $row['content_digest'] !== $copier->contentDigest($target)) {
      throw new MigrateRefusal('history_drift');
    }
  }

  /**
   * Records the batch intent inside the same transaction as its progress.
   */
  private function emitBatch(string $run_id, CopierInterface $copier, int $id_from, int $id_to, int $count): void {
    $fingerprint = hash('sha256', implode(':', [
      'lineage',
      $run_id,
      $copier->sourceEntityType(),
      (string) $id_from,
      (string) $id_to,
    ]));
    $this->outbox->record('lineage_batch', [
      'run_id' => $run_id,
      'source_type' => $copier->sourceEntityType(),
      'target_type' => $copier->targetEntityType(),
      'id_from' => $id_from,
      'id_to' => $id_to,
      'count' => $count,
    ], $fingerprint);
  }

  /**
   * Fails the run before any write when a source property has no marking.
   */
  private function assertMarkings(CopierInterface $copier): void {
    $unmapped = $copier->unmappedProperties();
    if ($unmapped !== []) {
      throw new MigrateRefusal('unmapped_marking');
    }
  }

  /**
   * Instantiates a copier or refuses an unknown plugin id.
   */
  private function copier(string $plugin_id): CopierInterface {
    if (!$this->copiers->hasDefinition($plugin_id)) {
      throw new MigrateRefusal('plugin_missing');
    }
    return $this->copiers->createInstance($plugin_id);
  }

  /**
   * Clamps batch size to a bounded positive window.
   */
  private function boundedBatch(int $batch_size): int {
    return max(1, min(500, $batch_size));
  }

  /**
   * Requires the migration run permission.
   */
  private function assertRunAccess(): void {
    if (!$this->currentUser->hasPermission('run agency migrate')) {
      throw new MigrateRefusal('forbidden');
    }
  }

  /**
   * Inserts a running receipt before the first batch.
   */
  private function insertRun(string $run_id, string $plugin_id, int $batch_size): void {
    $now = $this->time->getCurrentTime();
    $this->database->insert('agency_migrate_run')->fields([
      'run_id' => $run_id,
      'plugin_id' => $plugin_id,
      'status' => 'running',
      'cursor_source_id' => 0,
      'batch_size' => $batch_size,
      'copied' => 0,
      'skipped' => 0,
      'started' => $now,
      'updated' => $now,
      'finished' => NULL,
      'error' => NULL,
    ])->execute();
  }

  /**
   * Advances the resume cursor inside the current batch transaction.
   */
  private function touchRun(string $run_id, int $cursor, int $copied_delta, int $skipped_delta): void {
    $this->database->update('agency_migrate_run')->fields([
      'cursor_source_id' => $cursor,
      'updated' => $this->time->getCurrentTime(),
    ])->expression('copied', 'copied + :copied', [':copied' => $copied_delta])
      ->expression('skipped', 'skipped + :skipped', [':skipped' => $skipped_delta])
      ->condition('run_id', $run_id)
      ->execute();
  }

  /**
   * Marks a run completed or failed.
   */
  private function finish(string $run_id, string $status, ?string $error): void {
    $this->database->update('agency_migrate_run')->fields([
      'status' => $status,
      'error' => $error,
      'updated' => $this->time->getCurrentTime(),
      'finished' => $this->time->getCurrentTime(),
    ])->condition('run_id', $run_id)->execute();
  }

  /**
   * Loads one run receipt.
   *
   * @return array<string, mixed>|null
   *   Storage row.
   */
  public function loadRun(string $run_id): ?array {
    $row = $this->database->select('agency_migrate_run', 'r')
      ->fields('r')
      ->condition('run_id', $run_id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Public run receipt without storage internals.
   *
   * @param array<string, mixed> $run
   *   Storage row.
   *
   * @return array<string, mixed>
   *   Operator-facing receipt.
   */
  public function receipt(array $run): array {
    return [
      'run_id' => $run['run_id'],
      'plugin_id' => $run['plugin_id'] ?? NULL,
      'status' => $run['status'],
      'cursor_source_id' => isset($run['cursor_source_id']) ? (int) $run['cursor_source_id'] : 0,
      'copied' => isset($run['copied']) ? (int) $run['copied'] : 0,
      'skipped' => isset($run['skipped']) ? (int) $run['skipped'] : 0,
      'error' => $run['error'] ?? NULL,
    ];
  }

}
