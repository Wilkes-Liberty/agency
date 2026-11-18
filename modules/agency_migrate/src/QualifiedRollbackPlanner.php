<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\RollbackCopierInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Preflights complete replay chains and verifies legacy effects in order.
 *
 * @internal
 */
final class QualifiedRollbackPlanner {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly IdentityPreservingWriter $history,
    private readonly LineageStore $lineage,
    private readonly Reconciler $reconciler,
  ) {}

  /**
   * Requires clean initial lineage, final Agency state and all replay claims.
   *
   * @return array<string, array<int, array<string, mixed>>>
   *   Qualified identity chains keyed by copier id and original integer id.
   */
  public function preflight(array $copiers, array $operations, array $effective): array {
    $sources = [];
    $targets = [];
    $uuid_owners = [];
    if (array_diff_key($effective, $copiers) !== []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    foreach ($copiers as $plugin => $copier) {
      if (!$copier instanceof RollbackCopierInterface
        || isset($sources[$copier->sourceEntityType()]) || isset($targets[$copier->targetEntityType()])) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $sources[$copier->sourceEntityType()] = TRUE;
      $targets[$copier->targetEntityType()] = $copier;
      $this->storage($copier->sourceEntityType())->resetCache();
      $this->storage($copier->targetEntityType())->resetCache();
      foreach ($effective[$plugin] ?? [] as $record) {
        if ($record['source_type'] !== $copier->sourceEntityType() || $record['target_type'] !== $copier->targetEntityType()) {
          throw new MigrateRefusal('rollback_conflict');
        }
        $this->lineage->assertEffectiveAnchor($record);
        $uuid_owners[$plugin][$record['entity_uuid']] = $record['entity_id'];
      }
    }
    if (array_intersect_key($sources, $targets) !== []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $changes = [];
    $owners = [];
    $defaults = [];
    foreach ($operations as $wid => $operation) {
      $copier = $targets[$operation['entity_type']];
      $plugin = $copier->getPluginId();
      $id = $operation['id'];
      $uuid = $operation['uuid'];
      $before = $operation['before'];
      $after = $operation['after'];
      $storage = $this->storage($copier->sourceEntityType());
      if (isset($uuid_owners[$plugin][$uuid]) && $uuid_owners[$plugin][$uuid] !== $id) {
        throw new MigrateRefusal('rollback_conflict');
      }
      $uuid_owners[$plugin][$uuid] = $id;
      if (!isset($changes[$plugin][$id])) {
        $changes[$plugin][$id] = [
          'uuid' => $uuid,
          'before' => $before,
          'first_wid' => $wid,
          'source_type' => $copier->sourceEntityType(),
          'target_type' => $copier->targetEntityType(),
        ] + $this->verifyBaseline($copier, $storage, $id, $uuid, $before, $effective[$plugin][$id] ?? NULL, $wid);
      }
      elseif ($changes[$plugin][$id]['after'] === NULL || $changes[$plugin][$id]['after'] !== $before
        || $changes[$plugin][$id]['uuid'] !== $uuid) {
        throw new MigrateRefusal('rollback_conflict');
      }
      if ($operation['kind'] === 'update') {
        $this->assertAppendOnly($before, $after);
      }
      if ($operation['kind'] === 'create' && $after['revision_count'] !== 1) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      foreach ([$before, $after] as $snapshot) {
        if ($snapshot === NULL) {
          continue;
        }
        $detached = RevisionHistorySnapshot::decode($snapshot, $storage);
        foreach ($snapshot['revisions'] as $revision) {
          $revision_id = $revision['revision_id'];
          $identity = [$id, $uuid];
          if (isset($owners[$plugin][$revision_id]) && $owners[$plugin][$revision_id] !== $identity) {
            throw new MigrateRefusal('rollback_conflict');
          }
          $owners[$plugin][$revision_id] = $identity;
          $occupied = $storage->loadRevision($revision_id);
          if ($occupied !== NULL && (!$occupied instanceof ContentEntityInterface
            || (int) $occupied->id() !== $id || $occupied->uuid() !== $uuid
            || RevisionHistorySnapshot::fields($occupied) !== $revision['fields'])) {
            throw new MigrateRefusal('rollback_conflict');
          }
        }
        if ($snapshot === $after) {
          $defaults[$wid] = $detached[$snapshot['default_revision_id']];
        }
      }
      $changes[$plugin][$id]['after'] = $after;
      $changes[$plugin][$id]['last_wid'] = $wid;
    }
    $inventory = [];
    foreach ($copiers as $plugin => $copier) {
      $current = $changes[$plugin] ?? [];
      $records = $current + $this->effectiveProjections($effective[$plugin] ?? []);
      foreach ($records as $id => $record) {
        $this->assertSnapshot($copier->loadTarget($id), $record['after'], $copier->targetEntityType());
        if (!isset($current[$id])) {
          $this->assertSnapshot($copier->loadSource($id), $record['before'], $copier->sourceEntityType());
        }
        if ($record['after'] === NULL) {
          $preimage = isset($current[$id]) ? $operations[$record['last_wid']]['before'] : $record['preimage'];
          $this->assertDeletedHistory($copier->targetEntityType(), $preimage);
          if (!isset($current[$id])) {
            $this->assertDeletedHistory($copier->sourceEntityType(), $preimage);
          }
        }
      }
      $inventory[$plugin] = $this->reconciler->assertReplayCoverage($copier, $records, FALSE);
    }
    foreach ($operations as $wid => $operation) {
      $copier = $targets[$operation['entity_type']];
      $plugin = $copier->getPluginId();
      if ($operation['after'] === NULL) {
        unset($inventory[$plugin][$operation['id']]);
        continue;
      }
      $inventory[$plugin][$operation['id']] = TRUE;
      $this->verifyOperationReferences($copier, $defaults[$wid], $inventory[$plugin]);
    }
    return $changes;
  }

  /**
   * Checks original lineage, or proves that a create owns a new identity.
   */
  private function verifyBaseline(RollbackCopierInterface $copier, ContentEntityStorageInterface $storage, int $id, string $uuid, ?array $before, ?array $previous, int $wid): array {
    $legacy = $copier->loadSource($id);
    $source_type = $copier->sourceEntityType();
    $target_type = $copier->targetEntityType();
    $source_row = $this->lineage->bySource($source_type, $id);
    $target_row = $this->lineage->byTarget($target_type, $id);
    $by_uuid = $storage->getQuery()->accessCheck(FALSE)
      ->condition($storage->getEntityType()->getKey('uuid'), $uuid)->execute();
    if ($previous !== NULL) {
      if ($before === NULL || $previous['snapshot'] !== $before || $previous['entity_uuid'] !== $uuid
        || array_map('intval', array_values($by_uuid)) !== [$id]) {
        throw new MigrateRefusal('rollback_conflict');
      }
      $this->assertSnapshot($legacy, $before, $source_type);
      return array_intersect_key($previous, array_flip(['lineage_id', 'lineage_digest', 'origin_wid']));
    }
    if ($before === NULL) {
      if ($legacy !== NULL || $source_row !== NULL || $target_row !== NULL || $by_uuid !== []) {
        throw new MigrateRefusal('rollback_conflict');
      }
      return ['lineage_id' => NULL, 'lineage_digest' => NULL, 'origin_wid' => $wid];
    }
    $this->assertSnapshot($legacy, $before, $source_type);
    $manifest = $source_row === NULL ? NULL : $this->lineage->manifest($source_row);
    if ($legacy === NULL || $source_row === NULL || $source_row !== $target_row || $manifest === NULL
      || $source_row['source_uuid'] !== $uuid || $source_row['target_uuid'] !== $uuid
      || $source_row['target_entity_type'] !== $target_type || (int) $source_row['target_id'] !== $id
      || (int) $source_row['source_revision_id'] !== $before['default_revision_id']
      || (int) $source_row['target_revision_id'] !== $before['default_revision_id']
      || array_map('intval', array_values($by_uuid)) !== [$id]
      || $manifest !== $copier->historyManifest($legacy) || $source_row['content_digest'] !== $copier->contentDigest($legacy)) {
      throw new MigrateRefusal('rollback_conflict');
    }
    return [
      'lineage_id' => (int) $source_row['lid'],
      'lineage_digest' => $this->lineage->fingerprint($source_row),
      'origin_wid' => NULL,
    ];
  }

  /**
   * Expands unchanged effective records into exact before and after endpoints.
   */
  private function effectiveProjections(array $effective): array {
    $records = [];
    foreach ($effective as $id => $record) {
      $records[$id] = ['before' => $record['snapshot'], 'after' => $record['snapshot'], 'preimage' => $record['preimage']];
    }
    return $records;
  }

  /**
   * Checks an operation against previously stored revisions and new selection.
   */
  private function assertAppendOnly(array $before, array $after): void {
    $earlier = $this->revisionMap($before);
    $later = $this->revisionMap($after);
    if ($before['layout'] !== $after['layout'] || count($later) !== count($earlier) + 1
      || isset($earlier[$after['default_revision_id']])) {
      throw new MigrateRefusal('rollback_conflict');
    }
    foreach ($earlier as $id => $revision) {
      if (($later[$id] ?? NULL) !== $revision) {
        throw new MigrateRefusal('rollback_conflict');
      }
    }
  }

  /**
   * Indexes immutable payloads without their mutable current selection flag.
   */
  private function revisionMap(array $snapshot): array {
    $map = [];
    foreach ($snapshot['revisions'] as $revision) {
      unset($revision['default']);
      $map[$revision['revision_id']] = $revision;
    }
    return $map;
  }

  /**
   * References must resolve in the projected legacy state at this operation.
   */
  private function verifyOperationReferences(RollbackCopierInterface $copier, ContentEntityInterface $entity, array $inventory): void {
    foreach ($entity->getTranslationLanguages() as $code => $language) {
      $translation = $entity->getTranslation($code);
      foreach ($copier->referencePropertyNames() as $name) {
        if (!$translation->hasField($name)) {
          throw new MigrateRefusal('rollback_unqualified');
        }
        foreach ($translation->get($name) as $item) {
          $property = $item->mainPropertyName();
          if ($property === NULL) {
            throw new MigrateRefusal('rollback_unqualified');
          }
          $id = (int) $item->get($property)->getValue();
          if ($id > 0 && !isset($inventory[$id])) {
            throw new MigrateRefusal('rollback_conflict');
          }
        }
      }
    }
  }

  /**
   * Applies the preflighted plan in the supplied global journal order.
   */
  public function replay(array $targets, array $operations): void {
    foreach ($operations as $operation) {
      $copier = $targets[$operation['entity_type']];
      $type = $copier->sourceEntityType();
      $id = $operation['id'];
      $legacy = $copier->loadSource($id);
      $this->assertSnapshot($legacy, $operation['before'], $type);
      if ($operation['kind'] === 'delete') {
        if ($legacy === NULL) {
          throw new MigrateRefusal('rollback_conflict');
        }
        $storage = $this->storage($type);
        $storage->delete([$legacy]);
        $storage->resetCache([$id]);
        $this->assertSnapshot($copier->loadSource($id), NULL, $type);
        $this->assertDeletedHistory($type, $operation['before']);
      }
      else {
        $merged = $this->history->mergeHistory($operation['after'], $type);
        $this->assertSnapshot($merged, $operation['after'], $type);
      }
    }
  }

  /**
   * Requires absence of the deleted default and its archived revision rows.
   */
  private function assertDeletedHistory(string $type, array $before): void {
    $storage = $this->storage($type);
    foreach ($before['revisions'] as $revision) {
      if ($storage->loadRevision($revision['revision_id']) !== NULL) {
        throw new MigrateRefusal('rollback_conflict');
      }
    }
  }

  /**
   * Verifies exact live history or proven absence, for all domain copiers.
   */
  public function verifyReplayed(array $copiers, array $changes, array $effective): void {
    foreach ($copiers as $plugin => $copier) {
      $records = ($changes[$plugin] ?? []) + $this->effectiveProjections($effective[$plugin] ?? []);
      foreach ($records as $id => $record) {
        $this->assertSnapshot($copier->loadSource($id), $record['after'], $copier->sourceEntityType());
        $this->assertSnapshot($copier->loadTarget($id), $record['after'], $copier->targetEntityType());
      }
      $this->reconciler->assertReplayCoverage($copier, $records, TRUE);
    }
  }

  /**
   * Clears cached legacy IDs after aborting an outer replay transaction.
   */
  public function clearLegacyCaches(array $targets, array $operations): void {
    $ids = [];
    foreach ($operations as $operation) {
      $ids[$targets[$operation['entity_type']]->sourceEntityType()][$operation['id']] = $operation['id'];
    }
    foreach ($ids as $type => $affected) {
      $this->storage($type)->resetCache(array_values($affected));
    }
  }

  /**
   * Compares exact history, allowing just the proven side's entity type name.
   */
  private function assertSnapshot(?ContentEntityInterface $entity, ?array $snapshot, string $type): void {
    if ($entity === NULL || $snapshot === NULL) {
      if ($entity !== NULL || $snapshot !== NULL) {
        throw new MigrateRefusal('rollback_conflict');
      }
      return;
    }
    $snapshot['source_type'] = $type;
    if ($entity->getEntityTypeId() !== $type || $snapshot !== $this->history->captureHistory($entity)) {
      throw new MigrateRefusal('rollback_conflict');
    }
  }

  /**
   * Requires an explicitly qualified participant on the replay connection.
   */
  private function storage(string $type): ContentEntityStorageInterface {
    $storage = $this->entityTypeManager->getStorage($type);
    if (!$storage instanceof ContentEntityStorageInterface || !$storage instanceof OperationStorageParticipantInterface
      || $storage->operationConnection() !== $this->database) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $storage;
  }

}
