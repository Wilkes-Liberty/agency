<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * Durable minimized intents; Audit Chain owns chain integrity.
 */
final class MigrateOutbox {

  public function __construct(
    private readonly Connection $database,
    private readonly AuditChainLoggerInterface $logger,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records one allowlisted operation. Duplicate fingerprints are ignored.
   *
   * @param string $operation
   *   One of lineage_batch, authority_change, rollback,
   *   unmapped_marking, agency_read_refused or post_cutover_write.
   * @param array<string, mixed> $metadata
   *   Minimized public metadata. No record payloads or personal data.
   * @param string $fingerprint
   *   Idempotency key for this intent.
   */
  public function record(string $operation, array $metadata, string $fingerprint): string {
    $allowed = [
      'lineage_batch',
      'authority_change',
      'rollback',
      'unmapped_marking',
      'agency_read_refused',
      'post_cutover_write',
    ];
    if (!in_array($operation, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid evidence metadata.');
    }
    if ($this->database->select('agency_migrate_outbox', 'o')
      ->condition('fingerprint', $fingerprint)
      ->countQuery()
      ->execute()
      ->fetchField()) {
      $existing = $this->database->select('agency_migrate_outbox', 'o')
        ->fields('o', ['id'])
        ->condition('fingerprint', $fingerprint)
        ->execute()
        ->fetchField();
      return (string) $existing;
    }
    $id = $this->uuid->generate();
    $now = $this->time->getCurrentTime();
    $metadata['event_id'] = $id;
    $metadata['effective_at'] = $now;
    $this->database->insert('agency_migrate_outbox')->fields([
      'id' => $id,
      'operation' => $operation,
      'fingerprint' => $fingerprint,
      'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
      'recorded' => $now,
      'delivered' => NULL,
    ])->execute();
    return $id;
  }

  /**
   * Delivers queued rows through Audit Chain's public logKeyed() contract.
   */
  public function drain(int $limit = 20): int {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Drain only after the domain transaction commits.');
    }
    $delivered = 0;
    for ($i = 0; $i < max(0, min(1000, $limit)); $i++) {
      $transaction = $this->database->startTransaction();
      try {
        $claim = $this->database->select('agency_migrate_outbox', 'o')
          ->fields('o')
          ->isNull('delivered')
          ->orderBy('recorded')
          ->orderBy('id')
          ->range(0, 1);
        $claim->forUpdate();
        $row = $claim->execute()->fetchAssoc();
        if (!$row) {
          TransactionBoundary::commit($transaction);
          break;
        }
        $now = $this->time->getCurrentTime();
        $this->database->update('agency_migrate_outbox')->fields([
          'last_attempt_at' => $now,
        ])->expression('attempts', 'attempts + 1')->condition('id', $row['id'])->execute();
        $append = $this->database->startTransaction();
        try {
          $this->logger->logKeyed(
            'agency_migrate',
            $row['operation'],
            json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR),
          );
          $this->database->update('agency_migrate_outbox')->fields([
            'delivered' => $this->time->getCurrentTime(),
          ])->condition('id', $row['id'])->execute();
          TransactionBoundary::commit($append);
        }
        catch (\Throwable) {
          $append?->rollBack();
          unset($append);
          $this->database->update('agency_migrate_outbox')->fields([
            'last_error_at' => $this->time->getCurrentTime(),
          ])->condition('id', $row['id'])->execute();
          TransactionBoundary::commit($transaction);
          break;
        }
        TransactionBoundary::commit($transaction);
        $delivered++;
      }
      catch (\Throwable) {
        try {
          $transaction?->rollBack();
        }
        catch (\Throwable) {
          // The row remains the source for reconciliation after reconnect.
        }
        break;
      }
    }
    return $delivered;
  }

}
