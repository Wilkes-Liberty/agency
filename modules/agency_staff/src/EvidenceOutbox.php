<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Storage\TransactionBoundary;
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
          TransactionBoundary::commit($transaction);
          break;
        }
        // A competing drainer may have committed while this SELECT waited.
        $recheck = $this->database->select('agency_staff_outbox', 'o')->fields('o')->condition('id', $row['id']);
        $recheck->forUpdate();
        $fresh = $recheck->execute()->fetchAssoc();
        if ($fresh['delivered'] !== NULL) {
          TransactionBoundary::commit($transaction);
          continue;
        }
        // Keep attempt state outside the append savepoint, under the row lock.
        // Failed appends must roll back on PostgreSQL before recording failure.
        $now = $this->time->getCurrentTime();
        $this->database->update('agency_staff_outbox')->fields([
          'last_attempt_at' => $now,
        ])->expression('attempts', 'attempts + 1')->condition('id', $row['id'])->execute();
        $append = $this->database->startTransaction();
        try {
          $this->logger->logKeyed('agency_staff', $row['operation'], json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR));
          $this->database->update('agency_staff_outbox')->fields([
            'delivered' => $this->time->getCurrentTime(),
          ])->condition('id', $row['id'])->execute();
          TransactionBoundary::commit($append);
        }
        catch (\Throwable) {
          $append?->rollBack();
          unset($append);
          $this->database->update('agency_staff_outbox')->fields([
            'last_error_at' => $now,
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

  /**
   * Returns minimized delivery health for trusted operational callers only.
   *
   * The host authorizes monitoring; this service exposes no public route. The
   * threshold is a caller-supplied positive number of seconds. Database errors
   * propagate: unavailable telemetry must never be reported as a healthy queue.
   * Attempts count durable transactions since this feature was installed, not
   * unobservable process interruptions or pre-upgrade activity.
   */
  public function health(int $oldestUndeliveredAlertAfter): array {
    if ($oldestUndeliveredAlertAfter <= 0) {
      throw new \InvalidArgumentException('An explicit positive alert threshold is required.');
    }
    if ($this->database->inTransaction()) {
      throw new \LogicException('Read delivery health outside a domain transaction.');
    }
    // One statement gives a consistent aggregate, without loading intent data.
    $query = $this->database->select('agency_staff_outbox', 'o');
    $query->addExpression('SUM(CASE WHEN delivered IS NULL THEN 1 ELSE 0 END)', 'pending_count');
    $query->addExpression('SUM(attempts)', 'attempts');
    $query->addExpression('SUM(CASE WHEN delivered IS NULL THEN attempts ELSE 0 END)', 'pending_attempts');
    $query->addExpression('MAX(last_attempt_at)', 'last_attempt_at');
    $query->addExpression('MAX(last_error_at)', 'last_error_at');
    $query->addExpression('MIN(CASE WHEN delivered IS NULL THEN recorded ELSE NULL END)', 'oldest_pending_at');
    $query->addExpression('SUM(CASE WHEN delivered IS NULL AND last_error_at IS NOT NULL THEN 1 ELSE 0 END)', 'failed_pending');
    $row = $query->execute()->fetchAssoc();
    $oldest = $row['oldest_pending_at'] === NULL ? NULL : (int) $row['oldest_pending_at'];
    $age = $oldest === NULL ? NULL : max(0, $this->time->getCurrentTime() - $oldest);
    $alert = $age !== NULL && $age >= $oldestUndeliveredAlertAfter;
    return [
      'pending_count' => (int) $row['pending_count'],
      'attempts' => (int) $row['attempts'],
      'pending_attempts' => (int) $row['pending_attempts'],
      'last_attempt_at' => $row['last_attempt_at'] === NULL ? NULL : (int) $row['last_attempt_at'],
      'last_error' => $row['last_error_at'] === NULL ? NULL : 'evidence_delivery_failed',
      'last_error_at' => $row['last_error_at'] === NULL ? NULL : (int) $row['last_error_at'],
      'oldest_pending_at' => $oldest,
      'oldest_pending_age_seconds' => $age,
      'oldest_undelivered_alert' => $alert,
      'degraded' => $alert || (int) $row['failed_pending'] > 0,
    ];
  }

  /**
   * Returns only an operator-safe pending flag, not raw evidence.
   */
  public function pending(string $relationship): bool {
    return (bool) $this->database->select('agency_staff_outbox', 'o')->condition('relationship_uuid', $relationship)->isNull('delivered')->countQuery()->execute()->fetchField();
  }

}
