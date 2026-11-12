<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Transaction;

/**
 * Root commit boundary; an exception may represent an uncertain acknowledgement.
 */
interface TransactionCommitterInterface {

  public function commit(?Transaction &$transaction): void;

}
