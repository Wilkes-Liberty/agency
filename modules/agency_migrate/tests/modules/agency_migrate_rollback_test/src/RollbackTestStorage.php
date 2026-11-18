<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_rollback_test;

use Drupal\agency_migrate\OperationSqlContentEntityStorage;
use Drupal\Core\Entity\EntityInterface;

/**
 * Trace and fault injection around actual legacy SQL mutations.
 */
final class RollbackTestStorage extends OperationSqlContentEntityStorage {

  /**
   * Observed effects, including attempted writes that later roll back.
   *
   * @var list<array{string, int}>
   */
  public static array $effects = [];

  /**
   * Optional phase to interrupt after the actual SQL mutation.
   */
  public static ?string $interrupt = NULL;

  /**
   * Runs a competing worker once during the held replay transaction.
   */
  public static ?\Closure $compete = NULL;

  /**
   * {@inheritdoc}
   */
  public function restore(EntityInterface $entity) {
    parent::restore($entity);
    self::$effects[] = ['restore', (int) $entity->id()];
    if (self::$interrupt === 'restore') {
      throw new \RuntimeException('Synthetic restore interruption');
    }
    if (self::$interrupt === 'commit') {
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success): void {
        if ($success) {
          throw new \RuntimeException('Synthetic lost handover acknowledgement');
        }
      });
    }
    if (self::$compete !== NULL) {
      $compete = self::$compete;
      self::$compete = NULL;
      $compete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    parent::delete($entities);
    foreach ($entities as $entity) {
      self::$effects[] = ['delete', (int) $entity->id()];
    }
    if (self::$interrupt === 'delete') {
      throw new \RuntimeException('Synthetic delete interruption');
    }
  }

}
