<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\Core\Database\Transaction;

/**
 * Root commit boundary; an exception can mean uncertain acknowledgement.
 */
interface TransactionCommitterInterface {

  /**
   * Completes the owned root transaction; failure may be uncertain.
   */
  public function commit(?Transaction &$transaction): void;

}
