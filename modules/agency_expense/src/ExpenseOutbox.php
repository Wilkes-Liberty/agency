<?php

declare(strict_types=1);

namespace Drupal\agency_expense;

use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * Durable minimized expense intents; Audit Chain owns chain integrity.
 */
final class ExpenseOutbox {

  /**
   * Evidence fields allowed in expense metadata.
   */
  private const FIELDS = [
    'event_id',
    'expense_id',
    'subject_hash',
    'state',
    'effective_at',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly AuditChainLoggerInterface $logger,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records required evidence inside the domain transaction or throws.
   *
   * @param string $expense
   *   The expense UUID.
   * @param string $actorHash
   *   SHA-256 of the coordinator actor.
   * @param string $state
   *   The resulting expense state.
   *
   * @return string
   *   The event UUID.
   */
  public function recordRequired(
    string $expense,
    string $actorHash,
    string $state,
  ): string {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Evidence must share a domain transaction.');
    }
    if (!in_array($state, ['drafted'], TRUE)
      || !preg_match('/^[a-f0-9]{64}$/', $actorHash)
      || !preg_match('/^[a-f0-9-]{36}$/', $expense)) {
      throw new \InvalidArgumentException('Invalid evidence metadata.');
    }
    $id = $this->uuid->generate();
    $now = $this->time->getCurrentTime();
    $metadata = [
      'event_id' => $id,
      'expense_id' => $expense,
      'subject_hash' => $actorHash,
      'state' => $state,
      'effective_at' => $now,
    ];
    $this->assertMinimized($metadata);
    $this->database->insert('agency_expense_outbox')->fields([
      'id' => $id,
      'expense_uuid' => $expense,
      'operation' => 'recordExpense',
      'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
      'recorded' => $now,
      'delivered' => NULL,
      'attempts' => 0,
    ])->execute();
    return $id;
  }

  /**
   * Delivers queued rows through Audit Chain's public logKeyed() contract.
   *
   * @param int $limit
   *   Maximum rows to claim.
   *
   * @return int
   *   Delivered count.
   */
  public function drain(int $limit = 20): int {
    if ($this->database->inTransaction()) {
      throw new \LogicException(
        'Drain only after the domain transaction commits.',
      );
    }
    $delivered = 0;
    $committer = new TransactionCommitter();
    for ($i = 0; $i < max(0, min(1000, $limit)); $i++) {
      $transaction = $this->database->startTransaction();
      try {
        $claim = $this->database->select('agency_expense_outbox', 'o')
          ->fields('o')
          ->isNull('delivered')
          ->orderBy('recorded')
          ->orderBy('id')
          ->range(0, 1);
        $claim->forUpdate();
        $row = $claim->execute()->fetchAssoc();
        if (!$row) {
          $committer->commit($transaction);
          unset($transaction);
          break;
        }
        $this->database->update('agency_expense_outbox')
          ->expression('attempts', 'attempts + 1')
          ->condition('id', $row['id'])
          ->execute();
        $append = $this->database->startTransaction();
        try {
          $this->logger->logKeyed(
            'agency_expense',
            $row['operation'],
            json_decode($row['metadata'], TRUE, 32, JSON_THROW_ON_ERROR),
          );
          $this->database->update('agency_expense_outbox')->fields([
            'delivered' => $this->time->getCurrentTime(),
          ])->condition('id', $row['id'])->execute();
          $committer->commit($append);
          unset($append);
        }
        catch (\Throwable) {
          try {
            $append?->rollBack();
          }
          finally {
            unset($append);
          }
          $committer->commit($transaction);
          unset($transaction);
          break;
        }
        $committer->commit($transaction);
        unset($transaction);
        $delivered++;
      }
      catch (\Throwable) {
        try {
          $transaction?->rollBack();
        }
        finally {
          unset($transaction);
        }
        break;
      }
    }
    return $delivered;
  }

  /**
   * Refuses metadata that names a field outside the allowlist.
   *
   * @param array<string, mixed> $metadata
   *   Candidate evidence.
   */
  private function assertMinimized(array $metadata): void {
    foreach (array_keys($metadata) as $field) {
      if (!in_array($field, self::FIELDS, TRUE)) {
        throw new \InvalidArgumentException('Invalid evidence metadata.');
      }
    }
  }

}
