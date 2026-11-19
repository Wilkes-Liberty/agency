<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\agency_migrate\Exception\MigrateRefusal;

/**
 * Inserts a content entity using the caller's integer id, UUID and revision id.
 */
final class IdentityPreservingWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Creates a target entity that reuses the source identity fields.
   */
  public function insertCopy(ContentEntityInterface $source, string $target_type): ContentEntityInterface {
    $transaction = $this->database->startTransaction();
    try {
      $target = $this->restoreHistory($source, $target_type);
      TransactionBoundary::commit($transaction);
      return $target;
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      $this->entityTypeManager->getStorage($target_type)->resetCache([(int) $source->id()]);
      throw $exception;
    }
  }

  /**
   * Captures typed revision values for later replay without a live source.
   *
   * @return array<string, mixed>
   *   Versioned snapshot; see RevisionHistorySnapshot for its value contract.
   */
  public function captureHistory(ContentEntityInterface $entity): array {
    if (!$entity->getEntityType()->isRevisionable()) {
      throw new MigrateRefusal('history_unverified');
    }
    $history = $this->loadHistory($entity);
    $snapshot = RevisionHistorySnapshot::capture($entity, $history);
    $this->validateHistorySnapshot($snapshot, $entity->getEntityTypeId());
    return $snapshot;
  }

  /**
   * Checks a snapshot without loading its source or changing entity storage.
   */
  public function validateHistorySnapshot(array $snapshot, string $target_type): void {
    $storage = $this->entityTypeManager->getStorage($target_type);
    if (!$storage instanceof ContentEntityStorageInterface) {
      throw new MigrateRefusal('history_unverified');
    }
    RevisionHistorySnapshot::decode($snapshot, $storage);
  }

  /**
   * Atomically merges immutable revisions and selects the captured default.
   *
   * Target-only revisions remain. Identical overlaps are idempotent.
   * Conflicting stored revisions refuse, even for the same entity.
   * This helper does not qualify journals, lineage or authority transitions.
   */
  public function mergeHistory(array $snapshot, string $target_type): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($target_type);
    if (!$storage instanceof ContentEntityStorageInterface) {
      throw new MigrateRefusal('history_unverified');
    }
    // Validate the entire payload before starting any target mutation.
    $incoming = RevisionHistorySnapshot::decode($snapshot, $storage);
    $id = $snapshot['id'];
    $default_id = $snapshot['default_revision_id'];
    $transaction = $this->database->startTransaction();
    try {
      $storage->resetCache([$id]);
      $current = $storage->load($id);
      if ($current !== NULL && (!$current instanceof ContentEntityInterface || $current->uuid() !== $snapshot['uuid'])) {
        throw new MigrateRefusal('history_conflict');
      }
      $uuid_ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition($incoming[$default_id]->getEntityType()->getKey('uuid'), $snapshot['uuid'])->execute();
      if (array_diff(array_map('intval', array_values($uuid_ids)), [$id]) !== []) {
        throw new MigrateRefusal('history_conflict');
      }
      $expected = [];
      if ($current instanceof ContentEntityInterface) {
        foreach ($this->loadHistory($current) as $revision) {
          $expected[(int) $revision->getRevisionId()] = RevisionHistorySnapshot::fields($revision);
        }
      }
      $missing = [];
      foreach ($incoming as $revision_id => $revision) {
        $fields = RevisionHistorySnapshot::fields($revision);
        $occupied = $storage->loadRevision($revision_id);
        if ($occupied !== NULL) {
          if (!$occupied instanceof ContentEntityInterface
            || (int) $occupied->id() !== $id || $occupied->uuid() !== $snapshot['uuid']
            || RevisionHistorySnapshot::fields($occupied) !== $fields) {
            throw new MigrateRefusal('history_conflict');
          }
        }
        else {
          $missing[$revision_id] = $revision;
        }
        $expected[$revision_id] = $fields;
      }
      if ($current === NULL) {
        $this->restoreRevision($incoming[$default_id], $target_type, TRUE);
        unset($missing[$default_id]);
      }
      foreach ($missing as $revision) {
        $this->restoreRevision($revision, $target_type, FALSE);
      }
      $storage->resetCache([$id]);
      $current = $storage->load($id);
      if (!$current instanceof ContentEntityInterface) {
        throw new MigrateRefusal('history_drift');
      }
      if ((int) $current->getRevisionId() !== $default_id) {
        $selected = $storage->loadRevision($default_id);
        if (!$selected instanceof ContentEntityInterface || !$selected->wasDefaultRevision()) {
          throw new MigrateRefusal('history_conflict');
        }
        // Saving an already persisted, historically default revision updates
        // current tables. Verification below rejects presave rewrites anywhere.
        // Compare presave translation metadata to this persisted revision,
        // rather than to a different current revision on the legacy branch.
        // Core 10 uses the public original property; core 11 adds setOriginal.
        $legacy_original = version_compare(\Drupal::VERSION, '11.2.0', '<');
        if (!$legacy_original) {
          $selected->setOriginal(clone $selected);
        }
        else {
          $selected->original = clone $selected;
        }
        $selected->setSyncing(TRUE);
        $selected->setNewRevision(FALSE);
        $selected->isDefaultRevision(TRUE);
        $selected->save();
        // Core 10 skips dedicated fields that equal the original revision,
        // even when selecting that revision changes their current values.
        if ($legacy_original && $storage instanceof SqlEntityStorageInterface) {
          $this->refreshDedicatedProjection($storage, $selected);
        }
      }
      $this->advanceSerial($incoming[$default_id]);
      $storage->resetCache([$id]);
      $result = $storage->load($id);
      if (!$result instanceof ContentEntityInterface || (int) $result->getRevisionId() !== $default_id
        || RevisionHistorySnapshot::fields($result) !== $expected[$default_id]) {
        throw new MigrateRefusal('history_drift');
      }
      $actual = [];
      foreach ($this->loadHistory($result) as $revision) {
        if ($revision->isDefaultRevision() !== ((int) $revision->getRevisionId() === $default_id)) {
          throw new MigrateRefusal('history_drift');
        }
        $actual[(int) $revision->getRevisionId()] = RevisionHistorySnapshot::fields($revision);
      }
      ksort($expected, SORT_NUMERIC);
      ksort($actual, SORT_NUMERIC);
      if ($actual !== $expected) {
        throw new MigrateRefusal('history_drift');
      }
      TransactionBoundary::commit($transaction);
      $storage->resetCache([$id]);
      return $result;
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      $storage->resetCache([$id]);
      throw $exception;
    }
  }

  /**
   * Refreshes current dedicated field rows from the immutable SQL revision.
   */
  private function refreshDedicatedProjection(SqlEntityStorageInterface $storage, ContentEntityInterface $selected): void {
    $mapping = $storage->getTableMapping();
    if (!$mapping instanceof DefaultTableMapping) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    foreach ($selected->getFieldDefinitions() as $field) {
      $definition = $field->getFieldStorageDefinition();
      if (!$mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }
      $current = $mapping->getDedicatedDataTableName($definition);
      $history = $mapping->getDedicatedRevisionTableName($definition);
      $columns = $mapping->getAllColumns($current);
      if ($current === $history || $columns === [] || $columns !== $mapping->getAllColumns($history)) {
        throw new MigrateRefusal('incompatible_field_layout');
      }
      $this->database->delete($current)->condition('entity_id', $selected->id())->condition('deleted', 0)->execute();
      $values = $this->database->select($history, 'r')->fields('r', $columns)
        ->condition('entity_id', $selected->id())
        ->condition('revision_id', $selected->getRevisionId())
        ->condition('deleted', 0);
      $this->database->insert($current)->from($values)->execute();
    }
  }

  /**
   * Inserts a complete history inside the writer's transaction boundary.
   */
  private function restoreHistory(ContentEntityInterface $source, string $target_type): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($target_type);
    $source = $source->getUntranslated();
    $history = $this->loadHistory($source);
    $snapshot = $this->historySnapshot($history);
    $probe = $storage->create([]);
    if (!$probe instanceof ContentEntityInterface) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $this->assertCompatibleLayout($source, $probe);
    foreach ($history as $revision) {
      $this->assertCompatibleLayout($revision, $probe);
      if ($source->getEntityType()->isRevisionable()
        && $storage instanceof ContentEntityStorageInterface
        && $storage->loadRevision($revision->getRevisionId()) !== NULL) {
        throw new MigrateRefusal('history_conflict');
      }
    }
    // Restore the actual default once. Older defaults and pending revisions
    // must never touch current tables.
    $target = $this->restoreRevision($source, $target_type, TRUE);
    foreach ($history as $revision) {
      if ((int) $revision->getRevisionId() !== (int) $source->getRevisionId()) {
        $this->restoreRevision($revision, $target_type, FALSE);
      }
    }
    if ($snapshot !== $this->historySnapshot($this->loadHistory($source))) {
      throw new MigrateRefusal('history_drift');
    }
    $this->advanceSerial($target);
    $storage->resetCache([(int) $target->id()]);
    $reloaded = $storage->load($target->id());
    if (!$reloaded instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $this->assertSameIdentity($source, $reloaded);
    $storage->resetCache([(int) $source->id()]);
    return $reloaded;
  }

  /**
   * Loads every persisted revision, refusing an inconsistent current snapshot.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   Revisions ordered by revision identifier.
   */
  public function loadHistory(ContentEntityInterface $entity): array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    $storage->resetCache([(int) $entity->id()]);
    $current = $storage->load($entity->id());
    if (!$current instanceof ContentEntityInterface
      || $current->uuid() !== $entity->uuid()
      || (int) $current->getRevisionId() !== (int) $entity->getRevisionId()
      || $this->canonicalize($current) !== $this->canonicalize($entity)) {
      throw new MigrateRefusal('history_drift');
    }
    // Cached snapshot rows must not outlive an outer rollback.
    $storage->resetCache([(int) $entity->id()]);
    if (!$entity->getEntityType()->isRevisionable()) {
      return [$current];
    }
    if (!$storage instanceof ContentEntityStorageInterface) {
      throw new MigrateRefusal('history_unverified');
    }
    $ids = $storage->getQuery()->allRevisions()->accessCheck(FALSE)
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->sort($entity->getEntityType()->getKey('revision'))
      ->execute();
    $revisions = $storage->loadMultipleRevisions(array_keys($ids));
    if ($ids === [] || count($revisions) !== count($ids)) {
      throw new MigrateRefusal('history_drift');
    }
    foreach ($revisions as $revision) {
      if (!$revision instanceof ContentEntityInterface
        || (int) $revision->id() !== (int) $entity->id()
        || $revision->uuid() !== $entity->uuid()) {
        throw new MigrateRefusal('history_conflict');
      }
    }
    return array_values($revisions);
  }

  /**
   * Full immutable comparison values for a history read, including defaults.
   *
   * @param list<\Drupal\Core\Entity\ContentEntityInterface> $history
   *   Persisted revision objects.
   *
   * @return array<int, array<string, mixed>>
   *   Revision ids keyed to canonical content and runtime default selection.
   */
  private function historySnapshot(array $history): array {
    $snapshot = [];
    foreach ($history as $revision) {
      $snapshot[(int) ($revision->getRevisionId() ?? $revision->id())] = [
        'fields' => $this->canonicalize($revision),
        'default' => $revision->isDefaultRevision(),
      ];
    }
    ksort($snapshot, SORT_NUMERIC);
    return $snapshot;
  }

  /**
   * Restores one revision while preserving its historical default metadata.
   */
  private function restoreRevision(ContentEntityInterface $source, string $target_type, bool $default): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($target_type);
    $values = $this->fieldValues($source);
    $created = $storage->create($values);
    if (!$created instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $target = $created;
    // A new entity cannot be marked nondefault through the Entity API. Restore
    // already has explicit identifiers and performs inserts regardless of this
    // runtime flag; it does not invoke presave and rewrite revision metadata.
    $target->enforceIsNew($default);
    $target->isDefaultRevision($default);
    $target->setSyncing(TRUE);
    if ($target->getEntityType()->isRevisionable()) {
      $target->setNewRevision(FALSE);
      $target->set($target->getEntityType()->getKey('revision'), $source->getRevisionId());
    }
    $this->copyTranslations($source, $target, FALSE);
    $storage->restore($target);
    $this->assertSameIdentity($source, $target);
    $storage->resetCache([(int) $target->id()]);
    $reloaded = $source->getEntityType()->isRevisionable() && $storage instanceof ContentEntityStorageInterface
      ? $storage->loadRevision($source->getRevisionId())
      : $storage->load($target->id());
    if (!$reloaded instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $this->assertSameIdentity($source, $reloaded);
    if ($this->canonicalize($source) !== $this->canonicalize($reloaded)
      || $reloaded->isDefaultRevision() !== $default) {
      throw new MigrateRefusal('history_drift');
    }
    return $reloaded;
  }

  /**
   * Overwrites content fields on an existing entity without changing identity.
   */
  public function overwriteContent(ContentEntityInterface $existing, ContentEntityInterface $from): ContentEntityInterface {
    $existing = $existing->getUntranslated();
    $from = $from->getUntranslated();
    $this->assertCompatibleLayout($from, $existing);
    if ($existing->language()->getId() !== $from->language()->getId()) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $existing->setSyncing(TRUE);
    foreach ($this->fieldValues($from) as $name => $value) {
      if (!$this->isIdentityField($existing, $name)) {
        $existing->set($name, $value);
      }
    }
    $this->copyTranslations($from, $existing, TRUE);
    $existing->save();
    $storage = $this->entityTypeManager->getStorage($existing->getEntityTypeId());
    $storage->resetCache([(int) $existing->id()]);
    $reloaded = $storage->load($existing->id());
    if (!$reloaded instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    return $reloaded;
  }

  /**
   * Typed stored fields shared by copy verification and reconciliation.
   *
   * @return array<string, string>
   *   Sorted field names mapped to JSON of translations and ordered items.
   */
  public function canonicalize(ContentEntityInterface $entity): array {
    $values = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      foreach ($entity->getTranslation($langcode)->getFields(FALSE) as $name => $field) {
        if ($field->getFieldDefinition()->isComputed()) {
          continue;
        }
        $values[$name][$langcode] = [];
        foreach ($field as $item) {
          if ($field->getFieldDefinition()->getType() === 'map') {
            // Encode the item before sorting field and language keys. PHP
            // serialization retains map order, sparse keys and scalar types.
            $values[$name][$langcode][] = base64_encode(serialize(RevisionHistorySnapshot::mapValues($item)));
            continue;
          }
          $properties = [];
          foreach ($item->getProperties(FALSE) as $property_name => $property) {
            $value = $property->getValue();
            $properties[$property_name] = $value !== NULL && $property instanceof PrimitiveInterface
              ? $property->getCastedValue()
              : $value;
          }
          $values[$name][$langcode][] = $properties;
        }
      }
    }
    ksort($values);
    $canonical = [];
    foreach ($values as $name => $translations) {
      $canonical[$name] = json_encode($this->sortProperties($translations), JSON_THROW_ON_ERROR);
    }
    return $canonical;
  }

  /**
   * Sorts property maps while preserving the order of field item lists.
   */
  private function sortProperties(array $values): array {
    if (!array_is_list($values)) {
      ksort($values);
    }
    foreach ($values as &$value) {
      if (is_array($value)) {
        $value = $this->sortProperties($value);
      }
    }
    return $values;
  }

  /**
   * Builds values from every stored property of every field item.
   *
   * @return array<string, mixed>
   *   Field names keyed to ordered item values, including empty lists.
   */
  public function fieldValues(ContentEntityInterface $entity): array {
    $values = [];
    foreach ($entity->getFields(FALSE) as $name => $field) {
      if ($field->getFieldDefinition()->isComputed()) {
        continue;
      }
      $values[$name] = [];
      foreach ($field as $item) {
        if ($field->getFieldDefinition()->getType() === 'map') {
          $map = RevisionHistorySnapshot::mapValues($item);
          // SQL reload passes the raw map to the item list. A leading
          // numeric key is interpreted as a delta and loses map structure.
          if (is_numeric(array_key_first($map))) {
            throw new MigrateRefusal('incompatible_field_layout');
          }
          $values[$name][] = $map;
          continue;
        }
        $properties = [];
        foreach ($item->getProperties(FALSE) as $property_name => $property) {
          $properties[$property_name] = $property->getValue();
        }
        $values[$name][] = $properties;
      }
    }
    return $values;
  }

  /**
   * Rejects layouts requiring an explicit host-specific field mapping.
   */
  private function assertCompatibleLayout(ContentEntityInterface $source, ContentEntityInterface $target): void {
    $source_type = $source->getEntityType();
    $target_type = $target->getEntityType();
    foreach (['id', 'uuid', 'revision', 'langcode', 'bundle'] as $key) {
      if ($source_type->getKey($key) !== $target_type->getKey($key)) {
        throw new MigrateRefusal('incompatible_field_layout');
      }
    }
    if ($source_type->isRevisionable() !== $target_type->isRevisionable()
      || $source_type->isTranslatable() !== $target_type->isTranslatable()) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $definitions = [];
    foreach ([$source, $target] as $entity) {
      $fields = [];
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        if (!$definition->isComputed()) {
          $fields[$name] = [
            $definition->getType(),
            $definition->isTranslatable(),
            $definition->getFieldStorageDefinition()->getCardinality(),
            $definition->getFieldStorageDefinition()->getSettings(),
          ];
        }
      }
      ksort($fields);
      $definitions[] = $fields;
    }
    // Boolean field labels can be equivalent, distinct markup objects.
    if ($definitions[0] != $definitions[1]) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
  }

  /**
   * Copies current translations, removing target-only translations on replay.
   */
  private function copyTranslations(ContentEntityInterface $source, ContentEntityInterface $target, bool $overwrite): void {
    foreach ($target->getTranslationLanguages(FALSE) as $langcode => $language) {
      if (!$source->hasTranslation($langcode)) {
        $target->removeTranslation($langcode);
      }
    }
    foreach ($source->getTranslationLanguages(FALSE) as $langcode => $language) {
      $values = $this->fieldValues($source->getTranslation($langcode));
      if (!$target->hasTranslation($langcode)) {
        $target->addTranslation($langcode, $values);
        continue;
      }
      $translation = $target->getTranslation($langcode);
      foreach ($values as $name => $value) {
        if ($translation->getFieldDefinition($name)->isTranslatable()
          && (!$overwrite || !$this->isIdentityField($target, $name))) {
          $translation->set($name, $value);
        }
      }
    }
  }

  /**
   * Identity keys that a content overwrite must not remap.
   */
  public function isIdentityField(ContentEntityInterface $entity, string $name): bool {
    $keys = $entity->getEntityType()->getKeys();
    $identity = [
      $keys['id'] ?? 'id',
      $keys['uuid'] ?? 'uuid',
      $keys['revision'] ?? 'revision_id',
      $keys['langcode'] ?? 'langcode',
      'default_langcode',
      'revision_default',
      'revision_translation_affected',
      'created',
      'revision_uid',
      'revision_timestamp',
      'revision_log',
      'revision_user',
      'revision_created',
      'revision_log_message',
    ];
    return in_array($name, $identity, TRUE);
  }

  /**
   * Fails when id, UUID or revision id diverged from the source.
   */
  private function assertSameIdentity(ContentEntityInterface $source, ContentEntityInterface $target): void {
    if ((int) $target->id() !== (int) $source->id()
      || $target->uuid() !== $source->uuid()) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    if ($source->getEntityType()->isRevisionable() && $target->getEntityType()->isRevisionable()) {
      if ((int) $target->getRevisionId() !== (int) $source->getRevisionId()) {
        throw new MigrateRefusal('identity_not_preserved');
      }
    }
  }

  /**
   * Moves PostgreSQL sequences past explicitly inserted identifiers.
   */
  private function advanceSerial(ContentEntityInterface $entity): void {
    if ($this->database->driver() !== 'pgsql') {
      return;
    }
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof SqlEntityStorageInterface) {
      return;
    }
    $tables = [
      $entity->getEntityType()->getBaseTable() => [
        $entity->getEntityType()->getKey('id'),
        (int) $entity->id(),
      ],
    ];
    $revision_table = $entity->getEntityType()->getRevisionTable();
    if (is_string($revision_table) && $revision_table !== '' && $entity->getEntityType()->isRevisionable()) {
      $tables[$revision_table] = [
        $entity->getEntityType()->getKey('revision'),
        (int) $entity->getRevisionId(),
      ];
    }
    foreach ($tables as $table => [$column, $value]) {
      if (!is_string($column) || $column === '' || $value < 1) {
        continue;
      }
      if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
        continue;
      }
      $prefixed = $this->database->getPrefix() . $table;
      $this->database->query(
        'SELECT setval(pg_get_serial_sequence(' . $this->database->quote($prefixed) . ", '" . $column . "'), GREATEST(:value, (SELECT COALESCE(MAX(" . $column . '), 1) FROM {' . $table . '})))',
        [':value' => $value],
      );
    }
  }

}
