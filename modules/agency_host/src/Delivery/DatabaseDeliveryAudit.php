<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\agency_host\TransactionSupport;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Commits minimized custody evidence independently of encrypted payloads.
 */
final class DatabaseDeliveryAudit implements DeliveryAuditInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $operation, array $metadata): void {
    if (!preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $operation)) {
      throw new \InvalidArgumentException('Invalid delivery audit operation.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('agency_host_delivery_audit')->fields([
        'event_id' => bin2hex(random_bytes(16)),
        'operation' => $operation,
        'created' => $this->time->getCurrentTime(),
        'payload' => json_encode($metadata, JSON_THROW_ON_ERROR),
      ])->execute();
      TransactionSupport::commit($transaction);
    }
    catch (\Throwable $error) {
      $transaction?->rollBack();
      throw new \RuntimeException('Required delivery audit persistence failed.', 0, $error);
    }
  }

}
