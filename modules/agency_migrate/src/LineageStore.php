<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Bidirectional map of copied source records to Agency records.
 */
final class LineageStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records one copied pair. Duplicate source or target ids are rejected.
   */
  public function record(
    string $run_id,
    ContentEntityInterface $source,
    ContentEntityInterface $target,
    string $digest,
    ?array $history_manifest = NULL,
  ): void {
    $encoded_manifest = $history_manifest === NULL ? NULL : json_encode($history_manifest, JSON_THROW_ON_ERROR);
    if ($history_manifest !== NULL && $this->manifest(['history_manifest' => $encoded_manifest]) === NULL) {
      throw new MigrateRefusal('history_unverified');
    }
    $this->database->insert('agency_migrate_lineage')->fields([
      'run_id' => $run_id,
      'source_entity_type' => $source->getEntityTypeId(),
      'source_id' => (int) $source->id(),
      'source_uuid' => $source->uuid(),
      'source_revision_id' => (int) ($source->getRevisionId() ?? $source->id()),
      'target_entity_type' => $target->getEntityTypeId(),
      'target_id' => (int) $target->id(),
      'target_uuid' => $target->uuid(),
      'target_revision_id' => (int) ($target->getRevisionId() ?? $target->id()),
      'content_digest' => $digest,
      'history_manifest' => $encoded_manifest,
      'recorded' => $this->time->getCurrentTime(),
    ])->execute();
  }

  /**
   * Reads a versioned manifest without qualifying old or malformed lineage.
   *
   * @return array<string, mixed>|null
   *   Manifest, or NULL when the stored qualification is absent or invalid.
   */
  public function manifest(array $row): ?array {
    $encoded = $row['history_manifest'] ?? NULL;
    if (!is_string($encoded) || $encoded === '') {
      return NULL;
    }
    try {
      $manifest = json_decode($encoded, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($manifest) || ($manifest['version'] ?? NULL) !== 1
      || !is_int($manifest['id'] ?? NULL) || $manifest['id'] < 1
      || !is_string($manifest['uuid'] ?? NULL) || $manifest['uuid'] === ''
      || !is_int($manifest['default_revision_id'] ?? NULL)
      || !is_array($manifest['revisions'] ?? NULL) || $manifest['revisions'] === []) {
      return NULL;
    }
    $defaults = [];
    foreach ($manifest['revisions'] as $id => $revision) {
      if (!is_int($id) || $id < 1 || !is_array($revision)
        || !is_bool($revision['default'] ?? NULL)
        || !is_string($revision['digest'] ?? NULL)
        || !preg_match('/^[a-f0-9]{64}$/', $revision['digest'])) {
        return NULL;
      }
      if ($revision['default']) {
        $defaults[] = $id;
      }
    }
    return $defaults === [$manifest['default_revision_id']] ? $manifest : NULL;
  }

  /**
   * Returns the lineage row for a source type and integer id.
   *
   * @return array<string, mixed>|null
   *   Storage row, or NULL when that source has not been copied.
   */
  public function bySource(string $entity_type, int $id): ?array {
    $row = $this->database->select('agency_migrate_lineage', 'l')
      ->fields('l')
      ->condition('source_entity_type', $entity_type)
      ->condition('source_id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Returns the lineage row for a target type and integer id.
   *
   * @return array<string, mixed>|null
   *   Storage row, or NULL when that target is not in the map.
   */
  public function byTarget(string $entity_type, int $id): ?array {
    $row = $this->database->select('agency_migrate_lineage', 'l')
      ->fields('l')
      ->condition('target_entity_type', $entity_type)
      ->condition('target_id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Counts lineage rows for one source/target pair.
   */
  public function countPair(string $source_type, string $target_type): int {
    return (int) $this->database->select('agency_migrate_lineage', 'l')
      ->condition('source_entity_type', $source_type)
      ->condition('target_entity_type', $target_type)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Whether a target id is known as a migrated Agency record.
   */
  public function hasTarget(string $entity_type, int $id): bool {
    return $this->byTarget($entity_type, $id) !== NULL;
  }

}
