<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\MapItem;

/**
 * Checks native shared SQL map storage before accepting hydrated values.
 *
 * @internal
 */
final class SharedSqlMapVerifier {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Identifies the supported storage and physical map columns.
   *
   * @return array<string, array{column: string, current: string, revision: string}>
   *   SQL column and tables keyed by field name.
   */
  public function columns(ContentEntityInterface $entity): array {
    $fields = array_filter(
      $entity->getFieldDefinitions(),
      static fn ($field) => !$field->isComputed() && $field->getType() === 'map',
    );
    if ($fields === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof SqlEntityStorageInterface || !$entity->getEntityType()->isRevisionable()) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $mapping = $storage->getTableMapping();
    if (!$mapping instanceof DefaultTableMapping) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $translated = $entity->getEntityType()->isTranslatable();
    $current = $translated ? $mapping->getDataTable() : $mapping->getBaseTable();
    $revision = $translated ? $mapping->getRevisionDataTable() : $mapping->getRevisionTable();
    $result = [];
    foreach ($fields as $name => $field) {
      $definition = $field->getFieldStorageDefinition();
      $tables = $mapping->getAllFieldTableNames($name);
      $columns = $mapping->getColumnNames($name);
      if ($field->getItemDefinition()->getClass() !== MapItem::class
        || !$mapping->allowsSharedTableStorage($definition)
        || !$definition->isRevisionable() || $definition->getCardinality() !== 1
        || ($definition->getColumns()['value']['serialize'] ?? FALSE) !== TRUE
        || array_keys($columns) !== ['value']
        || count($tables) !== 2 || !in_array($current, $tables, TRUE) || !in_array($revision, $tables, TRUE)) {
        throw new MigrateRefusal('incompatible_field_layout');
      }
      $result[$name] = ['column' => $columns['value'], 'current' => $current, 'revision' => $revision];
    }
    return $result;
  }

  /**
   * Verifies that both SQL projections match the selected default.
   */
  public function assertCurrent(ContentEntityInterface $current): void {
    $this->assertStored($current, TRUE);
    $this->assertStored($current, FALSE);
  }

  /**
   * Verifies one persisted revision or its current-table projection.
   */
  public function assertStored(ContentEntityInterface $revision, bool $current): void {
    $type = $revision->getEntityType();
    $translated = $type->isTranslatable();
    foreach ($this->columns($revision) as $name => $mapping) {
      $table = $mapping[$current ? 'current' : 'revision'];
      $query = $this->database->select($table, 'm')
        ->condition('m.' . $type->getKey('id'), $revision->id())
        ->condition('m.' . $type->getKey('revision'), $revision->getRevisionId());
      $query->addField('m', $mapping['column'], 'map_value');
      if ($translated) {
        $query->addField('m', $type->getKey('langcode'), 'map_language');
      }
      $untranslated = $revision->getUntranslated();
      $languages = $translated ? $revision->getTranslationLanguages() : [$untranslated->language()->getId() => $untranslated->language()];
      $rows = $query->execute()->fetchAll();
      if (count($rows) !== count($languages)) {
        throw new MigrateRefusal('history_unverified');
      }
      foreach ($rows as $row) {
        $language = $translated ? $row->map_language : $untranslated->language()->getId();
        if (!isset($languages[$language])) {
          throw new MigrateRefusal('history_unverified');
        }
        unset($languages[$language]);
        $list = $revision->getTranslation($language)->get($name);
        $first = $list->first();
        $raw = is_resource($row->map_value) ? stream_get_contents($row->map_value) : $row->map_value;
        // Shared storage serializes the first item's full map, or the empty
        // array. Comparing bytes also checks key order and scalar types.
        if ($list->count() > 1 || $raw !== serialize($first ? RevisionHistorySnapshot::mapValues($first) : [])) {
          throw new MigrateRefusal('history_unverified');
        }
      }
    }
  }

}
