<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Transaction;

/**
 * Completes the caller-owned root transaction on both supported generations.
 */
final class RootTransactionCommitter implements TransactionCommitterInterface {

  public function commit(?Transaction &$transaction): void {
    if ($transaction === NULL) {
      throw new \LogicException('A root transaction is required.');
    }
    if (version_compare(\Drupal::VERSION, '11.3.0', '>=')) {
      $transaction->commitOrRelease();
    }
    else {
      $transaction = NULL;
    }
  }

}
