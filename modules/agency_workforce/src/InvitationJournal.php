<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;

/**
 * Local operation receipts and required, minimized workforce evidence intents.
 */
final class InvitationJournal {

  public function __construct(
    private readonly Connection $database,
    private readonly AuditChainLoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records the recovery receipt in the business transaction.
   */
  public function record(string $id, string $scope, InvitationRecord $record, string $operation, ?string $binding = NULL): void {
    if (!$this->database->inTransaction()
      || !in_array($operation, ['invitation_issued', 'invitation_accepted'], TRUE)) {
      throw new \LogicException('A workforce operation transaction is required.');
    }
    $this->database->insert('agency_workforce_receipt')->fields([
      'id' => $id,
      'scope' => $scope,
      'collection' => $record->collection,
      'digest' => $record->digest,
      'operation' => $operation,
      'expires' => $record->expires,
      'binding' => $binding,
      'recorded' => $this->time->getCurrentTime(),
    ])->execute();
  }

  /**
   * Records a completed invitation stage inside the business transaction.
   */
  public function recordStage(string $id, string $operation, InvitationRecord $record): void {
    if (!$this->database->inTransaction()
      || !in_array($operation, ['invite.issued', 'invite.claimed', 'invite.provisioned', 'invite.consumed'], TRUE)) {
      throw new \LogicException('A workforce stage transaction is required.');
    }
    $this->enqueue($id, $operation, ['classification' => $record->classification]);
  }

  /**
   * Records an application refusal outside any business transaction.
   */
  public function recordRefusal(string $id): void {
    if ($this->database->inTransaction()) {
      throw new \LogicException('An application refusal requires an independent write.');
    }
    $this->enqueue($id, 'invite.refused', ['reason' => 'not_found']);
  }

  /**
   * Queues one operation with the supplied allowlisted metadata.
   *
   * @param string $id
   *   The unique event UUID.
   * @param string $operation
   *   The evidence operation name.
   * @param array<string, string> $metadata
   *   The operation's fixed metadata.
   */
  private function enqueue(string $id, string $operation, array $metadata): void {
    if (!Uuid::isValid($id)) {
      throw new \LogicException('A valid workforce event ID is required.');
    }
    $now = $this->time->getCurrentTime();
    $this->database->insert('agency_workforce_outbox')->fields([
      'id' => $id,
      'operation' => $operation,
      'metadata' => json_encode(['event_id' => $id, 'effective_at' => $now] + $metadata, JSON_THROW_ON_ERROR),
      'recorded' => $now,
      'delivered' => NULL,
      'attempts' => 0,
    ])->execute();
  }

  /**
   * Reads committed operation state; callers separately authorize disclosure.
   *
   * @return array<string, mixed>|null
   *   The local receipt, or NULL.
   */
  public function byId(string $id, string $scope, string $collection): ?array {
    $row = $this->database->select('agency_workforce_receipt', 'r')->fields('r')
      ->condition('id', $id)->condition('scope', $scope)->condition('collection', $collection)
      ->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Finds an acceptance when the caller lost the entire response.
   *
   * @return array<string, mixed>|null
   *   The acceptance receipt, or NULL. Never provisions on a miss.
   */
  public function byDigest(string $digest, string $scope, string $collection): ?array {
    $row = $this->database->select('agency_workforce_receipt', 'r')->fields('r')
      ->condition('digest', $digest)->condition('scope', $scope)->condition('collection', $collection)
      ->condition('operation', 'invitation_accepted')->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Appends through Audit Chain's public contract after business commit.
   *
   * The append and delivery marker share one transaction. Row locks serialize
   * drainers, so retries cannot append the same committed intent twice.
   */
  public function drain(int $limit = 20): int {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Drain only after the root business transaction.');
    }
    $count = 0;
    $committer = new RootTransactionCommitter();
    for ($i = 0; $i < max(0, min(1000, $limit)); $i++) {
      $transaction = $this->database->startTransaction();
      try {
        $query = $this->database->select('agency_workforce_outbox', 'o')->fields('o')
          ->isNull('delivered')->orderBy('sequence')->range(0, 1);
        $query->forUpdate();
        $row = $query->execute()->fetchAssoc();
        if (!$row) {
          $committer->commit($transaction);
          unset($transaction);
          break;
        }
        $this->database->update('agency_workforce_outbox')
          ->expression('attempts', 'attempts + 1')->condition('id', $row['id'])->execute();
        $append = $this->database->startTransaction();
        try {
          $this->logger->logKeyed('agency_workforce', $row['operation'], json_decode($row['metadata'], TRUE, 32, JSON_THROW_ON_ERROR));
          $this->database->update('agency_workforce_outbox')->fields([
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
        $count++;
      }
      catch (\Throwable) {
        try {
          $transaction?->rollBack();
        }
        catch (\Throwable) {
          // Reconcile the durable delivery marker after reconnecting.
        }
        finally {
          unset($transaction);
        }
        break;
      }
    }
    return $count;
  }

}
