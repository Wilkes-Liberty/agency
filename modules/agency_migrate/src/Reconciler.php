<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\agency_migrate\Plugin\HistoryCopierInterface;
use Drupal\agency_migrate\Plugin\InventoryCopierInterface;
use Drupal\agency_migrate\Plugin\RollbackCopierInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Compares legacy and Agency through shadow reads.
 *
 * Reports counts, per-field digests, reference integrity and access-decision
 * parity for a sample. Historical Audit Chain rows are never rewritten.
 */
final class Reconciler {

  /**
   * Identity fields excluded from content drift.
   */
  private const IDENTITY_FIELDS = [
    'id',
    'uuid',
    'revision_id',
    'langcode',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'created',
    'changed',
    'revision_uid',
    'revision_timestamp',
    'revision_log',
    'revision_user',
    'revision_created',
    'revision_log_message',
  ];

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly LineageStore $lineage,
    private readonly AccountProxyInterface $currentUser,
    private readonly RollbackReplayStore $replay,
    private readonly IdentityPreservingWriter $history,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Compares one copier's source and target for a sample set.
   *
   * @param string $plugin_id
   *   Copier plugin id.
   * @param list<int> $sample_ids
   *   Source integer ids to inspect. Empty compares complete inventories.
   * @param list<string> $principals
   *   Opaque principal tokens used only for access-decision parity.
   */
  public function compare(string $plugin_id, array $sample_ids = [], array $principals = []): ReconciliationReport {
    if (!$this->currentUser->hasPermission('run agency migrate')) {
      throw new MigrateRefusal('forbidden');
    }
    if (!$this->copiers->hasDefinition($plugin_id)) {
      throw new MigrateRefusal('plugin_missing');
    }
    $copier = $this->copiers->createInstance($plugin_id);
    $effective = $this->effectiveRecords($plugin_id, $copier);
    $source_count = $copier->countSource();
    $target_count = $copier->countTarget();
    $matched = $this->lineage->countPair($copier->sourceEntityType(), $copier->targetEntityType());
    $effective_matched = $matched;
    foreach ($effective as $record) {
      $effective_matched += (int) ($record['snapshot'] !== NULL) - (int) ($record['origin'] === 'copy');
    }
    $exceptions = [];
    $coverage = ['scope' => 'sample', 'complete' => FALSE, 'total_source' => $source_count];
    $ids = array_values(array_unique(array_map('intval', $sample_ids)));
    sort($ids, SORT_NUMERIC);
    if ($sample_ids === []) {
      $inventory = $this->fullInventory($copier, $source_count, $target_count, $matched, $effective, $effective_matched);
      $ids = $inventory['ids'];
      $coverage = $inventory['coverage'];
      $exceptions = $inventory['exceptions'];
    }
    $digests = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      $source = $copier->loadSource($id);
      $target = $copier->loadTarget($id);
      $record = $effective[$id] ?? NULL;
      if ($source === NULL && $target === NULL && $record !== NULL && $record['snapshot'] === NULL) {
        continue;
      }
      if ($source === NULL) {
        $exceptions[] = [
          'type' => 'missing_source',
          'id' => $id,
        ];
        continue;
      }
      if ($target === NULL) {
        $exceptions[] = [
          'type' => 'missing_on_target',
          'id' => $id,
        ];
        continue;
      }
      if ((int) $source->id() !== $id || (int) $target->id() !== $id
        || $source->getEntityTypeId() !== $copier->sourceEntityType()
        || $target->getEntityTypeId() !== $copier->targetEntityType()
        || $source->uuid() !== $target->uuid()
        || (int) ($source->getRevisionId() ?? $id) !== (int) ($target->getRevisionId() ?? $id)) {
        $exceptions[] = ['type' => 'identity_drift', 'id' => $id];
        continue;
      }
      if ($record !== NULL) {
        if (!$this->matchesEffective($source, $record['snapshot']) || !$this->matchesEffective($target, $record['snapshot'])) {
          $exceptions[] = ['type' => 'history_drift', 'id' => $id];
        }
      }
      else {
        $exceptions = [...$exceptions, ...$this->copyLineageExceptions($copier, $source, $target, $id)];
      }
      $source_map = $copier->canonicalize($source);
      $target_map = $copier->canonicalize($target);
      $digests[$id] = [];
      foreach ($source_map as $field => $encoded) {
        $target_encoded = $target_map[$field] ?? '';
        $digests[$id][$field] = [
          'source' => hash('sha256', $encoded),
          'target' => hash('sha256', $target_encoded),
        ];
        if (in_array($field, self::IDENTITY_FIELDS, TRUE)) {
          continue;
        }
        if ($encoded !== $target_encoded) {
          $exceptions[] = [
            'type' => 'field_drift',
            'id' => $id,
            'field' => $field,
            'source_digest' => hash('sha256', $encoded),
            'target_digest' => hash('sha256', $target_encoded),
          ];
        }
      }
      $exceptions = [
        ...$exceptions,
        ...$this->referenceExceptions($copier, $source, 'source'),
        ...$this->referenceExceptions($copier, $target, 'target'),
      ];
      foreach ($principals as $principal) {
        $source_allow = $copier->accessDecision($principal, 'source', $id);
        $target_allow = $copier->accessDecision($principal, 'target', $id);
        if ($source_allow !== $target_allow) {
          $exceptions[] = [
            'type' => 'access_mismatch',
            'id' => $id,
            'principal' => $principal,
            'source' => $source_allow,
            'target' => $target_allow,
          ];
        }
      }
    }
    if ($sample_ids === []) {
      try {
        if ($source_count !== $copier->countSource() || $target_count !== $copier->countTarget()
          || $matched !== $this->lineage->countPair($copier->sourceEntityType(), $copier->targetEntityType())) {
          throw new MigrateRefusal('coverage_unverified');
        }
      }
      catch (\Throwable) {
        $coverage['complete'] = FALSE;
        $exceptions[] = ['type' => 'coverage_unverified'];
      }
    }
    $coverage['inspected'] = count($ids);
    return new ReconciliationReport(
      [
        'source' => $source_count,
        'target' => $target_count,
        'matched' => $effective_matched,
        'sampled' => count($ids),
      ],
      $digests,
      $exceptions,
      $coverage,
    );
  }

  /**
   * Resolves each effective identity from the copier's declared domains.
   */
  private function effectiveRecords(string $plugin_id, CopierInterface $copier): array {
    try {
      $records = [];
      $definition = $this->copiers->getDefinition($plugin_id);
      foreach ($definition['domains'] ?? [] as $domain) {
        foreach ($this->replay->effectiveDomain($domain)[$plugin_id] ?? [] as $id => $record) {
          if (isset($records[$id]) || $record['source_type'] !== $copier->sourceEntityType()
            || $record['target_type'] !== $copier->targetEntityType()) {
            throw new MigrateRefusal('coverage_unverified');
          }
          $this->lineage->assertEffectiveAnchor($record);
          if ($record['snapshot'] === NULL) {
            $this->assertDeletedHistory($copier->sourceEntityType(), $record['preimage']);
            $this->assertDeletedHistory($copier->targetEntityType(), $record['preimage']);
          }
          $records[$id] = $record;
        }
      }
      return $records;
    }
    catch (\Throwable) {
      throw new MigrateRefusal('coverage_unverified');
    }
  }

  /**
   * Verifies the exact tombstone in the mapped SQL tables, including orphans.
   *
   * Storage loaders join revisions through the base table. An orphan revision
   * can be invisible to them even though its archived values remain stored.
   *
   * @internal
   */
  public function assertDeletedHistory(string $type, array $preimage): void {
    $this->assertStoredAbsence($type, $preimage['id'], array_column($preimage['revisions'], 'revision_id'));
  }

  /**
   * Confirms that detached incoming revision IDs have no physical row.
   *
   * @internal
   */
  public function assertUnusedRevisionIds(string $type, array $vids): void {
    $this->assertStoredAbsence($type, NULL, $vids);
  }

  /**
   * Uses the replay connection and supported SQL storage table mapping.
   */
  private function assertStoredAbsence(string $type, ?int $id, array $vids): void {
    $storage = $this->entityTypeManager->getStorage($type);
    if (!$storage instanceof SqlEntityStorageInterface || !$storage instanceof OperationStorageParticipantInterface
      || $storage->operationConnection() !== $this->database) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $mapping = $storage->getTableMapping();
    $entity_type = $storage->getEntityType();
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');
    if (!$mapping instanceof DefaultTableMapping || !$mapping->getRevisionTable() || !$id_key || !$revision_key) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $shared_tables = array_filter([
      $mapping->getBaseTable(), $mapping->getDataTable(),
      $mapping->getRevisionTable(), $mapping->getRevisionDataTable(),
    ]);
    foreach (array_unique($shared_tables) as $table) {
      $columns = $mapping->getAllColumns($table);
      $vid_column = in_array($revision_key, $columns, TRUE) ? $revision_key : NULL;
      $this->assertNoStoredIdentity($table, $id_key, $vid_column, $id, $vids);
    }
    foreach ($mapping->getDedicatedTableNames() as $table) {
      $this->assertNoStoredIdentity($table, 'entity_id', 'revision_id', $id, $vids);
    }
  }

  /**
   * Detects stored rows for the identity or the supplied revision IDs.
   */
  private function assertNoStoredIdentity(string $table, string $id_key, ?string $revision_key, ?int $id, array $vids): void {
    if ($id === NULL && $revision_key === NULL) {
      return;
    }
    $query = $this->database->select($table, 'r')->range(0, 1);
    $conditions = $query->orConditionGroup();
    if ($id !== NULL) {
      $conditions->condition($id_key, $id);
    }
    if ($revision_key !== NULL) {
      $conditions->condition($revision_key, $vids, 'IN');
    }
    $query->addExpression('1');
    if ($query->condition($conditions)->execute()->fetchField() !== FALSE) {
      throw new MigrateRefusal('rollback_conflict');
    }
  }

  /**
   * Requires full parity before handing over an empty operation journal.
   *
   * Opted-in copiers supply their reviewed access cases. Older copiers retain
   * their existing full reconciliation contract for the empty-journal path.
   *
   * @internal
   */
  public function assertEmptyRollback(CopierInterface $copier): void {
    $principals = $copier instanceof RollbackCopierInterface ? $this->rollbackPrincipals($copier) : [];
    if (!$this->compare($copier->getPluginId(), [], $principals)->fullPassed()) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Validates the explicit access cases for a qualified rollback copier.
   *
   * @return non-empty-list<string>
   *   Validated principal tokens.
   */
  private function rollbackPrincipals(RollbackCopierInterface $copier): array {
    $principals = $copier->rollbackPrincipals();
    if ($principals === [] || !array_is_list($principals)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    foreach ($principals as $principal) {
      if (!is_string($principal) || $principal === '') {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    return $principals;
  }

  /**
   * Compares typed persisted history against the sealed journal endpoint.
   */
  private function matchesEffective(ContentEntityInterface $entity, ?array $snapshot): bool {
    if ($snapshot === NULL) {
      return FALSE;
    }
    try {
      $snapshot['source_type'] = $entity->getEntityTypeId();
      return $snapshot === $this->history->captureHistory($entity);
    }
    catch (MigrateRefusal) {
      return FALSE;
    }
  }

  /**
   * Keeps the original copy verification for identities with no replay.
   */
  private function copyLineageExceptions(CopierInterface $copier, ContentEntityInterface $source, ContentEntityInterface $target, int $id): array {
    $exceptions = [];
    $row = $this->lineage->bySource($copier->sourceEntityType(), $id);
    if ($row === NULL) {
      $exceptions[] = ['type' => 'missing_lineage', 'id' => $id];
    }
    else {
      if ($row['source_uuid'] !== $source->uuid()
        || $row['target_uuid'] !== $target->uuid()
        || $row['target_entity_type'] !== $copier->targetEntityType()
        || (int) $row['target_id'] !== $id
        || (int) $row['source_revision_id'] !== (int) ($source->getRevisionId() ?? $id)
        || (int) $row['target_revision_id'] !== (int) ($target->getRevisionId() ?? $id)) {
        $exceptions[] = ['type' => 'identity_drift', 'id' => $id];
      }
      if ($row['content_digest'] !== $copier->contentDigest($source)
        || $row['content_digest'] !== $copier->contentDigest($target)) {
        $exceptions[] = ['type' => 'lineage_drift', 'id' => $id];
      }
    }
    if ($copier instanceof HistoryCopierInterface || $source->getEntityType()->isRevisionable()) {
      $manifest = $row === NULL ? NULL : $this->lineage->manifest($row);
      try {
        if (!$copier instanceof HistoryCopierInterface || $manifest === NULL) {
          throw new MigrateRefusal('history_unverified');
        }
        if ($manifest !== $copier->historyManifest($source)
          || $manifest !== $copier->historyManifest($target)
          || $row['source_uuid'] !== $source->uuid()
          || $row['target_uuid'] !== $target->uuid()
          || $row['target_entity_type'] !== $copier->targetEntityType()
          || (int) $row['target_id'] !== (int) $target->id()
          || (int) $row['source_revision_id'] !== (int) ($source->getRevisionId() ?? $source->id())
          || (int) $row['target_revision_id'] !== (int) ($target->getRevisionId() ?? $target->id())
          || $row['content_digest'] !== $copier->contentDigest($source)) {
          throw new MigrateRefusal('history_drift');
        }
      }
      catch (MigrateRefusal $exception) {
        $exceptions[] = ['type' => $exception->reason, 'id' => $id];
      }
    }
    return $exceptions;
  }

  /**
   * Reconciles whole rollback inventories against original and replay lineage.
   *
   * Changed histories are checked against their full snapshots by the planner.
   * Inventory, references and access also cover records outside the journal.
   *
   * @param \Drupal\agency_migrate\Plugin\RollbackCopierInterface $copier
   *   A copier with reviewed identity, history and access semantics.
   * @param array<int, array<string, mixed>> $changes
   *   Planned baseline and final snapshots for affected source identities.
   * @param bool $applied
   *   Whether legacy now holds the final snapshots.
   *
   * @return array<int, array{uuid: string, revision_id: int}>
   *   The actual legacy side inventory at this stage.
   *
   * @internal
   */
  public function assertReplayCoverage(RollbackCopierInterface $copier, array $changes, bool $applied): array {
    $source_type = $copier->sourceEntityType();
    $target_type = $copier->targetEntityType();
    $source_count = $copier->countSource();
    $target_count = $copier->countTarget();
    $lineage_count = $this->lineage->countPair($source_type, $target_type);
    $principals = $this->rollbackPrincipals($copier);
    $baseline = [];
    foreach ($this->lineageInventory($copier, $lineage_count) as $row) {
      $id = (int) $row['source_id'];
      if ($id < 1 || $id !== (int) $row['target_id'] || isset($baseline[$id])
        || $row['source_uuid'] !== $row['target_uuid']
        || (int) $row['source_revision_id'] !== (int) $row['target_revision_id']) {
        throw new MigrateRefusal('rollback_conflict');
      }
      $baseline[$id] = ['uuid' => $row['source_uuid'], 'revision_id' => (int) $row['source_revision_id']];
    }
    $expected_source = $expected_target = $baseline;
    foreach ($changes as $id => $change) {
      $this->projectIdentity($expected_source, $id, $applied ? $change['after'] : $change['before']);
      $this->projectIdentity($expected_target, $id, $change['after']);
    }
    ksort($expected_source, SORT_NUMERIC);
    ksort($expected_target, SORT_NUMERIC);
    $sources = $this->entityInventory($copier->loadSourceBatch(...), $source_type, $source_count);
    $targets = $this->entityInventory($copier->loadTargetBatch(...), $target_type, $target_count);
    if ($sources !== $expected_source || $targets !== $expected_target) {
      throw new MigrateRefusal('rollback_conflict');
    }
    foreach (array_keys($sources) as $id) {
      $source = $copier->loadSource($id);
      if ($source === NULL || $this->referenceExceptions($copier, $source, 'source') !== []) {
        throw new MigrateRefusal('rollback_conflict');
      }
      if (!isset($changes[$id])) {
        $target = $copier->loadTarget($id);
        $row = $this->lineage->bySource($source_type, $id);
        $manifest = $row === NULL ? NULL : $this->lineage->manifest($row);
        if ($target === NULL || $row === NULL || $manifest === NULL
          || $manifest !== $copier->historyManifest($source) || $manifest !== $copier->historyManifest($target)
          || $row['content_digest'] !== $copier->contentDigest($source)
          || $row['content_digest'] !== $copier->contentDigest($target)) {
          throw new MigrateRefusal('rollback_conflict');
        }
      }
      // Unchanged effective histories already exist on both sides.
      if ($applied || !isset($changes[$id]) || $changes[$id]['before'] === $changes[$id]['after']) {
        foreach ($principals as $principal) {
          if ($copier->accessDecision($principal, 'source', $id) !== $copier->accessDecision($principal, 'target', $id)) {
            throw new MigrateRefusal('rollback_conflict');
          }
        }
      }
    }
    foreach (array_keys($targets) as $id) {
      $target = $copier->loadTarget($id);
      if ($target === NULL || $this->referenceExceptions($copier, $target, 'target') !== []) {
        throw new MigrateRefusal('rollback_conflict');
      }
    }
    if ($source_count !== $copier->countSource() || $target_count !== $copier->countTarget()
      || $lineage_count !== $this->lineage->countPair($source_type, $target_type)) {
      throw new MigrateRefusal('rollback_conflict');
    }
    return $sources;
  }

  /**
   * Projects a live identity, or removes the identity for a qualified delete.
   */
  private function projectIdentity(array &$inventory, int $id, ?array $snapshot): void {
    if ($snapshot === NULL) {
      unset($inventory[$id]);
      return;
    }
    $inventory[$id] = ['uuid' => $snapshot['uuid'], 'revision_id' => $snapshot['default_revision_id']];
  }

  /**
   * Broken integer references on one side of a record.
   *
   * @return list<array<string, mixed>>
   *   Exception records.
   */
  private function referenceExceptions(CopierInterface $copier, ContentEntityInterface $entity, string $side): array {
    $exceptions = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      foreach ($copier->referencePropertyNames() as $field) {
        if (!$translation->hasField($field)) {
          throw new MigrateRefusal('incompatible_field_layout');
        }
        foreach ($translation->get($field) as $delta => $item) {
          $main = $item->mainPropertyName();
          if ($main === NULL) {
            throw new MigrateRefusal('incompatible_field_layout');
          }
          $referenced = (int) $item->get($main)->getValue();
          if ($referenced <= 0) {
            continue;
          }
          $loaded = $side === 'source'
            ? $copier->loadSource($referenced)
            : $copier->loadTarget($referenced);
          if ($loaded instanceof ContentEntityInterface) {
            continue;
          }
          $exceptions[] = [
            'type' => 'broken_reference',
            'side' => $side,
            'id' => (int) $entity->id(),
            'field' => $field,
            'langcode' => $langcode,
            'delta' => $delta,
            'referenced_id' => $referenced,
          ];
        }
      }
    }
    return $exceptions;
  }

  /**
   * Checks complete, bounded source, target and lineage identity inventories.
   *
   * @return array{ids: list<int>, coverage: array<string, mixed>, exceptions: list<array<string, mixed>>}
   *   Observed population coverage, not a fence against concurrent writes.
   */
  private function fullInventory(CopierInterface $copier, int $source_count, int $target_count, int $matched, array $effective, int $effective_matched): array {
    $coverage = ['scope' => 'full', 'complete' => FALSE, 'total_source' => $source_count];
    $exceptions = [];
    if ($source_count !== $target_count || $source_count !== $effective_matched) {
      $exceptions[] = [
        'type' => 'count_mismatch',
        'source' => $source_count,
        'target' => $target_count,
        'lineage' => $effective_matched,
      ];
    }
    $sources = [];
    try {
      $sources = $this->entityInventory($copier->loadSourceBatch(...), $copier->sourceEntityType(), $source_count);
      if (!$copier instanceof InventoryCopierInterface) {
        throw new MigrateRefusal('coverage_unverified');
      }
      $targets = $this->entityInventory($copier->loadTargetBatch(...), $copier->targetEntityType(), $target_count);
      $rows = $this->lineageInventory($copier, $matched);
      $lineage_sources = [];
      $lineage_targets = [];
      $mapping = [];
      foreach ($rows as $row) {
        $source_id = (int) $row['source_id'];
        $target_id = (int) $row['target_id'];
        if ($source_id < 1 || $target_id < 1 || isset($lineage_sources[$source_id]) || isset($lineage_targets[$target_id])) {
          throw new MigrateRefusal('coverage_unverified');
        }
        $lineage_sources[$source_id] = [
          'uuid' => $row['source_uuid'],
          'revision_id' => (int) $row['source_revision_id'],
        ];
        $lineage_targets[$target_id] = [
          'uuid' => $row['target_uuid'],
          'revision_id' => (int) $row['target_revision_id'],
        ];
        $mapping[$source_id] = $target_id;
        if ($source_id !== $target_id) {
          $exceptions[] = ['type' => 'identity_drift', 'inventory' => 'lineage', 'id' => $source_id];
        }
      }
      foreach ($effective as $id => $record) {
        $this->projectIdentity($lineage_sources, $id, $record['snapshot']);
        $this->projectIdentity($lineage_targets, $id, $record['snapshot']);
        if ($record['snapshot'] === NULL) {
          unset($mapping[$id]);
        }
        else {
          $mapping[$id] = $id;
        }
      }
      ksort($lineage_sources, SORT_NUMERIC);
      ksort($lineage_targets, SORT_NUMERIC);
      ksort($mapping, SORT_NUMERIC);
      $exceptions = [
        ...$exceptions,
        ...$this->inventoryExceptions($sources, $targets, 'target'),
        ...$this->inventoryExceptions($sources, $lineage_sources, 'lineage_source'),
        ...$this->inventoryExceptions($targets, $lineage_targets, 'lineage_target'),
      ];
      if ($source_count !== $copier->countSource() || $target_count !== $copier->countTarget()
        || $matched !== $this->lineage->countPair($copier->sourceEntityType(), $copier->targetEntityType())) {
        throw new MigrateRefusal('coverage_unverified');
      }
      $coverage['source_inventory_digest'] = hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR));
      $coverage['target_inventory_digest'] = hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR));
      $coverage['lineage_inventory_digest'] = hash('sha256', json_encode([$lineage_sources, $lineage_targets, $mapping], JSON_THROW_ON_ERROR));
      $coverage['complete'] = $exceptions === [];
    }
    catch (\Throwable) {
      $exceptions[] = ['type' => 'coverage_unverified'];
    }
    return ['ids' => array_keys($sources), 'coverage' => $coverage, 'exceptions' => $exceptions];
  }

  /**
   * Scans pages while rejecting duplicates, wrong types and stale cursors.
   *
   * @return array<int, array{uuid: string, revision_id: int}>
   *   Identity tuples indexed by strictly increasing integer entity id.
   */
  private function entityInventory(callable $loader, string $entity_type, int $expected): array {
    $cursor = 0;
    $inventory = [];
    $uuids = [];
    while (TRUE) {
      $batch = $loader($cursor, 100);
      if ($batch === []) {
        break;
      }
      if (count($batch) > 100) {
        throw new MigrateRefusal('coverage_unverified');
      }
      foreach ($batch as $entity) {
        if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type
          || (int) $entity->id() <= $cursor || !$entity->isDefaultRevision()
          || !is_string($entity->uuid()) || $entity->uuid() === ''
          || isset($uuids[$entity->uuid()]) || count($inventory) >= $expected) {
          throw new MigrateRefusal('coverage_unverified');
        }
        $cursor = (int) $entity->id();
        $inventory[$cursor] = ['uuid' => $entity->uuid(), 'revision_id' => (int) ($entity->getRevisionId() ?? $cursor)];
        $uuids[$entity->uuid()] = TRUE;
      }
    }
    if (count($inventory) !== $expected) {
      throw new MigrateRefusal('coverage_unverified');
    }
    return $inventory;
  }

  /**
   * Scans the lineage primary key with the same finite cursor contract.
   *
   * @return list<array<string, mixed>>
   *   Persisted source/target identity rows, excluding history payloads.
   */
  private function lineageInventory(CopierInterface $copier, int $expected): array {
    $rows = [];
    $cursor = 0;
    while (TRUE) {
      $batch = $this->lineage->rowsForPair($copier->sourceEntityType(), $copier->targetEntityType(), $cursor, 100);
      if ($batch === []) {
        break;
      }
      foreach ($batch as $row) {
        if ((int) $row['lid'] <= $cursor || count($rows) >= $expected) {
          throw new MigrateRefusal('coverage_unverified');
        }
        $cursor = (int) $row['lid'];
        $rows[] = $row;
      }
    }
    if (count($rows) !== $expected) {
      throw new MigrateRefusal('coverage_unverified');
    }
    return $rows;
  }

  /**
   * Compares actual membership and identity, independent of population counts.
   *
   * @return list<array<string, mixed>>
   *   Inventory membership or UUID/revision identity mismatches.
   */
  private function inventoryExceptions(array $expected, array $actual, string $inventory): array {
    $exceptions = [];
    $missing = array_keys(array_diff_key($expected, $actual));
    $extra = array_keys(array_diff_key($actual, $expected));
    if ($missing !== [] || $extra !== []) {
      $exceptions[] = [
        'type' => 'inventory_mismatch',
        'inventory' => $inventory,
        'missing_ids' => $missing,
        'extra_ids' => $extra,
      ];
    }
    foreach (array_intersect_key($expected, $actual) as $id => $identity) {
      if ($identity !== $actual[$id]) {
        $exceptions[] = ['type' => 'identity_drift', 'inventory' => $inventory, 'id' => $id];
      }
    }
    return $exceptions;
  }

}
