<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads bounded maintenance inventory without changing Discovery records.
 */
final class RetentionPreview {

  public function __construct(
    private readonly DomainStore $store,
    private readonly ActorAccess $access,
    private readonly Policy $policy,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns an advisory page, not deletion authority or a removal receipt.
   *
   * @phpstan-impure
   */
  public function preview(int $afterId = 0, int $limit = 20): array {
    if ($this->store->database->inTransaction()) {
      throw new \LogicException('Retention preview requires a root transaction boundary.');
    }
    $transaction = NULL;
    try {
      $this->access->coordinator(NULL, 'maintain agency discovery retention');
      if ($afterId < 0 || $limit < 1 || $limit > 100) {
        throw new Refusal('invalid');
      }
      $transaction = $this->store->database->startTransaction();
      $this->config->reset('agency.settings');
      $facts = $this->policy->retentionFacts();
      $period = $facts['retention_period'];
      $now = $this->time->getCurrentTime();
      $storage = $this->entities->getStorage('agency_discovery');
      $storage->resetCache();
      $ids = array_values($storage->getQuery()->accessCheck(FALSE)
        ->condition('state', ['withdrawn', 'closed'], 'IN')
        ->condition('id', $afterId, '>')->sort('id')->range(0, $limit + 1)
        ->execute());
      $more = count($ids) > $limit;
      $ids = array_slice($ids, 0, $limit);
      $loaded = $storage->loadMultiple($ids);
      $rows = [];
      foreach ($ids as $id) {
        $record = $loaded[$id] ?? NULL;
        if (!$record instanceof InternalRecord
          || !in_array($record->scalar('state'), ['withdrawn', 'closed'], TRUE)) {
          // A concurrent mutation makes this page unavailable, never partial.
          throw new \UnexpectedValueException();
        }
        $terminal = $record->details()['terminal_at'] ?? NULL;
        $deadline = NULL;
        if (is_int($terminal) && $terminal > 0 && $terminal <= $now) {
          $deadline = Policy::addPeriod($period, $terminal);
        }
        $rows[] = [
          'relationship_id' => $record->uuid(),
          'state' => $record->scalar('state'),
          'version' => (int) $record->scalar('version'),
          'deadline' => $deadline,
          'eligibility' => $deadline === NULL ? 'review_required' : ($deadline <= $now ? 'due' : 'waiting'),
        ];
      }
      // Do not acquire the domain writer mutex or append observations. A
      // preview has no business writes to commit, even when it is refused.
      $transaction->rollBack();
      $transaction = NULL;
      return [
        'outcome' => 'preview',
        'evaluated_at' => $now,
        'retention_period' => $period,
        'records' => $rows,
        'next_after_id' => $more ? (int) end($ids) : NULL,
      ];
    }
    catch (Refusal $exception) {
      return ['outcome' => 'refused', 'reason' => $exception->reason];
    }
    catch (\Throwable) {
      return ['outcome' => 'refused', 'reason' => 'recording_unavailable'];
    }
    finally {
      if ($transaction !== NULL) {
        try {
          $transaction->rollBack();
        }
        catch (\Throwable) {
          // No successful preview escapes an unavailable transaction.
        }
      }
      $this->store->clear();
    }
  }

}
