<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Agency writes that must be replayed to legacy if authority rolls back.
 */
final class PostCutoverWriteLog {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records one Agency write after the domain became Agency-authoritative.
   */
  public function record(string $domain, string $entity_type, int $entity_id, string $entity_uuid): void {
    $this->database->insert('agency_migrate_write_log')->fields([
      'domain' => $domain,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'entity_uuid' => $entity_uuid,
      'recorded' => $this->time->getCurrentTime(),
      'replayed' => NULL,
    ])->execute();
  }

  /**
   * Unreplayed writes for a domain, oldest first.
   *
   * @return list<array<string, mixed>>
   *   Storage rows.
   */
  public function pending(string $domain): array {
    $rows = [];
    $result = $this->database->select('agency_migrate_write_log', 'w')
      ->fields('w')
      ->condition('domain', $domain)
      ->isNull('replayed')
      ->orderBy('wid')
      ->execute();
    while ($row = $result->fetchAssoc()) {
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Marks a write log row as replayed onto legacy.
   */
  public function markReplayed(int $wid): void {
    $this->database->update('agency_migrate_write_log')->fields([
      'replayed' => $this->time->getCurrentTime(),
    ])->condition('wid', $wid)->execute();
  }

}
