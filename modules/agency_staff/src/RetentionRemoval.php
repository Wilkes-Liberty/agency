<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Identity\Principal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Site\Settings;

/**
 * Prepares and removes one expired Discovery context behind explicit authority.
 */
final class RetentionRemoval {

  public function __construct(
    private readonly DomainStore $store,
    private readonly ActorAccess $access,
    private readonly LockedRetentionPolicy $policy,
    private readonly EntityTypeManagerInterface $entities,
    private readonly RelationshipCommands $commands,
    private readonly EvidenceOutbox $outbox,
    private readonly TimeInterface $time,
    private readonly Settings $settings,
  ) {}

  /**
   * Returns a minimized advisory plan without writing or granting authority.
   *
   * @phpstan-impure
   */
  public function prepare(string $id): array {
    if ($this->store->database->inTransaction()) {
      throw new \LogicException('Retention preparation requires a root transaction.');
    }
    $transaction = NULL;
    try {
      $operator = $this->access->coordinator(NULL, 'remove expired agency discovery');
      $this->identifier($id);
      $transaction = $this->store->database->startTransaction();
      $this->store->clear();
      $policy = $this->policy->capture(FALSE);
      $inventory = $this->inventory($id, $policy['facts']);
      $plan = $this->plan($inventory, $policy, (string) $operator->id());
      $transaction->rollBack();
      $transaction = NULL;
      return ['outcome' => 'prepared'] + $plan;
    }
    catch (Refusal $exception) {
      return ['outcome' => 'refused', 'reason' => $exception->reason];
    }
    catch (\Throwable) {
      return ['outcome' => 'refused', 'reason' => 'recording_unavailable'];
    }
    finally {
      $transaction?->rollBack();
      $this->store->clear();
    }
  }

  /**
   * Rechecks the exact plan under the writer and policy fences before deletion.
   */
  public function remove(string $id, string $plan, string $key): array {
    return $this->store->transaction(function () use ($id, $plan, $key): array {
      $operator = $this->access->coordinator(NULL, 'remove expired agency discovery');
      $this->identifier($id);
      if (preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1) {
        throw new Refusal('invalid');
      }
      $actor = hash('sha256', 'coordinator:' . $operator->id());
      $input = ['plan_sha256' => $plan];
      [$intent, $replay] = $this->commands->intent($actor, 'removeExpiredDiscovery', $id, $key, $input);
      if ($replay !== NULL) {
        return ['outcome' => 'committed', 'receipt' => $replay];
      }
      $policy = $this->policy->capture(TRUE);
      $inventory = $this->inventory($id, $policy['facts']);
      $fresh = $this->plan($inventory, $policy, (string) $operator->id());
      if (!hash_equals($plan, $fresh['plan_sha256'])) {
        throw new Refusal('plan_changed');
      }
      $record = $inventory['relationship'];
      $state = $record->scalar('state');
      $waiting = $record->scalar('waiting_on');
      foreach (['agency_invitation', 'agency_entitlement'] as $type) {
        $this->entities->getStorage($type)->delete($inventory[$type]);
      }
      // SQL content storage deletes every revision through the Entity API.
      $this->entities->getStorage('agency_discovery')->delete([$record]);
      if ($inventory['principal_action'] === 'delete') {
        $this->entities->getStorage('agency_principal')->delete([$inventory['principal']]);
      }
      elseif ($inventory['principal_action'] === 'minimize') {
        $inventory['principal']->set('issuer', '')->set('subject', '')->set('person_uuid', '')->save();
      }
      if ($inventory['person_action'] === 'delete') {
        $this->entities->getStorage('agency_person')->delete([$inventory['person']]);
      }
      $event = $this->outbox->recordRequired('removeExpiredDiscovery', $id, $actor, $state, $waiting);
      $receipt = [
        'id' => $event,
        'relationship_id' => $id,
        'effective_at' => $this->time->getCurrentTime(),
        'removed' => TRUE,
        'evidence' => 'recorded',
      ];
      $this->store->database->insert('agency_staff_intent')->fields([
        'id' => $intent,
        'payload_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
        'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR),
      ])->execute();
      return ['outcome' => 'committed', 'receipt' => $receipt];
    });
  }

