<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_operation_test;

use Drupal\agency_migrate\OperationSqlContentEntityStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Real SQL storage with explicit post-save fault boundaries.
 */
final class OperationTestStorage extends OperationSqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $storage = parent::createInstance($container, $entity_type);
    if ($container->get('state')->get('agency_operation.foreign_connection')) {
      $storage->database = Database::getConnection('default', 'operation_foreign');
    }
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    $fault = \Drupal::state()->get('agency_operation.fault');
    if ($fault === 'save_noop') {
      return SAVED_UPDATED;
    }
    $result = parent::save($entity);
    if ($fault === 'commit_ack') {
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success): void {
        if ($success) {
          throw new \RuntimeException('Synthetic lost commit acknowledgement.');
        }
      });
    }
    if ($fault === 'save') {
      throw new \RuntimeException('Synthetic post-save failure.');
    }
    $old = \Drupal::state()->get('agency_operation.old_revision');
    if ($fault === 'history_delete') {
      $this->deleteRevision($old);
    }
    if ($fault === 'history_overwrite') {
      $this->database->update('agency_migrate_agency_item_field_revision')->fields(['payload' => 'Altered old payload'])
        ->condition('revision_id', $old)->execute();
    }
    return $result;
  }

}
