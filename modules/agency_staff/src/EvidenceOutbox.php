<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * Owns durable integration state; delegates all chain integrity to Audit Chain.
 */
final class EvidenceOutbox {

  public function __construct(
    private readonly Connection $database,
    private readonly AuditChainLoggerInterface $logger,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records required evidence inside the domain transaction or throws.
   */
  public function recordRequired(string $operation, string $relationship, string $actorHash, string $state, string $waitingOn, bool $denial = FALSE): string {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Evidence must share a domain transaction.');
    }
    if (!in_array($operation, [
      'startDiscovery',
      'issue',
      'resend',
      'accept',
      'setNextAction',
      'respond',
      'pauseRelationship',
      'resumeRelationship',
      'withdrawRelationship',
      'revokeInvitation',
      'suspendAccess',
      'close',
      'moveForward',
    ], TRUE)
      || !in_array($state, ['invited', 'exploring', 'prepared', 'paused', 'withdrawn', 'closed', 'moved_forward'], TRUE)
      || !in_array($waitingOn, ['agency', 'participant'], TRUE)
      || !preg_match('/^[a-f0-9]{64}$/', $actorHash)
      || !preg_match('/^[a-f0-9-]{36}$/', $relationship)) {
      throw new \InvalidArgumentException('Invalid evidence metadata.');
    }
    $id = $this->uuid->generate();
    $now = $this->time->getCurrentTime();
    $metadata = [
      'event_id' => $id,
      'relationship_id' => $relationship,
      'subject_hash' => $actorHash,
      'state' => $state,
      'waiting_on' => $waitingOn,
      'effective_at' => $now,
    ];
    $this->database->insert('agency_staff_outbox')->fields([
      'id' => $id,
      'relationship_uuid' => $relationship,
      'operation' => $operation,
      'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
      'denial' => (int) $denial,
      'recorded' => $now,
      'delivered' => NULL,
    ])->execute();
    return $id;
  }

  /**
   * Records a minimized observation without any caller-supplied identifier.
   */
  public function recordObservation(string $reason, string $actorHash): void {
    if (!$this->database->inTransaction() || !in_array($reason, [
      'not_found',
      'identity_unavailable',
      'recording_unavailable',
    ], TRUE)
      || !preg_match('/^[a-f0-9]{64}$/', $actorHash)) {
      throw new \InvalidArgumentException('Invalid observation boundary.');
    }
    $id = $this->uuid->generate();
    $now = $this->time->getCurrentTime();
    $this->database->insert('agency_staff_outbox')->fields([
      'id' => $id,
      'relationship_uuid' => '',
      'operation' => 'access_observation',
      'metadata' => json_encode([
        'event_id' => $id,
        'reason' => $reason,
        'subject_hash' => $actorHash,
        'observed_at' => $now,
      ], JSON_THROW_ON_ERROR),
      'denial' => 0,
      'recorded' => $now,
      'delivered' => NULL,
    ])->execute();
  }

  /**
   * Delivers queued rows atomically with the shared logger's local append.
   */
  public function drain(int $limit = 20): int {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Drain only after the domain transaction commits.');
    }
    $delivered = 0;
    for ($i = 0; $i < max(0, min(1000, $limit)); $i++) {
      $transaction = $this->database->startTransaction();
      try {
        $claim = $this->database->select('agency_staff_outbox', 'o')->fields('o')->isNull('delivered')->orderBy('recorded')->orderBy('id')->range(0, 1);
        $claim->forUpdate();
        $row = $claim->execute()->fetchAssoc();
        if (!$row) {
          $transaction->commitOrRelease();
          break;
        }
        // A competing drainer may have committed while this SELECT waited.
        $recheck = $this->database->select('agency_staff_outbox', 'o')->fields('o')->condition('id', $row['id']);
        $recheck->forUpdate();
        $fresh = $recheck->execute()->fetchAssoc();
        if ($fresh['delivered'] !== NULL) {
          $transaction->commitOrRelease();
          continue;
        }
        $this->logger->logKeyed('agency_staff', $row['operation'], json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR));
        $this->database->update('agency_staff_outbox')->fields([
          'delivered' => $this->time->getCurrentTime(),
        ])->condition('id', $row['id'])->execute();
        $transaction->commitOrRelease();
        $delivered++;
      }
      catch (\Throwable) {
        try {
          $transaction->rollBack();
        }
        catch (\Throwable) {
          // The row remains the source for reconciliation after reconnect.
        }
        break;
      }
    }
    return $delivered;
  }

  /**
   * Returns only an operator-safe pending flag, not raw evidence.
   */
  public function pending(string $relationship): bool {
    return (bool) $this->database->select('agency_staff_outbox', 'o')->condition('relationship_uuid', $relationship)->isNull('delivered')->countQuery()->execute()->fetchField();
  }

}