  /**
   * Captures bounded complete owned data and every relevant shared reference.
   */
  private function inventory(string $id, array $facts): array {
    $records = $this->records('agency_discovery', ['uuid' => $id], 1);
    $record = reset($records);
    if (!$record instanceof InternalRecord) {
      throw new Refusal('not_found');
    }
    if (!in_array($record->scalar('state'), ['closed', 'withdrawn'], TRUE)) {
      throw new Refusal('not_due');
    }
    $terminal = $record->details()['terminal_at'] ?? NULL;
    $now = $this->time->getCurrentTime();
    if (!is_int($terminal) || $terminal <= 0 || $terminal > $now) {
      throw new Refusal('review_required');
    }
    $deadline = Policy::addPeriod($facts['retention_period'], $terminal);
    if ($deadline > $now) {
      throw new Refusal('not_due');
    }
    $person_id = $record->scalar('person_uuid');
    $principal_id = $record->scalar('principal_uuid');
    $this->identifier($person_id);
    $this->identifier($principal_id);
    $people = $this->records('agency_person', ['uuid' => $person_id], 1);
    $principals = $this->records('agency_principal', ['uuid' => $principal_id], 1);
    $person = reset($people);
    $principal = reset($principals);
    if (!$person instanceof InternalRecord || !$principal instanceof InternalRecord
      || $principal->scalar('person_uuid') !== $person_id
      || $principal->scalar('status') === ''
      || preg_match('/^[a-f0-9]{64}$/D', $principal->scalar('principal_key')) !== 1) {
      throw new Refusal('review_required');
    }
    if ($principal->scalar('status') === 'active') {
      try {
        $identity = new Principal($principal->scalar('issuer'), $principal->scalar('subject'));
        if (!hash_equals($principal->scalar('principal_key'), $identity->key())) {
          throw new \UnexpectedValueException();
        }
      }
      catch (\Throwable) {
        // A malformed active binding also denies new invitations. Deleting it
        // would remove that denial and permit a replacement binding.
        throw new Refusal('review_required');
      }
    }
    $revisions = $this->revisions($record, FALSE);
    if (!$revisions) {
      throw new Refusal('review_required');
    }
    foreach ($revisions as $revision) {
      if ($revision->scalar('person_uuid') !== $person_id
        || $revision->scalar('principal_uuid') !== $principal_id) {
        // Historical resolver changes require explicit reconciliation.
        throw new Refusal('review_required');
      }
    }
    $inventory = [
      'relationship' => $record,
      'person' => $person,
      'principal' => $principal,
      'revisions' => $revisions,
      'deadline' => $deadline,
      'references' => [
        'revisions' => $this->revisions($record, TRUE),
        'principals' => $this->records('agency_principal', ['person_uuid' => $person_id], 100, [
          'uuid', $principal_id,
        ]),
      ],
    ];
    $principal_shared = FALSE;
    $person_shared = (bool) $inventory['references']['principals'];
    foreach ($inventory['references']['revisions'] as $reference) {
      $principal_shared = $principal_shared || $reference->scalar('principal_uuid') === $principal_id;
      $person_shared = $person_shared
        || $reference->scalar('person_uuid') === $person_id;
    }
    foreach (['agency_invitation', 'agency_entitlement'] as $type) {
      $inventory[$type] = $this->records($type, ['relationship_uuid' => $id], 100);
      foreach ($inventory[$type] as $child) {
        if ($child->scalar('principal_uuid') !== $principal_id) {
          throw new Refusal('review_required');
        }
      }
      $inventory['references'][$type] = $this->records($type, ['principal_uuid' => $principal_id], 100, [
        'relationship_uuid', $id,
      ]);
      $principal_shared = $principal_shared || (bool) $inventory['references'][$type];
    }
    $inventory['principal_action'] = $principal_shared ? 'preserve'
      : ($principal->scalar('status') === 'active' ? 'delete' : 'minimize');
    $inventory['person_action'] = $person_shared || $principal_shared ? 'preserve' : 'delete';
    return $inventory;
  }

