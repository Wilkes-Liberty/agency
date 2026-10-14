<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Storage;

use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\ObservationRecorder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Serializes the first Discovery slice and avoids stale entity-cache authority.
 */
final class DomainStore {

  public const TYPES = [
    'agency_person',
    'agency_principal',
    'agency_discovery',
    'agency_invitation',
    'agency_entitlement',
  ];

  public function __construct(
    public readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ?ObservationRecorder $observations = NULL,
  ) {}

  /**
   * Owns the root transaction; no receipt escapes a caller's uncommitted work.
   */
  public function transaction(callable $work): array {
    if ($this->database->inTransaction()) {
      throw new \LogicException('Discovery commands and projections require a root transaction boundary.');
    }
    $transaction = $this->database->startTransaction();
    $committing = FALSE;
    try {
      // Take the fence before any ordinary read can establish a repeatable-
      // read snapshot. A waiting writer must see its predecessor's commit.
      $lock = $this->database->select('agency_staff_mutex', 'm')->fields('m', ['id'])->condition('id', 1);
      $lock->forUpdate();
      if (!$lock->execute()->fetchField()) {
        throw new \RuntimeException('Missing Discovery mutex.');
      }
      $this->database->update('agency_staff_mutex')->fields(['value' => 0])->condition('id', 1)->execute();
      $this->clear();
      $result = $work();
      $committing = TRUE;
      TransactionBoundary::commit($transaction);
      return $result;
    }
    catch (Refusal $exception) {
      $transaction?->rollBack();
      $this->observations?->report($exception->reason);
      return ['outcome' => 'refused', 'reason' => $exception->reason];
    }
    catch (\Throwable) {
      // A commit acknowledgment can be lost after persistence. Do not assert
      // rollback in that case, even if a cleanup rollback also succeeds.
      try {
        $transaction?->rollBack();
      }
      catch (\Throwable) {
        $committing = TRUE;
      }
      $this->observations?->report('recording_unavailable');
      return $committing
        ? ['outcome' => 'uncertain']
        : ['outcome' => 'refused', 'reason' => 'recording_unavailable'];
    }
    finally {
      $this->clear();
    }
  }

  /**
   * Loads fresh internal entities after explicit service authorization.
   */
  public function find(string $type, array $conditions): ?InternalRecord {
    $items = $this->all($type, $conditions);
    return $items ? reset($items) : NULL;
  }

  /**
   * Returns storage records only to the domain service boundary.
   *
   * @return \Drupal\agency_staff\Entity\InternalRecord[]
   *   Internal records, not API projections.
   */
  public function all(string $type, array $conditions = []): array {
    $storage = $this->entities->getStorage($type);
    $storage->resetCache();
    $records = $storage->loadByProperties($conditions);
    $internal = [];
    foreach ($records as $key => $record) {
      if (!$record instanceof InternalRecord) {
        throw new \LogicException('Only internal Discovery records use this store.');
      }
      $internal[$key] = $record;
    }
    return $internal;
  }

  /**
   * Creates an internal record within the command transaction.
   */
  public function create(string $type, array $values): InternalRecord {
    /** @var \Drupal\agency_staff\Entity\InternalRecord $record */
    $record = $this->entities->getStorage($type)->create($values);
    $record->save();
    return $record;
  }

  /**
   * Clears objects which may describe rolled-back or concurrent state.
   */
  public function clear(): void {
    foreach (self::TYPES as $type) {
      $this->entities->getStorage($type)->resetCache();
    }
  }

}
