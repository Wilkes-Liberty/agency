<?php

declare(strict_types=1);

namespace Drupal\agency_expense;

use Drupal\Core\Database\Transaction;

/**
 * Completes the caller-owned root transaction on both supported generations.
 */
final class TransactionCommitter {

  /**
   * Commits before returning receipts on both supported core generations.
   *
   * @param \Drupal\Core\Database\Transaction|null $transaction
   *   The open root transaction.
   */
  public function commit(?Transaction &$transaction): void {
    if ($transaction === NULL) {
      throw new \LogicException('A transaction is required.');
    }
    if (version_compare(\Drupal::VERSION, '11.3.0', '>=')) {
      $transaction->commitOrRelease();
    }
    else {
      $transaction = NULL;
    }
  }

}