  /**
   * Bounds entity reads, including references from orphan children.
   */
  private function records(string $type, array $conditions, int $limit, ?array $exclude = NULL): array {
    $storage = $this->entities->getStorage($type);
    $storage->resetCache();
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('id')->range(0, $limit + 1);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    if ($exclude !== NULL) {
      $query->condition($query->orConditionGroup()
        ->condition($exclude[0], $exclude[1], '<>')->notExists($exclude[0]));
    }
    $ids = array_values($query->execute());
    if (count($ids) > $limit) {
      throw new Refusal('review_required');
    }
    $records = $storage->loadMultiple($ids);
    $this->complete($records, count($ids));
    return $records;
  }

  /**
   * Reads owned or shared history, not just other relationships' default rows.
   */
  private function revisions(InternalRecord $record, bool $references): array {
    $storage = $this->entities->getStorage('agency_discovery');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new Refusal('retention_unavailable');
    }
    $query = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->sort('revision_id')->range(0, 1001);
    if ($references) {
      $query->condition('id', $record->id(), '<>');
      $query->condition($query->orConditionGroup()
        ->condition('person_uuid', $record->scalar('person_uuid'))
        ->condition('principal_uuid', $record->scalar('principal_uuid')));
    }
    else {
      $query->condition('id', $record->id());
    }
    $matches = $query->execute();
    $ids = array_keys($matches);
    if (count($ids) > 1000 || ($references && count(array_unique($matches)) > 100)) {
      throw new Refusal('review_required');
    }
    $revisions = $storage->loadMultipleRevisions($ids);
    $this->complete($revisions, count($ids));
    return $revisions;
  }

  /**
   * Missing or incompatible records never imply an absence of references.
   */
  private function complete(array $records, int $expected): void {
    if (count($records) !== $expected) {
      throw new Refusal('review_required');
    }
    foreach ($records as $record) {
      if (!$record instanceof InternalRecord) {
        throw new Refusal('review_required');
      }
    }
  }

  /**
   * Binds private state without returning a reversible content fingerprint.
   */
  private function plan(array $inventory, array $policy, string $operator): array {
    $salt = $this->settings->get('hash_salt');
    if (!is_string($salt) || strlen($salt) < 16) {
      throw new Refusal('retention_unavailable');
    }
    $snapshot = $this->canonical([
      'format' => 'agency-retention-v1',
      'operator' => $operator,
      'policy' => $policy,
      'inventory' => $inventory,
    ]);
    return [
      'relationship_id' => $inventory['relationship']->uuid(),
      'deadline' => $inventory['deadline'],
      'plan_sha256' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), $salt),
      'revisions' => count($inventory['revisions']),
      'invitations' => count($inventory['agency_invitation']),
      'entitlements' => count($inventory['agency_entitlement']),
      'principal_action' => $inventory['principal_action'],
      'person_action' => $inventory['person_action'],
    ];
  }

  /**
   * Canonicalizes field data without entity serialization metadata or services.
   */
  private function canonical(mixed $value): mixed {
    if ($value instanceof InternalRecord) {
      $value = $value->toArray();
    }
    if (is_array($value)) {
      ksort($value);
      foreach ($value as &$item) {
        $item = $this->canonical($item);
      }
    }
    return $value;
  }

  /**
   * Rejects malformed identifiers before any inventory query.
   */
  private function identifier(string $id): void {
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) !== 1) {
      throw new Refusal('invalid');
    }
  }

}
