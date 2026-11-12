<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
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
    $storage = $this->entityTypeManager->getStorage($target_type);
    $source = $source->getUntranslated();
    $probe = $storage->create([]);
    if (!$probe instanceof ContentEntityInterface) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $this->assertCompatibleLayout($source, $probe);
    $values = $this->fieldValues($source);
    $created = $storage->create($values);
    if (!$created instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $target = $created;
    $target->enforceIsNew(TRUE);
    $target->setSyncing(TRUE);
    if ($target->getEntityType()->isRevisionable()) {
      $target->setNewRevision(FALSE);
      $target->set($target->getEntityType()->getKey('revision'), $source->getRevisionId());
    }
    $this->copyTranslations($source, $target, FALSE);
    $storage->restore($target);
    $this->assertSameIdentity($source, $target);
    $this->advanceSerial($target);
    $storage->resetCache([(int) $target->id()]);
    $reloaded = $storage->load($target->id());
    if (!$reloaded instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $this->assertSameIdentity($source, $reloaded);
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
