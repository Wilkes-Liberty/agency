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
    private readonly OperationJournalQualification $qualification,
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
    $has_legacy_rows = FALSE;
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
        $has_legacy_rows = TRUE;
        continue;
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
        $this->assertCurrentQualification($domain, $envelope);
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
    if ($has_legacy_rows) {
      throw new MigrateRefusal('rollback_unqualified');
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
   * Requalifies both private snapshot sides under the current host contract.
   */
  private function assertCurrentQualification(string $domain, array $envelope): void {
    foreach (['before', 'after'] as $side) {
      $snapshot = $envelope[$side];
      if ($snapshot !== NULL && !hash_equals($envelope['qualification'], $this->qualification->assertSnapshot($domain, $envelope['entity_type'], $snapshot))) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
  }

  /**
   * Includes delivered intents and refuses mismatched or unpaired operations.
   */
  private function matchIntents(string $domain, array $expected, ?int $generation = NULL): void {
    $intents = $this->database->select('agency_migrate_outbox', 'o')
      ->fields('o', ['id', 'fingerprint', 'metadata', 'recorded'])
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
      if (!isset($expected[$fingerprint]) && ($metadata['domain'] !== $domain
        || ($generation !== NULL && ($metadata['generation'] ?? NULL) !== $generation))) {
        continue;
      }
      if (!isset($expected[$fingerprint]) || ($metadata['event_id'] ?? NULL) !== $intent['id']
        || ($metadata['effective_at'] ?? NULL) !== (int) $intent['recorded']) {
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
   * Reads the sealed effective history for one domain, including tombstones.
   *
   * @return array<string, array<int, array<string, mixed>>>
   *   Verified latest provenance per copier and integer identity.
   */
  public function effectiveDomain(string $domain): array {
    $records = $this->database->select('agency_migrate_rollback_record', 'r')->fields('r')
      ->condition('domain', $domain)->orderBy('generation')->orderBy('copier')->orderBy('entity_id')->execute();
    $by_generation = [];
    while ($record = $records->fetchAssoc()) {
      $normalized = self::normalizeRecord($record);
      $by_generation[$normalized['generation']][] = $normalized;
    }
    $receipts = $this->database->select('agency_migrate_rollback_receipt', 'r')->fields('r')
      ->condition('domain', $domain)->orderBy('generation')->execute();
    $effective = [];
    $authority = NULL;
    while ($receipt = $receipts->fetchAssoc()) {
      $generation = (int) $receipt['generation'];
      $authority ??= (int) $this->database->select('agency_migrate_authority_state', 'a')->fields('a', ['version'])
        ->condition('domain', $domain)->execute()->fetchField();
      $provenance = $by_generation[$generation] ?? [];
      unset($by_generation[$generation]);
      if ($generation < 1 || $authority <= $generation || self::provenanceDigest($provenance) !== $receipt['provenance_digest']) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $operations = $this->sealedOperations($domain, $generation, $receipt);
      $chains = [];
      foreach ($operations as $wid => $operation) {
        $type = $operation['entity_type'];
        $id = $operation['id'];
        $chains[$type][$id][0] ??= $wid;
        $chains[$type][$id][1] = $wid;
      }
      foreach ($provenance as $record) {
        $plugin = $record['copier'];
        $id = $record['entity_id'];
        $type = $record['target_type'];
        $first = $record['first_wid'];
        $last = $record['last_wid'];
        if (($chains[$type][$id] ?? NULL) !== [$first, $last]) {
          throw new MigrateRefusal('rollback_unqualified');
        }
        unset($chains[$type][$id]);
        $previous = $effective[$plugin][$id] ?? NULL;
        $effective[$plugin][$id] = $this->sealedRecord($record, $operations[$first], $operations[$last], $previous);
      }
      foreach ($chains as $uncovered) {
        if ($uncovered !== []) {
          throw new MigrateRefusal('rollback_unqualified');
        }
      }
    }
    if ($by_generation !== []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $effective;
  }

  /**
   * Verifies the complete historical generation against its committed receipt.
   */
  private function sealedOperations(string $domain, int $generation, array $receipt): array {
    $rows = $this->database->select('agency_migrate_write_log', 'w')->fields('w')
      ->condition('domain', $domain)->condition('authority_generation', $generation)->orderBy('wid')->execute();
    $operations = $expected = [];
    while ($row = $rows->fetchAssoc()) {
      $operation = PostCutoverOperationEnvelope::decode($row, $this->history);
      if ($row['replayed'] === NULL || (int) $row['replayed'] !== (int) $receipt['recorded']) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $operations[(int) $row['wid']] = $operation;
      $fingerprint = hash('sha256', 'operation:' . $domain . ':' . $operation['operation_id']);
      $keys = array_flip(['domain', 'generation', 'operation_id', 'kind']);
      $expected[$fingerprint] = array_intersect_key($operation, $keys);
    }
    if (count($operations) !== (int) $receipt['replayed'] || self::digest($operations) !== $receipt['journal_digest']) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $this->matchIntents($domain, $expected, $generation);
    return $operations;
  }

  /**
   * Resolves a sealed row to its exact terminal history or retained preimage.
   */
  private function sealedRecord(array $record, array $first, array $last, ?array $previous): array {
    $snapshot = $last['after'];
    $history = $snapshot ?? $last['before'];
    if ($first['uuid'] !== $record['entity_uuid'] || $last['uuid'] !== $record['entity_uuid']
      || self::digest($history) !== $record['history_digest']
      || $record['delete_wid'] !== ($snapshot === NULL ? $record['last_wid'] : NULL)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $origin_keys = array_flip([
      'source_type', 'target_type', 'entity_uuid', 'origin',
      'lineage_id', 'lineage_digest', 'origin_wid',
    ]);
    if ($previous !== NULL) {
      if ($previous['snapshot'] === NULL || $first['before'] !== $previous['snapshot']
        || array_intersect_key($record, $origin_keys) !== array_intersect_key($previous, $origin_keys)) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    elseif ($record['origin'] === 'create') {
      if ($first['kind'] !== 'create' || $record['origin_wid'] !== $record['first_wid']
        || $record['lineage_id'] !== NULL || $record['lineage_digest'] !== NULL) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
    elseif ($record['origin'] !== 'copy' || $first['before'] === NULL || $record['lineage_id'] === NULL
      || $record['lineage_digest'] === NULL || $record['origin_wid'] !== NULL) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $record + ['snapshot' => $snapshot, 'preimage' => $snapshot === NULL ? $history : NULL];
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
   * Reads the receipt and resulting authority in one committed snapshot.
   */
  public function committedReceipt(string $domain, int $generation, string $reason): ?array {
    $query = $this->database->select('agency_migrate_rollback_receipt', 'r');
    $query->innerJoin('agency_migrate_authority_state', 'a', 'a.domain = r.domain');
    $row = $query->fields('r')->condition('r.domain', $domain)
      ->condition('r.generation', $generation)->condition('r.reason_hash', hash('sha256', trim($reason)))
      ->condition('a.state', 'legacy')->condition('a.version', $generation + 1)->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['generation'] = (int) $row['generation'];
    $row['replayed'] = (int) $row['replayed'];
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
    $provenance = [];
    foreach ($changes as $copier => $records) {
      foreach ($records as $id => $record) {
        $last = $operations[$record['last_wid']];
        $values = [
          'domain' => $domain,
          'generation' => $generation,
          'copier' => $copier,
          'source_type' => $record['source_type'],
          'target_type' => $record['target_type'],
          'entity_id' => $id,
          'entity_uuid' => $record['uuid'],
          'origin' => $record['lineage_id'] === NULL ? 'create' : 'copy',
          'lineage_id' => $record['lineage_id'],
          'lineage_digest' => $record['lineage_digest'],
          'origin_wid' => $record['origin_wid'],
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
        if (self::normalizeRecord($persisted) !== $values) {
          throw new MigrateRefusal('rollback_unqualified');
        }
        $provenance[] = $values;
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
      $this->assertCurrentQualification($domain, $operation);
    }
    $receipt = [
      'domain' => $domain,
      'generation' => $generation,
      'reason_hash' => hash('sha256', trim($reason)),
      'journal_digest' => self::digest($operations),
      'provenance_digest' => self::provenanceDigest($provenance),
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
   * Normalizes SQL numerics without conflating NULL with zero.
   */
  private static function normalizeRecord(array $record): array {
    foreach (['generation', 'entity_id', 'lineage_id', 'origin_wid', 'first_wid', 'last_wid', 'delete_wid'] as $field) {
      if ($record[$field] !== NULL) {
        $record[$field] = (int) $record[$field];
      }
    }
    return $record;
  }

  /**
   * Hashes all provenance in a stable copier and identity order.
   */
  private static function provenanceDigest(array $records): string {
    usort($records, static fn (array $left, array $right): int =>
      [$left['copier'], $left['entity_id']] <=> [$right['copier'], $right['entity_id']]);
    return self::digest($records);
  }

  /**
   * Fingerprints the ordered, typed private values without exposing them.
   */
  private static function digest(array $values): string {
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
  }

}
