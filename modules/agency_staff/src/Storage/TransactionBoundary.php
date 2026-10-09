<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Storage;

use Drupal\Core\Database\Transaction;

/**
 * Commits before returning receipts on both supported core generations.
 */
final class TransactionBoundary {

  /**
   * Completes the transaction in the caller's try/catch, including Drupal 10.
   *
   * The caller owns the only reference. Drupal 10 commits when that reference
   * is released; newer cores provide the explicit commit/savepoint method.
   *
   * @see https://www.drupal.org/node/3512006
   */
  public static function commit(?Transaction &$transaction): void {
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
