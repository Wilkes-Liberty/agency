<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\Core\Database\Transaction;

/**
 * Attempts cancellation while preserving the operation failure.
 */
final class OriginTransaction {

  /**
   * Attempts rollback without replacing the original operation failure.
   */
  public static function rollBack(Transaction $transaction): void {
    try {
      $transaction->rollBack();
    }
    catch (\Throwable) {
      // The patched driver owns failed-commit cleanup. Never void the stack
      // here: Drupal treats a merely voided transaction as a successful commit.
      // Independent guards remain in place when rollback cannot be confirmed.
    }
  }

}
