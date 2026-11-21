<?php

declare(strict_types=1);

namespace Drupal\agency_campaign;

use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * Durable minimized campaign intents; Audit Chain owns chain integrity.
 */
final class CampaignOutbox {

  /**
   * Evidence fields allowed in campaign metadata.
   */
  private const FIELDS = [
    'event_id',
    'campaign_id',
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
   * @param string $campaign
   *   The campaign UUID.
   * @param string $actorHash
   *   SHA-256 of the coordinator actor.
   * @param string $state
   *   The resulting campaign state.
   *
   * @return string
   *   The event UUID.
   */
  public function recordRequired(
    string $campaign,
    string $actorHash,
    string $state,
  ): string {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Evidence must share a domain transaction.');
    }
    if (!in_array($state, ['draft', 'active'], TRUE)
      || !preg_match('/^[a-f0-9]{64}$/', $actorHash)
      || !preg_match('/^[a-f0-9-]{36}$/', $campaign)) {
      throw new \InvalidArgumentException('Invalid evidence metadata.');
    }
    $id = $this->uuid->generate();
    $now = $this->time->getCurrentTime();
    $metadata = [
      'event_id' => $id,
      'campaign_id' => $campaign,
      'subject_hash' => $actorHash,
      'state' => $state,
      'effective_at' => $now,
    ];
    $this->assertMinimized($metadata);
    $this->database->insert('agency_campaign_outbox')->fields([
      'id' => $id,
      'campaign_uuid' => $campaign,
      'operation' => 'launchCampaign',
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
      throw new \LogicException('Drain only after the domain transaction commits.');
    }
    $delivered = 0;
    $committer = new TransactionCommitter();
    for ($i = 0; $i < max(0, min(1000, $limit)); $i++) {
      $transaction = $this->database->startTransaction();
      try {
        $claim = $this->database->select('agency_campaign_outbox', 'o')
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
        $this->database->update('agency_campaign_outbox')
          ->expression('attempts', 'attempts + 1')
          ->condition('id', $row['id'])
          ->execute();
        $append = $this->database->startTransaction();
        try {
          $this->logger->logKeyed(
            'agency_campaign',
            $row['operation'],
            json_decode($row['metadata'], TRUE, 32, JSON_THROW_ON_ERROR),
          );
          $this->database->update('agency_campaign_outbox')->fields([
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
   * Refuses metadata that names a forbidden or unknown field.
   *
   * @param array<string, mixed> $metadata
   *   Candidate evidence.
   */
  private function assertMinimized(array $metadata): void {
    $forbidden = [
      'contact',
      'note',
      'token',
      'credential',
      'file',
      'guessed_identifier',
      'title',
    ];
    foreach (array_keys($metadata) as $field) {
      if (!in_array($field, self::FIELDS, TRUE)
        || in_array($field, $forbidden, TRUE)) {
        throw new \InvalidArgumentException('Invalid evidence metadata.');
      }
    }
  }

}
