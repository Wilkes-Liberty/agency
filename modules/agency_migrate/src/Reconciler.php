<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\agency_migrate\Plugin\HistoryCopierInterface;
use Drupal\agency_migrate\Plugin\InventoryCopierInterface;
use Drupal\agency_migrate\Plugin\RollbackCopierInterface;
use Drupal\Core\Entity\ContentEntityInterface;
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
    $source_count = $copier->countSource();
    $target_count = $copier->countTarget();
    $matched = $this->lineage->countPair($copier->sourceEntityType(), $copier->targetEntityType());
    $exceptions = [];
    $coverage = ['scope' => 'sample', 'complete' => FALSE, 'total_source' => $source_count];
    $ids = array_values(array_unique(array_map('intval', $sample_ids)));
    sort($ids, SORT_NUMERIC);
    if ($sample_ids === []) {
      $inventory = $this->fullInventory($copier, $source_count, $target_count, $matched);
      $ids = $inventory['ids'];
      $coverage = $inventory['coverage'];
      $exceptions = $inventory['exceptions'];
    }
    $digests = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      $source = $copier->loadSource($id);
      $target = $copier->loadTarget($id);
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
      $source_map = $copier->canonicalize($source);
      $target_map = $copier->canonicalize($target);
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
        'matched' => $matched,
        'sampled' => count($ids),
      ],
      $digests,
      $exceptions,
      $coverage,
    );
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
    $principals = $copier->rollbackPrincipals();
    if ($principals === [] || !array_is_list($principals)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    foreach ($principals as $principal) {
      if (!is_string($principal) || $principal === '') {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
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
      if ($applied || !isset($changes[$id])) {
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
  private function fullInventory(CopierInterface $copier, int $source_count, int $target_count, int $matched): array {
    $coverage = ['scope' => 'full', 'complete' => FALSE, 'total_source' => $source_count];
    $exceptions = [];
    if ($source_count !== $target_count || $source_count !== $matched) {
      $exceptions[] = [
        'type' => 'count_mismatch',
        'source' => $source_count,
        'target' => $target_count,
        'lineage' => $matched,
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
