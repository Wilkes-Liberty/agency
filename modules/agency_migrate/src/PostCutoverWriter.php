<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Owns local post-cutover mutations and immutable historical receipts.
 *
 * Calling domain services must authorize their business operation. This service
 * provides no route, permission grant, remote operation or rollback replay.
 */
final class PostCutoverWriter {

  public function __construct(
    private readonly Connection $database,
    private readonly AuthorityRegister $authority,
    private readonly EntityTypeManagerInterface $entities,
    private readonly CopierManager $copiers,
    private readonly IdentityPreservingWriter $history,
    private readonly MigrateOutbox $outbox,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates an identity only on the first committed attempt.
   */
  public function create(string $domain, int $expectedGeneration, string $operationId, string $entityType, array $values): array {
    return $this->perform('create', $domain, $expectedGeneration, $operationId, $entityType, NULL, NULL, $values);
  }

  /**
   * Appends a new revision without changing any old revision payload.
   */
  public function update(string $domain, int $expectedGeneration, string $operationId, string $entityType, int $id, string $uuid, array $changes): array {
    return $this->perform('update', $domain, $expectedGeneration, $operationId, $entityType, $id, $uuid, $changes);
  }

  /**
   * Deletes the exact identity and retains its complete typed preimage.
   */
  public function delete(string $domain, int $expectedGeneration, string $operationId, string $entityType, int $id, string $uuid): array {
    return $this->perform('delete', $domain, $expectedGeneration, $operationId, $entityType, $id, $uuid, []);
  }

  /**
   * Owns root durability, current authority and cache cleanup on every exit.
   */
  private function perform(string $kind, string $domain, int $generation, string $operationId, string $entityType, ?int $id, ?string $uuid, array $values): array {
    if ($this->database->inTransaction()) {
      throw new MigrateRefusal('writer_transaction_owned');
    }
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $domain)
      || $generation < 1 || strlen($operationId) !== 36 || !Uuid::isValid($operationId) || strtolower($operationId) !== $operationId
      || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $entityType)
      || ($kind !== 'create' && ($id === NULL || $id < 1 || $uuid === NULL || !Uuid::isValid($uuid)))) {
      throw new MigrateRefusal('operation_invalid');
    }
    try {
      $requestHash = hash('sha256', json_encode($this->canonical([
        'version' => 1,
        'kind' => $kind,
        'domain' => $domain,
        'generation' => $generation,
        'operation_id' => $operationId,
        'entity_type' => $entityType,
        'id' => $id,
        'uuid' => $uuid,
        'values' => $values,
      ]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
      // This read is outside the new transaction. In MySQL it must not create
      // a repeatable-read snapshot before waiting on the authority row.
      $existing = $this->existing($domain, $generation, $operationId, $requestHash, FALSE);
    }
    catch (MigrateRefusal $exception) {
      throw $exception;
    }
    catch (\Throwable) {
      throw new MigrateRefusal('operation_storage_unavailable');
    }
    if ($existing !== NULL) {
      return $existing;
    }
    $storage = NULL;
    $transaction = NULL;
    $committing = FALSE;
    try {
      $transaction = $this->database->startTransaction();
      $authority = $this->authority->lockOperationAuthority($this->database, $domain);
      // An intervening transition may have won the lock after the original
      // operation committed. A valid historical receipt survives that change.
      $existing = $this->existing($domain, $generation, $operationId, $requestHash, TRUE);
      if ($existing !== NULL) {
        $committing = TRUE;
        $this->commit($transaction);
        return $existing;
      }
      if ($authority === NULL || $authority['state'] !== 'agency' || $authority['version'] !== $generation) {
        throw new MigrateRefusal('agency_writer_refused');
      }
      $this->copiers->copierForTarget($entityType, $domain);
      $storage = $this->entities->getStorage($entityType);
      if (!$storage instanceof ContentEntityStorageInterface
        || !$storage instanceof OperationStorageParticipantInterface
        || $storage->operationConnection() !== $this->database) {
        throw new MigrateRefusal('writer_connection_mismatch');
      }
      if (!$this->entities->getDefinition($entityType)->isRevisionable()) {
        throw new MigrateRefusal('operation_history_unsupported');
      }
      $storage->resetCache();
      $before = NULL;
      $after = NULL;
      if ($kind === 'create') {
        $entity = $storage->create($values);
      }
      else {
        $entity = $storage->load($id);
        if (!$entity instanceof ContentEntityInterface || $entity->uuid() !== $uuid) {
          throw new MigrateRefusal('operation_identity_mismatch');
        }
        if ($kind === 'update' && !$entity->getEntityType()->isRevisionable()) {
          throw new MigrateRefusal('operation_history_unsupported');
        }
        $before = $this->history->captureHistory($entity);
      }
      if (!$entity instanceof ContentEntityInterface) {
        throw new MigrateRefusal('operation_history_unsupported');
      }
      if ($kind !== 'delete') {
        $keys = $entity->getEntityType()->getKeys();
        $identity = array_filter([
          $keys['id'] ?? NULL,
          $keys['uuid'] ?? NULL,
          $keys['revision'] ?? NULL,
          'revision_default',
          'default_langcode',
          'revision_translation_affected',
        ]);
        foreach ($values as $name => $value) {
          if (!is_string($name) || !$entity->hasField($name)
            || $entity->getFieldDefinition($name)->isComputed()
            || in_array($name, $identity, TRUE)) {
            throw new MigrateRefusal('operation_invalid');
          }
          $entity->set($name, $value);
        }
        if ($kind === 'update') {
          $entity->setNewRevision(TRUE);
          $entity->isDefaultRevision(TRUE);
        }
        $expected = $this->history->canonicalize($entity);
        $entity->save();
        $id = (int) $entity->id();
        $uuid = $entity->uuid();
        if ($id < 1 || !Uuid::isValid($uuid)) {
          throw new MigrateRefusal('operation_identity_mismatch');
        }
        $storage->resetCache();
        $fresh = $storage->load($id);
        if (!$fresh instanceof ContentEntityInterface || $fresh->uuid() !== $uuid) {
          throw new MigrateRefusal('operation_identity_mismatch');
        }
        $actual = $this->history->canonicalize($fresh);
        foreach (array_keys($values) as $field) {
          if (($expected[$field] ?? NULL) !== ($actual[$field] ?? NULL)) {
            throw new MigrateRefusal('operation_content_mismatch');
          }
        }
        $after = $this->history->captureHistory($fresh);
        if ($before !== NULL) {
          $this->assertAppendOnly($before, $after);
        }
      }
      else {
        $entity->delete();
        $storage->resetCache();
        if ($storage->load($id) !== NULL) {
          throw new MigrateRefusal('operation_content_mismatch');
        }
        if ($entity->getEntityType()->isRevisionable()) {
          foreach ($before['revisions'] as $revision) {
            if ($storage->loadRevision($revision['revision_id']) !== NULL) {
              throw new MigrateRefusal('operation_history_conflict');
            }
          }
        }
      }
      $envelope = [
        'version' => 1,
        'kind' => $kind,
        'domain' => $domain,
        'generation' => $generation,
        'operation_id' => $operationId,
        'request_hash' => $requestHash,
        'entity_type' => $entityType,
        'id' => $id,
        'uuid' => $uuid,
        'before' => $before,
        'after' => $after,
      ];
      $receipt = $this->append($envelope);
      $committing = TRUE;
      $this->commit($transaction);
      return $receipt;
    }
    catch (\Throwable $exception) {
      $rollbackFailed = FALSE;
      try {
        if ($this->database->inTransaction()) {
          $transaction?->rollBack();
        }
      }
      catch (\Throwable) {
        $rollbackFailed = TRUE;
      }
      finally {
        unset($transaction);
      }
      if ($committing || $rollbackFailed) {
        throw new MigrateRefusal('operation_commit_uncertain');
      }
      throw $exception instanceof MigrateRefusal ? $exception : new MigrateRefusal('operation_failed');
    }
    finally {
      $storage?->resetCache();
    }
  }

  /**
   * Finishes the root transaction and any post-transaction callbacks.
   *
   * @param-out null $transaction
   */
  private function commit(?Transaction &$transaction): void {
    // @phpstan-ignore paramOut.type (The next assignment clears the reference.)
    TransactionBoundary::commit($transaction);
    // Keep callbacks from transaction destruction inside the caller's catch.
    $transaction = NULL;
    if ($this->database->inTransaction()) {
      throw new \LogicException('Root operation commit did not complete.');
    }
  }

  /**
   * Requires the exact original request, even after authority has moved away.
   */
  private function existing(string $domain, int $generation, string $operationId, string $requestHash, bool $lock): ?array {
    $query = $this->database->select('agency_migrate_write_log', 'w')->fields('w')
      ->condition('domain', $domain)->condition('operation_id', $operationId);
    if ($lock) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    if ((int) ($row['authority_generation'] ?? 0) !== $generation
      || !hash_equals((string) ($row['request_hash'] ?? ''), $requestHash)) {
      throw new MigrateRefusal('operation_conflict');
    }
    $envelope = PostCutoverOperationEnvelope::decode($row, $this->history);
    return PostCutoverOperationEnvelope::receipt($row, $envelope);
  }

  /**
   * Writes only self-captured envelopes, in the same mutation transaction.
   */
  private function append(array $envelope): array {
    $row = [
      'domain' => $envelope['domain'],
      'entity_type' => $envelope['entity_type'],
      'entity_id' => $envelope['id'],
      'entity_uuid' => $envelope['uuid'],
      'recorded' => $this->time->getCurrentTime(),
      'replayed' => NULL,
      'envelope_version' => 1,
      'authority_generation' => $envelope['generation'],
      'operation_id' => $envelope['operation_id'],
      'request_hash' => $envelope['request_hash'],
      'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
    ];
    PostCutoverOperationEnvelope::decode($row, $this->history);
    $row['wid'] = (int) $this->database->insert('agency_migrate_write_log')->fields($row)->execute();
    $metadata = [
      'domain' => $envelope['domain'],
      'generation' => $envelope['generation'],
      'operation_id' => $envelope['operation_id'],
      'kind' => $envelope['kind'],
    ];
    $fingerprint = hash('sha256', 'operation:' . $envelope['domain'] . ':' . $envelope['operation_id']);
    $intentId = $this->outbox->record('post_cutover_write', $metadata, $fingerprint);
    $journalQuery = $this->database->select('agency_migrate_write_log', 'w')->fields('w')
      ->condition('wid', $row['wid']);
    $journalQuery->forUpdate();
    $journal = $journalQuery->execute()->fetchAssoc();
    if (!$journal || $row['wid'] < 1 || $journal['envelope'] !== $row['envelope']
      || (int) $journal['recorded'] !== $row['recorded'] || $journal['replayed'] !== NULL) {
      throw new MigrateRefusal('operation_journal_conflict');
    }
    $validated = PostCutoverOperationEnvelope::decode($journal, $this->history);
    $intentQuery = $this->database->select('agency_migrate_outbox', 'o')->fields('o')
      ->condition('id', $intentId);
    $intentQuery->forUpdate();
    $intent = $intentQuery->execute()->fetchAssoc();
    if (!$intent || $intent['id'] !== $intentId || $intent['operation'] !== 'post_cutover_write'
      || $intent['fingerprint'] !== $fingerprint || $intent['delivered'] !== NULL
      || (int) $intent['attempts'] !== 0 || $intent['last_attempt_at'] !== NULL
      || $intent['last_error_at'] !== NULL) {
      throw new MigrateRefusal('operation_evidence_conflict');
    }
    $expectedMetadata = $metadata + ['event_id' => $intentId, 'effective_at' => (int) $intent['recorded']];
    $storedMetadata = json_decode($intent['metadata'], TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($storedMetadata) || count($storedMetadata) !== count($expectedMetadata)) {
      throw new MigrateRefusal('operation_evidence_conflict');
    }
    foreach ($expectedMetadata as $key => $value) {
      if (($storedMetadata[$key] ?? NULL) !== $value) {
        throw new MigrateRefusal('operation_evidence_conflict');
      }
    }
    return PostCutoverOperationEnvelope::receipt($journal, $validated);
  }

  /**
   * Verifies old stored payloads and inventory after a forced new revision.
   */
  private function assertAppendOnly(array $before, array $after): void {
    if ($before['layout'] !== $after['layout']
      || count($after['revisions']) !== count($before['revisions']) + 1
      || $after['default_revision_id'] === $before['default_revision_id']) {
      throw new MigrateRefusal('operation_history_conflict');
    }
    $revisions = [];
    foreach ($after['revisions'] as $revision) {
      unset($revision['default']);
      $revisions[$revision['revision_id']] = $revision;
    }
    foreach ($before['revisions'] as $revision) {
      $id = $revision['revision_id'];
      unset($revision['default']);
      if (($revisions[$id] ?? NULL) !== $revision) {
        throw new MigrateRefusal('operation_history_conflict');
      }
    }
  }

  /**
   * Sorts maps recursively while preserving typed scalars and list order.
   */
  private function canonical(mixed $value, int $depth = 0): mixed {
    if ($depth > 32 || is_object($value) || is_resource($value)
      || (is_float($value) && !is_finite($value))
      || (is_string($value) && preg_match('//u', $value) !== 1)) {
      throw new MigrateRefusal('operation_invalid');
    }
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value, SORT_STRING);
    }
    foreach ($value as &$item) {
      $item = $this->canonical($item, $depth + 1);
    }
    return $value;
  }

}
