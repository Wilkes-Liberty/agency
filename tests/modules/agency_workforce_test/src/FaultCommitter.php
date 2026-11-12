<?php

declare(strict_types=1);

namespace Drupal\agency_workforce_test;

use Drupal\agency_workforce\RootTransactionCommitter;
use Drupal\agency_workforce\TransactionCommitterInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Transaction;

/**
 * A real commit followed by acknowledgement loss, or a pre-commit fault.
 */
final class FaultCommitter implements TransactionCommitterInterface {

  public function __construct(private readonly ConfigFactoryInterface $config) {}

  /**
   * {@inheritdoc}
   */
  public function commit(?Transaction &$transaction): void {
    $fault = $this->config->get('agency_workforce_test.settings')->get('fault');
    if ($fault === 'commit_noop') {
      return;
    }
    if ($fault === 'commit_before') {
      throw new \RuntimeException('Synthetic commit failure.');
    }
    (new RootTransactionCommitter())->commit($transaction);
    if ($fault === 'commit_ack') {
      throw new \RuntimeException('Synthetic acknowledgement loss.');
    }
  }

}
