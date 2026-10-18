<?php

declare(strict_types=1);

namespace Drupal\agency_host;

use Drupal\Core\Database\Transaction;

/**
 * Commits or releases a transaction across supported core transaction APIs.
 */
final class TransactionSupport {

  /**
   * Commits/releases a transaction, clearing the caller's Drupal 10 handle.
   */
  public static function commit(Transaction &$transaction): void {
    if (method_exists($transaction, 'commitOrRelease')) {
      $transaction->commitOrRelease();
    }
    $transaction = NULL;
  }

}
