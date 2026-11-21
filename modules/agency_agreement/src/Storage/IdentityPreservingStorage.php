<?php

declare(strict_types=1);

namespace Drupal\agency_agreement\Storage;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Refuses occupied integer ids, UUIDs and revision ids on create.
 */
final class IdentityPreservingStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function restore(EntityInterface $entity): void {
    if ($entity instanceof ContentEntityInterface) {
      $this->refuseOccupiedIdentifiers($entity);
    }
    parent::restore($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function doSave($id, EntityInterface $entity) {
    if ($entity->isNew() && $entity instanceof ContentEntityInterface) {
      $this->refuseOccupiedIdentifiers($entity);
    }
    return parent::doSave($id, $entity);
  }

  /**
   * Fails closed when an explicit identifier is already stored.
   */
  private function refuseOccupiedIdentifiers(
    ContentEntityInterface $entity,
  ): void {
    $id_key = $this->entityType->getKey('id');
    $explicit_id = $entity->id();
    if (is_numeric($explicit_id) && (int) $explicit_id > 0) {
      $occupied = (int) $this->getQuery()
        ->accessCheck(FALSE)
        ->condition($id_key, (int) $explicit_id)
        ->range(0, 1)
        ->count()
        ->execute();
      if ($occupied > 0) {
        throw new EntityStorageException('collision');
      }
    }

    $uuid_key = $this->entityType->getKey('uuid');
    $uuid = $entity->uuid();
    if (is_string($uuid) && $uuid !== '') {
      $occupied = (int) $this->getQuery()
        ->accessCheck(FALSE)
        ->condition($uuid_key, $uuid)
        ->range(0, 1)
        ->count()
        ->execute();
      if ($occupied > 0) {
        throw new EntityStorageException('collision');
      }
    }

    if (!$this->entityType->isRevisionable()) {
      return;
    }
    $revision_key = $this->entityType->getKey('revision');
    $revision_id = $entity->getRevisionId();
    if (!is_numeric($revision_id) || (int) $revision_id < 1) {
      return;
    }
    $occupied = (int) $this->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition($revision_key, (int) $revision_id)
      ->range(0, 1)
      ->count()
      ->execute();
    if ($occupied > 0) {
      throw new EntityStorageException('collision');
    }
  }

}
