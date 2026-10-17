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
    $values = $this->scalarValues($source);
    $created = $storage->create($values);
    if (!$created instanceof ContentEntityInterface) {
      throw new MigrateRefusal('identity_not_preserved');
    }
    $target = $created;
    $target->enforceIsNew(TRUE);
    $target->setSyncing(TRUE);
    if ($target->getEntityType()->isRevisionable()) {
      $target->setNewRevision(FALSE);
      $target->set('revision_id', $source->getRevisionId());
    }
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
    $existing->setSyncing(TRUE);
    foreach ($this->scalarValues($from) as $name => $value) {
      if ($this->isIdentityField($existing, $name)) {
        continue;
      }
      $existing->set($name, $value);
    }
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
   * Builds create() values from scalar and reference main properties.
   *
   * @return array<string, mixed>
   *   Field names keyed to the main property value.
   */
  public function scalarValues(ContentEntityInterface $entity): array {
    $values = [];
    foreach ($entity->getFields(FALSE) as $name => $field) {
      $definition = $field->getFieldDefinition();
      if ($definition->isComputed()) {
        continue;
      }
      $item = $field->first();
      if ($item === NULL) {
        $values[$name] = NULL;
        continue;
      }
      $main = $item->mainPropertyName();
      if ($main === NULL) {
        continue;
      }
      $values[$name] = $item->get($main)->getValue();
    }
    return $values;
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
