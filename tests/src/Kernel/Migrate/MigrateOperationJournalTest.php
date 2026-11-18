<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\PostCutoverOperationEnvelope;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\agency\Fixtures\MigrateOperationSqlFault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

/**
 * Real local mutations, journal receipts and failure boundaries.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateOperationJournalTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['agency_migrate_operation_test'];

  private const TYPE = 'agency_migrate_agency_item';

  /**
   * A create retry cannot allocate a second identity or evidence intent.
   */
  public function testOperationCreateRetryRetainsAllocatedIdentity(): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $values = ['title' => 'First durable record', 'payload' => 'Synthetic payload'];
    $receipt = $this->completed(fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, $values));
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame($receipt, $this->writer()->create('synthetic', 3, $operation, self::TYPE, $values));
    $this->assertSame($receipt, $this->writer()->create('synthetic', 3, $operation, self::TYPE, array_reverse($values, TRUE)));
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame(1, $this->rows('agency_migrate_outbox'));
    $metadata = $this->container->get('database')->select('agency_migrate_outbox', 'o')->fields('o', ['metadata'])->execute()->fetchField();
    $keys = array_keys(json_decode($metadata, TRUE, 16, JSON_THROW_ON_ERROR));
    sort($keys);
    $this->assertSame(['domain', 'effective_at', 'event_id', 'generation', 'kind', 'operation_id'], $keys);
    $this->assertStringNotContainsString('Synthetic payload', $metadata);
    $this->assertStringNotContainsString($receipt['entity_uuid'], $metadata);
    $this->authority('legacy', 4);
    $this->assertSame($receipt, $this->writer()->create('synthetic', 3, $operation, self::TYPE, $values));
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 4, $operation, self::TYPE, $values), 'operation_conflict');
  }

  /**
   * Refusal checks happen before any business mutation.
   *
   * @dataProvider deniedAuthority
   */
  #[DataProvider('deniedAuthority')]
  public function testOperationAuthorityRefusesWithoutEffects(?string $state, int $generation, int $expected): void {
    if ($state !== NULL) {
      $this->authority($state, $generation);
    }
    $this->assertRefused(fn () => $this->writer()->create('synthetic', $expected, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'agency_writer_refused');
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Independent stale and unavailable authority states.
   */
  public static function deniedAuthority(): array {
    return [
      'unregistered' => [NULL, 0, 3],
      'legacy' => ['legacy', 1, 1],
      'shadow' => ['shadow', 2, 2],
      'removed' => ['removed', 4, 4],
      'stale generation' => ['agency', 5, 3],
    ];
  }

  /**
   * A caller-owned transaction cannot receive a premature durable receipt.
   */
  public function testOperationOuterTransactionRefuses(): void {
    $this->authority('agency', 3);
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'writer_transaction_owned');
      $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
      $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    }
    finally {
      $transaction->rollBack();
      unset($transaction);
    }
  }

  /**
   * Update history is append-only and delete retains the complete preimage.
   */
  public function testOperationUpdateAndDeleteKeepTypedPreimages(): void {
    $this->authority('agency', 3);
    $values = ['title' => 'Original', 'payload' => 'Before'];
    $create = $this->completed(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, $values));
    $updateId = $this->container->get('uuid')->generate();
    $update = $this->writer()->update('synthetic', 3, $updateId, self::TYPE, $create['entity_id'], $create['entity_uuid'], ['payload' => 'After']);
    $envelope = $this->envelope($updateId);
    $this->assertCount(1, $envelope['before']['revisions']);
    $this->assertCount(2, $envelope['after']['revisions']);
    $this->assertSame($update, $this->writer()->update('synthetic', 3, $updateId, self::TYPE, $create['entity_id'], $create['entity_uuid'], ['payload' => 'After']));
    $deleteId = $this->container->get('uuid')->generate();
    $delete = $this->writer()->delete('synthetic', 3, $deleteId, self::TYPE, $create['entity_id'], $create['entity_uuid']);
    $deleted = $this->envelope($deleteId);
    $this->assertSame($envelope['after'], $deleted['before']);
    $this->assertNull($deleted['after']);
    $this->assertNull($this->target($create['entity_id']));
    $this->authority('agency', 7);
    $this->assertSame($delete, $this->writer()->delete('synthetic', 3, $deleteId, self::TYPE, $create['entity_id'], $create['entity_uuid']));
    $this->assertSame(3, $this->rows('agency_migrate_write_log'));
    $this->assertSame(3, $this->rows('agency_migrate_outbox'));
  }

  /**
   * A domain-global operation id never authorizes another request.
   */
  public function testOperationChangedRequestsRefuseBeforeMutation(): void {
    $this->authority('agency', 3);
    $id = $this->container->get('uuid')->generate();
    $created = $this->completed(fn () => $this->writer()->create('synthetic', 3, $id, self::TYPE, ['title' => 'Exact']));
    foreach ([
      fn () => $this->writer()->create('synthetic', 3, $id, self::TYPE, ['title' => 'Changed']),
      fn () => $this->writer()->create('synthetic', 4, $id, self::TYPE, ['title' => 'Exact']),
      fn () => $this->writer()->delete('synthetic', 3, $id, self::TYPE, $created['entity_id'], $created['entity_uuid']),
      fn () => $this->writer()->update('synthetic', 3, $id, self::TYPE, $created['entity_id'] + 1, $created['entity_uuid'], []),
    ] as $attempt) {
      $this->assertRefused($attempt, 'operation_conflict');
    }
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame('Exact', $this->target($created['entity_id'])->get('title')->value);
  }

  /**
   * Failure after real SQL save restores entities, history, journal and cache.
   *
   * @dataProvider localOperationFailures
   */
  #[DataProvider('localOperationFailures')]
  public function testOperationLocalFailureIsAtomic(string $fault, string $reason): void {
    $this->authority('agency', 3);
    $values = ['title' => 'Original', 'payload' => 'Before'];
    $created = $this->completed(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, $values));
    $entity = $this->target($created['entity_id']);
    $history = $this->container->get('agency_migrate.identity_writer');
    $before = $history->captureHistory($entity);
    $this->container->get('state')->set('agency_operation.old_revision', (int) $entity->getRevisionId());
    $this->container->get('state')->set('agency_operation.fault', $fault);
    $database = $this->container->get('database');
    $table = match ($fault) {
      'journal', 'journal_alter' => 'agency_migrate_write_log',
      'outbox', 'outbox_alter', 'outbox_extra' => 'agency_migrate_outbox',
      default => NULL,
    };
    $sqlFault = $table !== NULL ? new MigrateOperationSqlFault($database, $table, $fault) : NULL;
    $sqlFault?->install();
    try {
      $this->assertRefused(fn () => $this->writer()->update('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, $created['entity_id'], $created['entity_uuid'], ['payload' => 'Uncommitted']), $reason);
    }
    finally {
      $sqlFault?->remove();
      $this->container->get('state')->delete('agency_operation.fault');
    }
    $this->assertFalse($database->inTransaction());
    $reloaded = $this->container->get('entity_type.manager')->getStorage(self::TYPE)->load($created['entity_id']);
    $this->assertInstanceOf(ContentEntityInterface::class, $reloaded);
    $this->assertSame('Before', $reloaded->get('payload')->value, 'Failure must clear mutated entity caches without caller reset.');
    $this->assertSame($before, $history->captureHistory($reloaded));
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame(1, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Independent database and history mutation boundaries.
   */
  public static function localOperationFailures(): array {
    return [
      'save failure' => ['save', 'operation_failed'],
      'noop save' => ['save_noop', 'operation_content_mismatch'],
      'old history deletion' => ['history_delete', 'operation_history_conflict'],
      'old history overwrite' => ['history_overwrite', 'operation_history_conflict'],
      'journal insert failure' => ['journal', 'operation_failed'],
      'required outbox unavailable' => ['outbox', 'operation_failed'],
      'altered journal insert' => ['journal_alter', 'operation_journal_conflict'],
      'altered evidence insert' => ['outbox_alter', 'operation_evidence_conflict'],
      'extra evidence metadata' => ['outbox_extra', 'operation_evidence_conflict'],
    ];
  }

  /**
   * A genuine committed operation with a lost acknowledgement is recoverable.
   */
  public function testOperationLostCommitAcknowledgementNeverReruns(): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $this->container->get('state')->set('agency_operation.fault', 'commit_ack');
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Committed once']), 'operation_commit_uncertain');
    $database = $this->container->get('database');
    $this->assertFalse($database->inTransaction());
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame(1, $this->rows('agency_migrate_outbox'));
    $this->container->get('state')->delete('agency_operation.fault');
    $this->authority('legacy', 4);
    $receipt = $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Committed once']);
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame('Committed once', $this->target($receipt['entity_id'])->get('title')->value);
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 4, $operation, self::TYPE, ['title' => 'Committed once']), 'operation_conflict');
  }

  /**
   * A failed final cache reset reports uncertainty and preserves the receipt.
   */
  public function testOperationCacheCleanupAfterCommitKeepsReceipt(): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $this->container->get('state')->set('agency_operation.fault', 'cleanup_after_commit');
    $attempt = fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Committed despite cache failure']);
    $this->assertCleanupRefused($attempt, 'operation_commit_uncertain');
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame(1, $this->rows('agency_migrate_outbox'));
    $receipt = $attempt();
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame('Committed despite cache failure', $this->target($receipt['entity_id'])->get('title')->value);
  }

  /**
   * Cache cleanup cannot expose a backend failure during root rollback.
   *
   * @dataProvider rollbackCleanupFailures
   */
  #[DataProvider('rollbackCleanupFailures')]
  public function testOperationCacheCleanupAfterRollbackKeepsRefusal(string $fault, string $reason): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $this->container->get('state')->set('agency_operation.fault', $fault);
    $attempt = fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Rolled back despite cache failure']);
    $this->assertCleanupRefused($attempt, $reason);
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Cache failures with and without a failed rollback callback.
   */
  public static function rollbackCleanupFailures(): array {
    return [
      'rollback acknowledged' => ['cleanup_after_rollback', 'operation_history_conflict'],
      'rollback callback failed' => ['cleanup_after_rollback_callback', 'operation_commit_uncertain'],
    ];
  }

  /**
   * Requires both safe refusal reasons without any backend exception chain.
   */
  private function assertCleanupRefused(callable $attempt, string $reason): void {
    try {
      $attempt();
      $this->fail('Expected a migrate refusal.');
    }
    catch (MigrateRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
      $cleanup = $exception->getPrevious();
      $this->assertInstanceOf(MigrateRefusal::class, $cleanup);
      $this->assertSame('operation_cache_cleanup_failed', $cleanup->reason);
      $this->assertNull($cleanup->getPrevious());
    }
  }

  /**
   * SQL interface membership cannot qualify a different actual connection.
   */
  public function testOperationWrongStorageConnectionRefuses(): void {
    $this->authority('agency', 3);
    $database = $this->container->get('database');
    Database::addConnectionInfo('operation_foreign', 'default', $database->getConnectionOptions());
    $this->container->get('state')->set('agency_operation.foreign_connection', TRUE);
    $manager = $this->container->get('entity_type.manager');
    $manager->clearCachedDefinitions();
    try {
      $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'writer_connection_mismatch');
      $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
      $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    }
    finally {
      Database::removeConnection('operation_foreign');
    }
  }

  /**
   * Standard SQL without an explicit participant contract is unsupported.
   */
  public function testOperationUnqualifiedStorageRefuses(): void {
    $this->authority('agency', 3);
    $this->container->get('state')->set('agency_operation.unsupported_storage', TRUE);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'writer_connection_mismatch');
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
  }

  /**
   * Missing operational state never authorizes an Agency write.
   */
  public function testOperationMissingAuthorityStorageRefuses(): void {
    $this->container->get('database')->schema()->dropTable('agency_migrate_authority_state');
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'authority_storage_unavailable');
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Domain declaration is required even for otherwise qualified SQL storage.
   */
  public function testOperationMissingDomainMappingRefuses(): void {
    $this->authority('agency', 3);
    $this->setSetting('agency_migrate_copier_domains', []);
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, ['title' => 'Refused']), 'rollback_unqualified');
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
  }

  /**
   * Missing or unsafe journal policy refuses before a save can run.
   *
   * @dataProvider unqualifiedJournalPolicies
   */
  #[DataProvider('unqualifiedJournalPolicies')]
  public function testOperationJournalPolicyRefusesBeforeSave(string $case): void {
    $this->authority('agency', 3);
    $state = $this->container->get('state');
    $state->set('agency_operation.policy_case', $case);
    $state->set('agency_operation.fault', 'save');
    try {
      $this->writer()->create('synthetic', 3, $this->container->get('uuid')->generate(), self::TYPE, [
        'title' => 'Refused',
        'access_class' => 'Synthetic private data',
      ]);
      $this->fail('Expected journal qualification to refuse before saving.');
    }
    catch (MigrateRefusal $exception) {
      $this->assertSame('operation_unqualified', $exception->reason);
      $this->assertSame('operation_unqualified', $exception->getMessage());
      $this->assertNull($exception->getPrevious());
    }
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Independent reasons why an exact target may lack journal qualification.
   */
  public static function unqualifiedJournalPolicies(): array {
    return [
      'missing qualification' => ['missing'],
      'missing property' => ['unmarked'],
      'unknown marking' => ['unknown'],
      'secret property' => ['secret'],
      'broader access' => ['access'],
      'unqualified recovery' => ['recovery'],
      'incompatible retention' => ['retention'],
      'policy failure' => ['throws'],
    ];
  }

  /**
   * Unrelated writes cannot journal an unmarked confidential prior revision.
   */
  public function testOperationJournalQualificationCoversHistoricalTranslation(): void {
    $this->enableModules(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->authority('agency', 3);
    $storage = $this->container->get('entity_type.manager')->getStorage(self::TYPE);
    $target = $storage->create(['title' => 'Original', 'access_class' => 'Synthetic private historical value']);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $target->addTranslation('fr', ['payload' => 'Synthetic historical translation']);
    $target->save();
    $target->removeTranslation('fr');
    $target->set('access_class', NULL);
    $target->setNewRevision(TRUE);
    $target->save();
    $id = (int) $target->id();
    $uuid = $target->uuid();
    $history = $this->container->get('agency_migrate.identity_writer');
    $before = $history->captureHistory($target);
    $this->assertCount(2, $before['revisions']);
    $this->assertSame('Synthetic historical translation', $before['revisions'][0]['fields']['payload']['fr'][0]['value']);
    $this->assertSame('Synthetic private historical value', $before['revisions'][0]['fields']['access_class']['fr'][0]['value']);
    $this->assertSame([], $before['revisions'][1]['fields']['access_class']['en']);
    $this->assertArrayNotHasKey('fr', $before['revisions'][1]['fields']['payload']);

    $this->container->get('state')->set('agency_operation.policy_case', 'unmarked_historical');
    $operation = $this->container->get('uuid');
    $this->assertRefused(fn () => $this->writer()->update('synthetic', 3, $operation->generate(), self::TYPE, $id, $uuid, ['title' => 'Unrelated']), 'operation_unqualified');
    $this->assertRefused(fn () => $this->writer()->delete('synthetic', 3, $operation->generate(), self::TYPE, $id, $uuid), 'operation_unqualified');
    $this->assertSame($before, $history->captureHistory($this->target($id)));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
  }

  /**
   * A save hook that moves the record out of its qualified bundle rolls back.
   */
  public function testOperationJournalQualificationRechecksSavedBundle(): void {
    $this->authority('agency', 3);
    $entities = $this->container->get('entity_type.manager');
    $this->container->get('entity.definition_update_manager')->uninstallEntityType($entities->getDefinition(self::TYPE));
    $state = $this->container->get('state');
    $state->set('agency_operation.bundled_target', TRUE);
    $entities->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $this->installEntitySchema(self::TYPE);
    $state->set('agency_operation.change_bundle', TRUE);

    $operation = $this->container->get('uuid')->generate();
    $attempt = fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Bundle changed by a hook']);
    $this->assertRefused($attempt, 'operation_unqualified');
    $this->assertSame(0, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(0, $this->rows('agency_migrate_write_log'));
    $this->assertSame(0, $this->rows('agency_migrate_outbox'));
    $state->delete('agency_operation.change_bundle');
    $receipt = $this->completed($attempt);
    $this->assertSame('journal_fixture', $this->target($receipt['entity_id'])->bundle());
  }

  /**
   * A delete hook cannot tighten policy and leave a disallowed preimage.
   *
   * @dataProvider deletePolicyChanges
   */
  #[DataProvider('deletePolicyChanges')]
  public function testOperationJournalQualificationRechecksDeletedHistory(string $case): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid');
    $createId = $operation->generate();
    $values = ['title' => 'Original', 'access_class' => 'Synthetic confidential preimage'];
    $create = fn () => $this->writer()->create('synthetic', 3, $createId, self::TYPE, $values);
    $receipt = $this->completed($create);
    $id = $receipt['entity_id'];
    $uuid = $receipt['entity_uuid'];
    $this->writer()->update('synthetic', 3, $operation->generate(), self::TYPE, $id, $uuid, ['title' => 'Updated']);
    $history = $this->container->get('agency_migrate.identity_writer');
    $before = $history->captureHistory($this->target($id));
    $this->assertCount(2, $before['revisions']);

    $state = $this->container->get('state');
    $state->set('agency_operation.delete_policy_case', $case);
    $this->assertRefused(fn () => $this->writer()->delete('synthetic', 3, $operation->generate(), self::TYPE, $id, $uuid), 'operation_unqualified');
    $this->assertSame($before, $history->captureHistory($this->target($id)));
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(2, $this->rows('agency_migrate_write_log'));
    $this->assertSame(2, $this->rows('agency_migrate_outbox'));
    $state->set('agency_operation.policy_case', 'throws');
    $this->assertSame($receipt, $create());
  }

  /**
   * An unavailable or different protection contract invalidates the delete.
   */
  public static function deletePolicyChanges(): array {
    return [
      'policy revoked' => ['missing'],
      'policy changed' => ['changed_contract'],
    ];
  }

  /**
   * Qualified retries stay historical and unqualified typed rows stay closed.
   */
  public function testOperationJournalQualificationPreservesScalarRetry(): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $attempt = fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, [
      'title' => 'Committed',
      'access_class' => 'Synthetic private data',
    ]);
    $receipt = $this->completed($attempt);
    $envelope = $this->envelope($operation);
    $this->container->get('state')->set('agency_operation.policy_case', 'throws');
    $this->assertSame($receipt, $attempt());
    $this->assertSame(2, $envelope['version']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $envelope['qualification']);

    $envelope['version'] = 1;
    unset($envelope['qualification']);
    $this->container->get('database')->update('agency_migrate_write_log')->fields([
      'envelope_version' => 1,
      'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
    ])->condition('operation_id', $operation)->execute();
    $this->assertRefused($attempt, 'operation_legacy_unqualified');
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
    $this->assertSame(1, $this->rows('agency_migrate_write_log'));
    $this->assertSame(1, $this->rows('agency_migrate_outbox'));
  }

  /**
   * Corrupt envelope identity cannot be trusted as a historical receipt.
   */
  public function testOperationEnvelopeIndexMismatchRefuses(): void {
    $this->authority('agency', 3);
    $id = $this->container->get('uuid')->generate();
    $this->completed(fn () => $this->writer()->create('synthetic', 3, $id, self::TYPE, ['title' => 'Original']));
    $database = $this->container->get('database');
    $database->update('agency_migrate_write_log')->expression('entity_id', 'entity_id + 1')->condition('operation_id', $id)->execute();
    $this->assertRefused(fn () => $this->writer()->create('synthetic', 3, $id, self::TYPE, ['title' => 'Original']), 'operation_unqualified');
    $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
  }

  /**
   * Original unqualified rows remain unchanged through repeatable upgrade.
   */
  public function testOperationNullableUpgradeDoesNotQualifyOldRows(): void {
    $this->container->get('agency_migrate.writes')->record('synthetic', self::TYPE, 17, $this->container->get('uuid')->generate());
    $database = $this->container->get('database');
    foreach (['envelope_version', 'authority_generation', 'operation_id', 'request_hash', 'envelope'] as $field) {
      if ($field === 'operation_id') {
        $database->schema()->dropUniqueKey('agency_migrate_write_log', 'domain_operation');
      }
      $database->schema()->dropField('agency_migrate_write_log', $field);
    }
    require_once $this->container->get('extension.list.module')->getPath('agency_migrate') . '/agency_migrate.install';
    agency_migrate_update_10004();
    agency_migrate_update_10004();
    $rows = $this->container->get('agency_migrate.writes')->pending('synthetic');
    $this->assertCount(1, $rows);
    $this->assertSame(17, (int) $rows[0]['entity_id']);
    foreach (['envelope_version', 'authority_generation', 'operation_id', 'request_hash', 'envelope'] as $field) {
      $this->assertNull($rows[0][$field]);
    }
    $this->assertRefused(fn () => PostCutoverOperationEnvelope::decode($rows[0], $this->container->get('agency_migrate.identity_writer')), 'operation_unqualified');
  }

  /**
   * Reads and validates a real immutable operation row.
   */
  private function envelope(string $operationId): array {
    $row = $this->container->get('database')->select('agency_migrate_write_log', 'w')->fields('w')
      ->condition('operation_id', $operationId)->execute()->fetchAssoc();
    return PostCutoverOperationEnvelope::decode($row, $this->container->get('agency_migrate.identity_writer'));
  }

  /**
   * Concurrent exact operation retries cannot allocate another entity.
   */
  public function testOperationConcurrentDuplicateUsesCommittedReceipt(): void {
    $this->concurrentOperation('duplicate');
  }

  /**
   * Authority transition waits through entity, envelope and evidence commit.
   */
  public function testOperationConcurrentTransitionWaitsForCommit(): void {
    $this->concurrentOperation('transition');
  }

  /**
   * A transition between original commit and retry cannot hide the receipt.
   */
  public function testOperationConcurrentTransitionBeforeRetryKeepsReceipt(): void {
    $this->concurrentOperation('transition', 'duplicate');
  }

  /**
   * Exercises independent real entity kernels sharing only isolated SQL.
   */
  private function concurrentOperation(string ...$modes): void {
    $this->authority('agency', 3);
    $operation = $this->container->get('uuid')->generate();
    $workers = [];
    try {
      foreach ($modes as $mode) {
        $prefix = sys_get_temp_dir() . '/agency-operation-' . bin2hex(random_bytes(8));
        $ready = $prefix . '-ready';
        $release = $prefix . '-release';
        $process = $this->operationWorker($operation, $mode, $ready, $release);
        $workers[$mode] = ['process' => $process, 'ready' => $ready, 'release' => $release];
        $process->start();
        $deadline = microtime(TRUE) + 20;
        while (!is_file($ready)) {
          if (!$process->isRunning() || microtime(TRUE) > $deadline) {
            $this->fail('Independent kernel did not boot: ' . $process->getOutput() . $process->getErrorOutput());
          }
          usleep(20000);
        }
      }
      $this->container->get('state')->set('agency_operation.workers', array_column($workers, 'release', 'ready'));
      $receipt = $this->completed(fn () => $this->writer()->create('synthetic', 3, $operation, self::TYPE, ['title' => 'Concurrent once']));
      $this->assertSame(count($workers), $this->container->get('state')->get('agency_operation.observed_waits'));
      foreach ($workers as $mode => $worker) {
        $process = $worker['process'];
        $this->assertSame(0, $process->wait(), $process->getErrorOutput() . $process->getOutput());
        $other = json_decode($process->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
        if ($mode === 'duplicate') {
          $this->assertSame($receipt, $other);
        }
        else {
          $this->assertSame('transitioned', $other['status']);
          $this->assertSame('removed', $this->container->get('agency_migrate.authority')->get('synthetic'));
        }
      }
      $this->assertSame(1, $this->rows('agency_migrate_agency_item'));
      $this->assertSame(1, $this->rows('agency_migrate_write_log'));
      $this->assertSame(1, (int) $this->container->get('database')->select('agency_migrate_outbox', 'o')->condition('operation', 'post_cutover_write')->countQuery()->execute()->fetchField());
    }
    finally {
      foreach ($workers as $worker) {
        touch($worker['release']);
        $worker['process']->stop(1);
        @unlink($worker['ready']);
        @unlink($worker['release']);
      }
    }
  }

  /**
   * Prepares a worker with the existing kernel's isolated SQL prefix.
   */
  private function operationWorker(string $operation, string $mode, string $ready, string $release): Process {
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $process = new Process([
      PHP_BINARY,
      dirname(__DIR__, 3) . '/fixtures/migrate/operation-worker.php',
      $this->root,
      $vendor,
    ]);
    $process->setInput(json_encode([
      'database' => $this->container->get('database')->getConnectionOptions(),
      'mode' => $mode,
      'operation_id' => $operation,
      'ready' => $ready,
      'release' => $release,
    ], JSON_THROW_ON_ERROR));
    return $process->setTimeout(35);
  }

  /**
   * Writes one synthetic operational authority generation, without config.
   */
  private function authority(string $state, int $generation): void {
    $this->container->get('database')->upsert('agency_migrate_authority_state')->key('domain')->fields([
      'domain', 'state', 'version', 'changed', 'actor_hash', 'reason',
    ])->values(['synthetic', $state, $generation, 1800000000, hash('sha256', 'synthetic'), 'Synthetic qualification'])->execute();
  }

  /**
   * Actual registered operation service.
   */
  private function writer(): object {
    return $this->container->get('agency_migrate.post_cutover_writer');
  }

  /**
   * An unavailable implementation is a failed positive proof, not bootstrap.
   */
  private function completed(callable $work): array {
    try {
      return $work();
    }
    catch (MigrateRefusal $exception) {
      $this->fail('Expected completed operation; got ' . $exception->reason);
    }
  }

  /**
   * Counts persisted rows on the isolated kernel connection.
   */
  private function rows(string $table): int {
    return (int) $this->container->get('database')->select($table, 't')->countQuery()->execute()->fetchField();
  }

}
