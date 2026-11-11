<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_host\TransactionSupport;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Host-owned custody and attempt fences, never Agency-owned persistence.
 */
final class DeliveryLedger {

  public function __construct(
    private readonly Connection $database,
    private readonly DeliveryConfiguration $configuration,
    private readonly TimeInterface $time,
    private readonly DeliveryAuditInterface $audit,
  ) {}

  /**
   * Reserves an immutable command before entering an Agency root transaction.
   */
  public function reserve(string $id, string $kind, string $relationship, string $fingerprint, array $payload): array {
    $existing = $this->read($id);
    if ($existing !== NULL) {
      if (!hash_equals($existing['fingerprint'], $fingerprint)) {
        throw new Refusal('idempotency_conflict');
      }
      return $existing;
    }
    $config = $this->configuration->read();
    $row = [
      'id' => $id,
      'kind' => $kind,
      'relationship_id' => $relationship,
      'fingerprint' => $fingerprint,
      'state' => 'reserved',
      'revision' => 1,
      'created' => $this->time->getCurrentTime(),
      'expires' => $this->time->getCurrentTime() + $config['payload_retention_seconds'],
    ];
    $row['payload'] = $this->configuration->encrypt($payload, $this->context($row));
    $this->write(function () use ($row): void {
      $this->database->insert('agency_host_delivery')->fields($row)->execute();
    }, $id, 'reserved');
    return $row;
  }

  /**
   * Reads minimized metadata; decrypting custody is a separate explicit step.
   */
  public function read(string $id): ?array {
    $row = $this->database->select('agency_host_delivery', 'd')->fields('d')
      ->condition('id', $id)->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Finds only the host mapping created by a successful exact preparation.
   */
  public function mapping(string $relationship): ?array {
    $row = $this->database->select('agency_host_delivery', 'd')->fields('d')
      ->condition('kind', 'preparation')->condition('relationship_id', $relationship)
      ->condition('state', 'mapped')->range(0, 1)->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Lists minimized delivery records after the caller checks assignment.
   */
  public function listing(string $relationship): array {
    return $this->fetchAssociative($this->database->select('agency_host_delivery', 'd')
      ->fields('d', ['id', 'state', 'created'])
      ->condition('kind', 'invitation')->condition('relationship_id', $relationship)
      ->orderBy('created', 'DESC')->range(0, 50)->execute());
  }

  /**
   * Retains warning evidence independently of encrypted payload retention.
   */
  public function hasHandoff(string $relationship): bool {
    return (bool) $this->database->select('agency_host_delivery', 'd')->condition('kind', 'invitation')
      ->condition('relationship_id', $relationship)->condition('state', ['attempting', 'unknown', 'accepted'], 'IN')
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Never decrypts expired custody or falls back to plaintext.
   */
  public function payload(array $row): array {
    if ((int) $row['expires'] <= $this->time->getCurrentTime() || $row['payload'] === '') {
      throw new Refusal('delivery_unavailable');
    }
    return $this->configuration->decrypt($row['payload'], $this->context($row));
  }

  /**
   * Commits a compare-and-swap transition and its evidence in one transaction.
   */
  public function transition(array $row, string $state, array $payload, ?string $relationship = NULL): array {
    $allowed = [
      'reserved' => ['mapped', 'captured', 'capture_unavailable', 'refused'],
      'captured' => ['attempting'],
      'attempting' => ['test_captured', 'accepted', 'unknown'],
    ];
    if (!in_array($state, $allowed[$row['state']] ?? [], TRUE)) {
      throw new Refusal('delivery_conflict');
    }
    $next = $row;
    $next['relationship_id'] = $relationship ?? $row['relationship_id'];
    $next['state'] = $state;
    $next['revision'] = (int) $row['revision'] + 1;
    $next['payload'] = $this->configuration->encrypt($payload, $this->context($next));
    $this->write(function () use ($row, $next): void {
      $changed = $this->database->update('agency_host_delivery')->fields([
        'relationship_id' => $next['relationship_id'],
        'state' => $next['state'],
        'revision' => $next['revision'],
        'payload' => $next['payload'],
      ])->condition('id', $row['id'])->condition('revision', $row['revision'])
        ->condition('state', $row['state'])->condition('payload', $row['payload'])
        ->condition('expires', $this->time->getCurrentTime(), '>')->execute();
      if ($changed !== 1) {
        throw new Refusal('delivery_conflict');
      }
    }, $row['id'], $state);
    return $next;
  }

  /**
   * Expires sensitive payloads while retaining duplicate-suppression fences.
   */
  public function purge(): void {
    $this->database->update('agency_host_delivery')->fields(['payload' => ''])
      ->condition('expires', $this->time->getCurrentTime(), '<=')->condition('payload', '', '<>')->execute();
  }

  /**
   * Builds immutable authenticated data, including the mapped relationship.
   */
  private function context(array $row): string {
    return $row['id'] . ':' . $row['kind'] . ':' . $row['relationship_id'];
  }

  /**
   * Completes the host transaction before any domain command or transport call.
   */
  private function write(callable $write, string $id, string $state): void {
    if ($this->database->inTransaction()) {
      throw new Refusal('delivery_unavailable');
    }
    $transaction = $this->database->startTransaction();
    try {
      $write();
      $this->audit->record('agency.invitation_delivery', [
        'request_id' => $id,
        'after_state' => $state,
      ]);
      TransactionSupport::commit($transaction);
    }
    catch (\Throwable $error) {
      $transaction?->rollBack();
      throw $error;
    }
  }

  /**
   * Returns associative rows on Drupal 10 PDO modes and Drupal 11 FetchAs.
   */
  private function fetchAssociative(StatementInterface $statement): array {
    if (class_exists(FetchAs::class)) {
      return $statement->fetchAll(FetchAs::Associative);
    }
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
  }

}
