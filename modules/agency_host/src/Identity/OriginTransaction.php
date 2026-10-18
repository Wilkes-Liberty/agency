<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;

/**
 * Attempts cancellation while preserving the operation failure.
 */
final class OriginTransaction {

  /**
   * Attempts rollback without replacing the original operation failure.
   *
   * Drupal 11.4's pgsql TransactionManager leaves the Drupal stack Active
   * when PDO::commit() throws. PostgreSQL has already ended that session
   * transaction, so a later Connection::commitAll() issues a second COMMIT
   * ("There is no active transaction") or finds a closed client. Reopen a
   * client transaction and roll it back so callbacks see failure. Never void
   * the stack: Drupal treats a merely voided transaction as a successful
   * commit. Independent guards remain when rollback cannot be confirmed.
   */
  public static function rollBack(Transaction $transaction): void {
    try {
      $transaction->rollBack();
    }
    catch (\Throwable) {
      // First rollback may fail after a throwing COMMIT; recover below.
    }
    try {
      $connection = (new \ReflectionProperty(Transaction::class, 'connection'))->getValue($transaction);
      if (!$connection instanceof Connection) {
        return;
      }
      $manager = $connection->transactionManager();
      if ($manager->stackDepth() === 0 || !$manager->inTransaction()) {
        return;
      }
      $client = $connection->getClientConnection();
      if (!$client instanceof \PDO) {
        return;
      }
      try {
        if ($client->inTransaction()) {
          return;
        }
      }
      catch (\Throwable) {
        return;
      }
      $client->beginTransaction();
      $transaction->rollBack();
    }
    catch (\Throwable) {
      // Independent guards remain in place when rollback cannot be confirmed.
    }
  }

}
