<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reads complete journal evidence and retains committed handover records.
 *
 * @internal
 */
final class RollbackReplayStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly IdentityPreservingWriter $history,
  ) {}

  /**
   * Matches every retained operation intent to a journal entry in both ways.
   *
   * @param string $domain
   *   Qualified domain identifier.
   * @param int $generation
   *   Current Agency generation.
   * @param array<string, \Drupal\agency_migrate\Plugin\CopierInterface> $targets
   *   Confirmed domain copiers, keyed by their target entity type.
   *
   * @return array<int, array<string, mixed>>
   *   Current unreplayed operations indexed in journal order by write id.
   */
  public function pending(string $domain, int $generation, array $targets): array {
    $rows = $this->database->select('agency_migrate_write_log', 'w')->fields('w')
      ->condition('domain', $domain)->orderBy('wid')->execute();
    $pending = [];
    $expected_intents = [];
    $historical = [];
    while ($row = $rows->fetchAssoc()) {
      $copier = $targets[$row['entity_type']] ?? NULL;
      if ($copier === NULL) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if (($row['envelope_version'] ?? NULL) === NULL) {
        // Preserve the identity-conflict reason for old four-argument rows.
        $entity = $copier->loadTarget((int) $row['entity_id']);
        if ($entity === NULL || (int) $entity->id() !== (int) $row['entity_id']
          || $entity->getEntityTypeId() !== $row['entity_type'] || $entity->uuid() !== $row['entity_uuid']) {
          throw new MigrateRefusal('rollback_conflict');
        }
        throw new MigrateRefusal('rollback_unqualified');
      }
      try {
        $envelope = PostCutoverOperationEnvelope::decode($row, $this->history);
      }
      catch (MigrateRefusal) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $wid = (int) $row['wid'];
      $fingerprint = hash('sha256', 'operation:' . $domain . ':' . $envelope['operation_id']);
      if ($wid < 1 || isset($expected_intents[$fingerprint])) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $expected_intents[$fingerprint] = [
        'domain' => $domain,
        'generation' => $envelope['generation'],
        'operation_id' => $envelope['operation_id'],
        'kind' => $envelope['kind'],
      ];
      if ($envelope['generation'] === $generation && $row['replayed'] === NULL) {
        $pending[$wid] = $envelope;
      }
      elseif ($envelope['generation'] < $generation && $row['replayed'] !== NULL) {
        $receipt = $this->receipt($domain, $envelope['generation']);
        if ($receipt === NULL || $receipt['recorded'] !== (int) $row['replayed']) {
          throw new MigrateRefusal('rollback_unqualified');
        }
        $historical[$envelope['generation']][$wid] = $envelope;
      }
      else {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    $receipts = $this->database->select('agency_migrate_rollback_receipt', 'r')->fields('r')
      ->condition('domain', $domain)->execute();
    while ($receipt = $receipts->fetchAssoc()) {
      $old_generation = (int) $receipt['generation'];
      $operations = $historical[$old_generation] ?? [];
      if ($old_generation < 1 || $old_generation >= $generation
        || $receipt['journal_digest'] !== self::digest($operations)
        || (int) $receipt['replayed'] !== count($operations)) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    $this->matchIntents($domain, $expected_intents);
    return $pending;
  }

  /**
   * Includes delivered intents and refuses mismatched or unpaired operations.
   */
  private function matchIntents(string $domain, array $expected): void {
    $intents = $this->database->select('agency_migrate_outbox', 'o')->fields('o', ['id', 'fingerprint', 'metadata'])
      ->condition('operation', 'post_cutover_write')->execute();
    while ($intent = $intents->fetchAssoc()) {
      try {
        $metadata = json_decode($intent['metadata'], TRUE, 16, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if (!is_string($metadata['domain'] ?? NULL)) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $fingerprint = $intent['fingerprint'];
      if ($metadata['domain'] !== $domain && !isset($expected[$fingerprint])) {
        continue;
      }
      if (!isset($expected[$fingerprint]) || ($metadata['event_id'] ?? NULL) !== $intent['id']
        || !is_int($metadata['effective_at'] ?? NULL)) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      unset($metadata['event_id'], $metadata['effective_at']);
      $expected_metadata = $expected[$fingerprint];
      ksort($metadata);
      ksort($expected_metadata);
      if ($metadata !== $expected_metadata) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      unset($expected[$fingerprint]);
    }
    if ($expected !== []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
  }

  /**
   * Loads an immutable handover receipt for an explicit Agency generation.
   */
  public function receipt(string $domain, int $generation): ?array {
    $row = $this->database->select('agency_migrate_rollback_receipt', 'r')->fields('r')
      ->condition('domain', $domain)->condition('generation', $generation)->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    foreach (['generation', 'replayed', 'recorded'] as $field) {
      $row[$field] = (int) $row[$field];
    }
    return $row;
  }

  /**
   * Appends immutable provenance, CAS marks all operations, seals receipt.
   *
   * @param string $domain
   *   Qualified domain identifier.
   * @param int $generation
   *   Agency generation being retired.
   * @param string $reason
   *   Original operator reason for exact retry matching.
   * @param array<int, array<string, mixed>> $operations
   *   Verified operations keyed by journal write id in their replay order.
   * @param array<string, array<int, array<string, mixed>>> $changes
   *   Verified per-copier identity chains, indexed by entity id.
   */
  public function seal(string $domain, int $generation, string $reason, array $operations, array $changes): array {
    if (!$this->database->inTransaction()) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $recorded = $this->time->getCurrentTime();
    foreach ($changes as $copier => $records) {
      foreach ($records as $id => $record) {
        $last = $operations[$record['last_wid']];
        $values = [
          'domain' => $domain,
          'generation' => $generation,
          'copier' => $copier,
          'entity_id' => $id,
          'entity_uuid' => $record['uuid'],
          'origin' => $record['lineage_id'] === NULL ? 'create' : 'copy',
          'lineage_id' => $record['lineage_id'],
          'first_wid' => $record['first_wid'],
          'last_wid' => $record['last_wid'],
          'delete_wid' => $last['kind'] === 'delete' ? $record['last_wid'] : NULL,
          'history_digest' => self::digest($last['after'] ?? $last['before']),
        ];
        $this->database->insert('agency_migrate_rollback_record')->fields($values)->execute();
        $persisted = $this->database->select('agency_migrate_rollback_record', 'r')->fields('r')
          ->condition('domain', $domain)->condition('generation', $generation)
          ->condition('copier', $copier)->condition('entity_id', $id)->execute()->fetchAssoc();
        if (!$persisted) {
          throw new MigrateRefusal('rollback_unqualified');
        }
        foreach (['generation', 'entity_id', 'lineage_id', 'first_wid', 'last_wid', 'delete_wid'] as $field) {
          if ($persisted[$field] !== NULL) {
            $persisted[$field] = (int) $persisted[$field];
          }
        }
        if ($persisted !== $values) {
          throw new MigrateRefusal('rollback_unqualified');
        }
      }
    }
    foreach ($operations as $wid => $operation) {
      $updated = $this->database->update('agency_migrate_write_log')->fields(['replayed' => $recorded])
        ->condition('wid', $wid)->condition('domain', $domain)->condition('authority_generation', $generation)
        ->condition('operation_id', $operation['operation_id'])->isNull('replayed')->execute();
      if ($updated !== 1) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $row = $this->database->select('agency_migrate_write_log', 'w')->fields('w')
        ->condition('wid', $wid)->execute()->fetchAssoc();
      if (!$row || $row['replayed'] === NULL || (int) $row['replayed'] !== $recorded
        || PostCutoverOperationEnvelope::decode($row, $this->history) !== $operation) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    $receipt = [
      'domain' => $domain,
      'generation' => $generation,
      'reason_hash' => hash('sha256', trim($reason)),
      'journal_digest' => self::digest($operations),
      'replayed' => count($operations),
      'recorded' => $recorded,
    ];
    $this->database->insert('agency_migrate_rollback_receipt')->fields($receipt)->execute();
    if ($this->receipt($domain, $generation) !== $receipt) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $receipt;
  }

  /**
   * Fingerprints the ordered, typed private values without exposing them.
   */
  private static function digest(array $values): string {
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
  }

}
