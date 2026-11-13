<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Storage;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\agency_workforce\Entity\WorkforceRecord;
use Drupal\agency_workforce\Exception\WorkforceRefusal;
use Drupal\agency_workforce\WorkforceTypes;

/**
 * Preserves explicit identifiers and refuses colliding workforce rows.
 */
final class WorkforceStorage extends SqlContentEntityStorage {

  /**
   * Entity type ids installed by agency_workforce.
   */
  public const TYPES = WorkforceTypes::ALL;

  /**
   * {@inheritdoc}
   */
  public function hasData() {
    $table = $this->getBaseTable();
    if ($table === '' || !$this->database->schema()->tableExists($table)) {
      return FALSE;
    }
    return parent::hasData();
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    if (!$entity instanceof WorkforceRecord) {
      throw new EntityStorageException('Workforce storage accepts workforce records only.');
    }
    $entity->assertWorkforceConstraints();
    if ($entity->isNew() && $this->hasExplicitId($entity)
      && $entity->getEntityType()->isRevisionable()
      && !(int) $entity->getRevisionId()) {
      $entity->set($entity->getEntityType()->getKey('revision'), (int) $entity->id());
    }
    $this->refuseCollision($entity);
    try {
      if ($entity->isNew() && $this->hasExplicitId($entity)) {
        return $this->insertExplicit($entity);
      }
      return parent::save($entity);
    }
    catch (IntegrityConstraintViolationException) {
      throw new WorkforceRefusal('collision');
    }
  }

  /**
   * Inserts a new row while keeping caller-supplied identity fields.
   */
  private function insertExplicit(WorkforceRecord $entity): int {
    if ($entity->uuid() === NULL || $entity->uuid() === '') {
      $entity->set('uuid', $this->uuidService->generate());
    }
    $entity->enforceIsNew(TRUE);
    $entity->setSyncing(TRUE);
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->isDefaultRevision(TRUE);
      $entity->setNewRevision(TRUE);
    }
    $id = (int) $entity->id();
    $this->restore($entity);
    $this->advanceSerial($entity);
    $this->resetCache([$id]);
    return $id;
  }

  /**
   * True when the caller supplied an integer id for ID-preserving create.
   */
  private function hasExplicitId(EntityInterface $entity): bool {
    $id = $entity->id();
    return $id !== NULL && $id !== '' && (int) $id > 0;
  }

  /**
   * Refuses an explicit id, UUID or revision id already held by another row.
   */
  private function refuseCollision(WorkforceRecord $entity): void {
    if (!$entity->isNew()) {
      return;
    }
    $id = $entity->id();
    if ($this->hasExplicitId($entity)) {
      $existing = $this->load((int) $id);
      if ($existing !== NULL) {
        throw new WorkforceRefusal('collision');
      }
    }
    $uuid = $entity->uuid();
    if (is_string($uuid) && $uuid !== '') {
      $matches = $this->loadByProperties(['uuid' => $uuid]);
      if ($matches !== []) {
        throw new WorkforceRefusal('collision');
      }
    }
    if ($entity->getEntityType()->isRevisionable()) {
      $revision_id = $entity->getRevisionId();
      if ($revision_id !== NULL && $revision_id !== '' && (int) $revision_id > 0) {
        $revision = $this->loadRevision($revision_id);
        if ($revision !== NULL) {
          throw new WorkforceRefusal('collision');
        }
      }
    }
  }

  /**
   * Moves PostgreSQL sequences past explicitly inserted identifiers.
   */
  private function advanceSerial(WorkforceRecord $entity): void {
    if ($this->database->driver() !== 'pgsql') {
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
