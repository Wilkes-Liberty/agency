<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_operation_test;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\OperationSqlContentEntityStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Real SQL storage with explicit post-save fault boundaries.
 */
final class OperationTestStorage extends OperationSqlContentEntityStorage {

  /**
   * In-process synthetic fault and worker coordination controls.
   */
  protected StateInterface $operationState;

  /**
   * Whether the first cache reset after root completion must fail.
   */
  protected bool $failCompletedCacheReset = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $storage = parent::createInstance($container, $entity_type);
    $storage->operationState = $container->get('state');
    if ($container->get('state')->get('agency_operation.foreign_connection')) {
      $storage->database = Database::getConnection('default', 'operation_foreign');
    }
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    $fault = $this->operationState->get('agency_operation.fault');
    if ($fault === 'save_noop') {
      return SAVED_UPDATED;
    }
    $result = parent::save($entity);
    if (in_array($fault, ['cleanup_after_commit', 'cleanup_after_rollback', 'cleanup_after_rollback_callback'], TRUE)) {
      $this->failCompletedCacheReset = TRUE;
    }
    $workers = $this->operationState->get('agency_operation.workers', []);
    foreach ($workers as $ready => $release) {
      touch($release);
      $pid = (int) file_get_contents($ready);
      $deadline = microtime(TRUE) + 10;
      do {
        if ($this->database->driver() === 'pgsql') {
          $this->database->query('SELECT pg_stat_clear_snapshot()');
          $query = $this->database->query("SELECT query FROM pg_stat_activity WHERE pid = :pid AND wait_event_type = 'Lock'", [':pid' => $pid])->fetchField();
        }
        else {
          $query = $this->database->query("SELECT INFO FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = :pid AND COMMAND = 'Query'", [':pid' => $pid])->fetchField();
        }
        $blocked = is_string($query) && str_contains($query, 'agency_migrate_authority_state');
        if (!$blocked) {
          usleep(20000);
        }
      } while (!$blocked && microtime(TRUE) < $deadline);
      if (!$blocked) {
        throw new \RuntimeException('Independent operation did not wait on fresh authority.');
      }
      usleep(300000);
    }
    if ($workers !== []) {
      $this->operationState->set('agency_operation.observed_waits', count($workers));
    }
    if ($fault === 'commit_ack') {
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success): void {
        if ($success) {
          throw new \RuntimeException('Synthetic lost commit acknowledgement.');
        }
      });
    }
    if ($fault === 'cleanup_after_rollback_callback') {
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success): void {
        if (!$success) {
          throw new \RuntimeException('Synthetic rollback callback details.');
        }
      });
    }
    if (in_array($fault, ['cleanup_after_rollback', 'cleanup_after_rollback_callback'], TRUE)) {
      throw new MigrateRefusal('operation_history_conflict');
    }
    if ($fault === 'save') {
      throw new \RuntimeException('Synthetic post-save failure.');
    }
    $old = $this->operationState->get('agency_operation.old_revision');
    if ($fault === 'history_delete') {
      $this->deleteRevision($old);
    }
    if ($fault === 'history_overwrite') {
      $this->database->update('agency_migrate_agency_item_field_revision')->fields(['payload' => 'Altered old payload'])
        ->condition('revision_id', $old)->execute();
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(?array $ids = NULL): void {
    if ($this->failCompletedCacheReset && !$this->database->inTransaction()) {
      $this->failCompletedCacheReset = FALSE;
      throw new \RuntimeException('Synthetic cache backend details.');
    }
    parent::resetCache($ids);
  }

}
