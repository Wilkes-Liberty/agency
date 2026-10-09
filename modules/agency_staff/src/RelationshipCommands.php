<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;

/**
 * The Discovery state machine and its root-transaction command boundary.
 */
final class RelationshipCommands {

  public function __construct(
    private readonly DomainStore $store,
    private readonly ActorAccess $access,
    private readonly IdentityProviderInterface $identity,
    private readonly Policy $policy,
    private readonly RelationshipReads $reads,
    private readonly EvidenceOutbox $outbox,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Prepares an invitation-only relationship without provisioning an account.
   */
  public function startDiscovery(array $input, string $key): array {
    return $this->store->transaction(function () use ($input, $key): array {
      $owner = $this->access->coordinator();
      $actor = hash('sha256', 'coordinator:' . $owner->id());
      [$intent, $replay] = $this->intent($actor, 'startDiscovery', '', $key, $input);
      if ($replay) {
        $existing = $this->access->relationship($replay['relationship_id']);
        $this->access->coordinator($existing);
        return ['outcome' => 'committed', 'receipt' => $replay];
      }
      $this->policy->invitationReady();
      $contact = self::contact($input['contact'] ?? []);
      $owner_contact = self::contact($input['owner_contact'] ?? []);
      $display_name = self::text($input['display_name'] ?? '', 255);
      $action = $this->action($input['next_action'] ?? []);
      if (!$this->identity->mayContact($contact)) {
        throw new Refusal('contact_refused');
      }
      $principal = $this->identity->intended($contact);
      if (!$principal || !$principal->active) {
        throw new Refusal('identity_unavailable');
      }
      $binding = $this->store->find('agency_principal', ['principal_key' => $principal->key()]);
      if ($binding && $binding->scalar('status') !== 'active') {
        throw new Refusal('not_found');
      }
      if (!$binding) {
        $person = $this->store->create('agency_person', [
          'display_name' => $display_name,
          'contact_kind' => $contact['kind'],
          'contact_value' => $contact['value'],
        ]);
        $binding = $this->store->create('agency_principal', [
          'principal_key' => $principal->key(),
          'issuer' => $principal->issuer,
          'subject' => $principal->subject,
          'person_uuid' => $person->uuid(),
          'status' => 'active',
        ]);
      }
      $data = [
        'owner' => [
          'name' => $owner->getDisplayName(),
          'contact' => $owner_contact,
        ],
        'next_action' => $action,
        'pause_origin' => NULL,
        'proposal' => NULL,
        'last_agency_action_at' => $this->time->getCurrentTime(),
      ];
      $relationship = $this->store->create('agency_discovery', [
        'person_uuid' => $binding->scalar('person_uuid'),
        'principal_uuid' => $binding->uuid(),
        'owner_uid' => $owner->id(),
        'state' => 'invited',
        'waiting_on' => $action['side'],
        'version' => 0,
        'details' => json_encode($data, JSON_THROW_ON_ERROR),
      ]);
      $this->store->create('agency_entitlement', [
        'relationship_uuid' => $relationship->uuid(),
        'principal_uuid' => $binding->uuid(),
        'status' => 'pending',
        'version' => 1,
      ]);
      return $this->finish($relationship, 'startDiscovery', $actor, $intent, $input);
    });
  }

  /**
   * Sets the single current obligation and its waiting side together.
   */
  public function setNextAction(string $id, array $input, string $key, int $version): array {
    return $this->execute('setNextAction', $id, $input, $key, $version);
  }

  /**
   * Records an optional response without conflating decline with withdrawal.
   */
  public function respond(string $id, array $input, string $key, int $version): array {
    return $this->execute('respond', $id, $input, $key, $version);
  }

  /**
   * Pauses with continuing access and explicit pause ownership.
   */
  public function pauseRelationship(string $id, array $input, string $key, int $version): array {
    return $this->execute('pauseRelationship', $id, $input, $key, $version);
  }

  /**
   * Resumes only for the pausing side and computes the agency deadline.
   */
  public function resumeRelationship(string $id, array $input, string $key, int $version): array {
    return $this->execute('resumeRelationship', $id, $input, $key, $version);
  }

  /**
   * Stops from the latest authorized state even when the view version is stale.
   */
  public function withdrawRelationship(string $id, array $input, string $key, int $version): array {
    return $this->execute('withdrawRelationship', $id, $input, $key, $version);
  }

  /**
   * Revokes generations and participant capabilities without reopening state.
   */
  public function revokeInvitation(string $id, array $input, string $key, int $version): array {
    return $this->execute('revokeInvitation', $id, $input, $key, $version);
  }

  /**
   * Removes workspace and summary access, including after a terminal state.
   */
  public function suspendAccess(string $id, array $input, string $key, int $version): array {
    return $this->execute('suspendAccess', $id, $input, $key, $version);
  }

  /**
   * Closes with a bounded message and a separate delivery obligation.
   */
  public function close(string $id, array $input, string $key, int $version): array {
    return $this->execute('close', $id, $input, $key, $version);
  }

  /**
   * Proposes a next purpose or records explicit participant consent.
   */
  public function moveForward(string $id, array $input, string $key, int $version): array {
    return $this->execute('moveForward', $id, $input, $key, $version);
  }

  /**
   * Authorizes and commits one governed transition.
   */
  private function execute(string $operation, string $id, array $input, string $key, int $version): array {
    $denial = in_array($operation, ['withdrawRelationship', 'revokeInvitation', 'suspendAccess'], TRUE);
    $result = $this->store->transaction(function () use ($operation, $id, $input, $key, $version, $denial): array {
      $participant = in_array($operation, ['respond', 'withdrawRelationship'], TRUE)
        || ($operation === 'moveForward' && ($input['consent'] ?? FALSE) === TRUE);
      if (in_array($operation, ['pauseRelationship', 'resumeRelationship'], TRUE)) {
        if (!in_array($input['side'] ?? 'participant', ['participant', 'agency'], TRUE)) {
          throw new Refusal('invalid');
        }
        // The side selects a path; current authority still comes from services.
        $participant = ($input['side'] ?? 'participant') === 'participant';
      }
      if ($participant) {
        $this->access->principal();
      }
      $relationship = $this->access->relationship($id);
      if ($participant) {
        $this->access->participant($relationship);
        $actor = $this->access->principal()->key();
      }
      else {
        $owner = $this->access->coordinator(
          $relationship,
          $operation === 'suspendAccess' ? 'suspend agency discovery access' : 'coordinate agency discovery',
          $operation === 'setNextAction' && array_keys($input) === ['owner'],
        );
        $actor = hash('sha256', 'coordinator:' . $owner->id());
      }
      // Current authority is checked before idempotency. In particular, a
      // withdrawn participant cannot use a receipt to regain any capability.
      [$intent, $replay] = $this->intent($actor, $operation, $id, $key, $input);
      if ($replay) {
        $result = ['outcome' => 'committed', 'receipt' => $replay];
        if ($participant) {
          $result['workspace'] = $this->reads->workspace($relationship);
        }
        return $result;
      }
      if (!$denial && $version !== (int) $relationship->scalar('version')) {
        return ['outcome' => 'conflict'];
      }
      $state = $relationship->scalar('state');
      if (!in_array($operation, ['suspendAccess', 'revokeInvitation'], TRUE)
        && !in_array($state, ['exploring', 'prepared', 'paused'], TRUE)
        && !($state === 'invited' && $operation === 'setNextAction' && array_keys($input) === ['owner'])) {
        throw new Refusal('not_found');
      }
      if (!$denial) {
        $this->policy->name();
      }
      $data = $relationship->details();
      switch ($operation) {
        case 'setNextAction':
          $owner_only = array_keys($input) === ['owner'];
          if ($state === 'paused' && !$owner_only) {
            throw new Refusal('invalid');
          }
          if (!$owner_only) {
            $data['next_action'] = $this->action($input);
            $relationship->set('waiting_on', $data['next_action']['side']);
          }
          if (isset($input['owner'])) {
            if (!is_array($input['owner']) || !is_int($input['owner']['uid'] ?? NULL)) {
              throw new Refusal('invalid');
            }
            $assignee = $this->access->assignee($input['owner']['uid']);
            $data['owner'] = [
              'name' => $assignee->getDisplayName(),
              'contact' => self::contact($input['owner']['contact'] ?? []),
            ];
            $relationship->set('owner_uid', $assignee->id());
            $data['owner_changed_at'] = $this->time->getCurrentTime();
            $this->notify($data, 'owner_changed');
          }
          else {
            $this->notify($data, 'next_action');
          }
          break;

        case 'respond':
          if ($state === 'paused') {
            throw new Refusal('invalid');
          }
          $choice = $input['choice'] ?? '';
          if (!in_array($choice, ['yes', 'not_now', 'message', 'ready', 'decline_proposal'], TRUE)) {
            throw new Refusal('invalid');
          }
          if ($choice === 'decline_proposal') {
            if (empty($data['proposal'])) {
              throw new Refusal('invalid');
            }
            $data['proposal'] = NULL;
          }
          $data['response'] = [
            'choice' => $choice,
            'note' => self::text($input['note'] ?? '', 2000, TRUE),
            'recorded_at' => $this->time->getCurrentTime(),
          ];
          $relationship->set('state', $choice === 'ready' ? 'prepared' : 'exploring');
          $this->agencyNext($relationship, $data);
          break;

        case 'pauseRelationship':
          if ($state === 'paused') {
            throw new Refusal('invalid');
          }
          $data['pause_origin'] = $participant ? 'participant' : 'agency';
          $data['pause_note'] = self::text($input['note'] ?? '', 2000, TRUE);
          $data['next_action'] = $participant ? NULL : $this->action([
            'summary' => $input['summary'] ?? '',
            'why_it_helps' => $input['why_it_helps'] ?? '',
            'side' => 'agency',
            'expected_by' => $input['expected_by'] ?? 0,
            'optional' => TRUE,
          ]);
          $relationship->set('state', 'paused')->set('waiting_on', 'agency');
          if (!$participant) {
            $this->notify($data, 'paused');
          }
          break;

        case 'resumeRelationship':
          if ($state !== 'paused' || ($data['pause_origin'] ?? '') !== ($participant ? 'participant' : 'agency')) {
            throw new Refusal('invalid');
          }
          $data['pause_origin'] = NULL;
          $relationship->set('state', 'exploring');
          $this->agencyNext($relationship, $data);
          break;

        case 'withdrawRelationship':
          $data['withdrawal_note'] = self::text($input['note'] ?? '', 2000, TRUE);
          $relationship->set('state', 'withdrawn');
          $this->terminal($relationship, $data);
          break;

        case 'close':
          $reason = self::text($input['category'] ?? '', 128);
          $reasons = $this->policy->value('close_reasons');
          if (!is_array($reasons) || !in_array($reason, array_column($reasons, 'id'), TRUE)) {
            throw new Refusal('invalid');
          }
          $data['close'] = [
            'category' => $reason,
            'message' => self::text($input['message'] ?? '', 2000),
            'contact' => self::contact($input['contact'] ?? []),
          ];
          $data['closing_delivery'] = ['state' => 'captured', 'recorded_at' => $this->time->getCurrentTime()];
          $relationship->set('state', 'closed');
          $this->terminal($relationship, $data);
          break;

        case 'suspendAccess':
        case 'revokeInvitation':
          $this->deny($relationship, $operation === 'suspendAccess' ? 'suspended' : 'revoked');
          break;

        case 'moveForward':
          if ($state === 'paused') {
            throw new Refusal('invalid');
          }
          if ($participant) {
            if (empty($data['proposal'])) {
              throw new Refusal('invalid');
            }
            $data['agreed_next_step'] = $data['proposal'];
            $data['proposal'] = NULL;
            $data['next_action'] = NULL;
            $relationship->set('state', 'moved_forward');
          }
          else {
            $data['proposal'] = [
              'target_purpose' => self::text($input['target_purpose'] ?? '', 255),
              'message' => self::text($input['message'] ?? '', 2000),
            ];
            $relationship->set('state', 'exploring')->set('waiting_on', 'participant');
            $data['next_action'] = [
              'code' => NULL,
              'summary' => $data['proposal']['message'],
              'why_it_helps' => $data['proposal']['target_purpose'],
              'side' => 'participant',
              'expected_by' => $this->policy->deadline('follow_up_period'),
              'optional' => TRUE,
            ];
            $this->notify($data, 'proposal');
          }
          break;
      }
      if (!$participant) {
        $data['last_agency_action_at'] = $this->time->getCurrentTime();
      }
      $relationship->setDetails($data);
      $result = $this->finish($relationship, $operation, $actor, $intent, $input, $denial);
      if ($participant && !$denial) {
        $result['workspace'] = $this->reads->workspace($relationship);
      }
      return $result;
    });
    if ($denial && ($result['outcome'] ?? '') === 'committed') {
      $this->outbox->drain();
    }
    return $result;
  }

  /**
   * Validates an intent after current authorization, before checking versions.
   *
   * @internal
   */
  public function intent(string $actor, string $operation, string $context, string $key, array $input): array {
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $key)) {
      throw new Refusal('invalid');
    }
    $id = hash('sha256', json_encode([$actor, $operation, $context, $key], JSON_THROW_ON_ERROR));
    $hash = self::fingerprint($input);
    $row = $this->store->database->select('agency_staff_intent', 'i')->fields('i')->condition('id', $id)->execute()->fetchAssoc();
    if ($row && !hash_equals($row['payload_hash'], $hash)) {
      throw new Refusal('invalid');
    }
    return [$id, $row ? json_decode($row['receipt'], TRUE, 16, JSON_THROW_ON_ERROR) : NULL];
  }

  /**
   * Saves one revision, required evidence and the minimal idempotent receipt.
   *
   * @internal
   */
  public function finish(InternalRecord $relationship, string $operation, string $actor, string $intent, array $input, bool $denial = FALSE): array {
    $now = $this->time->getCurrentTime();
    $relationship->set('version', (int) $relationship->scalar('version') + 1)->set('changed', $now)->set('revision_created', $now);
    $relationship->setNewRevision(TRUE);
    $relationship->save();
    $event = $this->outbox->recordRequired($operation, $relationship->uuid(), $actor, $relationship->scalar('state'), $relationship->scalar('waiting_on'), $denial);
    $receipt = [
      'id' => $event,
      'relationship_id' => $relationship->uuid(),
      'effective_at' => $now,
      'evidence' => $denial ? 'pending' : 'recorded',
    ];
    $grant = $this->store->find('agency_entitlement', ['relationship_uuid' => $relationship->uuid()]);
    if ($grant && in_array($relationship->scalar('state'), ['withdrawn', 'closed'], TRUE)) {
      $receipt['readable_until'] = $grant->scalar('status') === 'summary_only' ? (int) $grant->scalar('readable_until') : NULL;
    }
    $this->store->database->insert('agency_staff_intent')->fields([
      'id' => $intent,
      'payload_hash' => self::fingerprint($input),
      'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR),
    ])->execute();
    return ['outcome' => 'committed', 'receipt' => $receipt, 'version' => (int) $relationship->scalar('version')];
  }

  /**
   * Validates a typed contact route without choosing an identity from it.
   */
  public static function contact(array $contact): array {
    $kind = self::text($contact['kind'] ?? '', 32);
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $kind)) {
      throw new Refusal('invalid');
    }
    return ['kind' => $kind, 'value' => self::text($contact['value'] ?? '', 255)];
  }

  /**
   * Bounds plain text without treating it as HTML or instruction.
   */
  public static function text(mixed $value, int $limit, bool $empty = FALSE): string {
    if (!is_string($value) || mb_strlen($value) > $limit || str_contains($value, "\0") || (!$empty && trim($value) === '')) {
      throw new Refusal('invalid');
    }
    return trim($value);
  }

  /**
   * Validates the single optional next action.
   */
  private function action(array $input): array {
    if (!in_array($input['side'] ?? '', ['agency', 'participant'], TRUE)
      || !is_int($input['expected_by'] ?? NULL) || $input['expected_by'] <= $this->time->getCurrentTime()
      || ($input['optional'] ?? NULL) !== TRUE) {
      throw new Refusal('invalid');
    }
    return [
      'code' => NULL,
      'summary' => self::text($input['summary'] ?? '', 1000),
      'why_it_helps' => self::text($input['why_it_helps'] ?? '', 1000),
      'side' => $input['side'],
      'expected_by' => $input['expected_by'],
      'optional' => TRUE,
    ];
  }

  /**
   * Makes agency responsibility explicit after a participant response/resume.
   */
  private function agencyNext(InternalRecord $relationship, array &$data): void {
    $relationship->set('waiting_on', 'agency');
    $data['next_action'] = [
      'code' => 'agency_response',
      'summary' => NULL,
      'why_it_helps' => NULL,
      'side' => 'agency',
      'expected_by' => $this->policy->deadline('acknowledgement_period'),
      'optional' => TRUE,
    ];
  }

  /**
   * Atomically denies workspace and optionally prepares a bounded summary.
   */
  private function terminal(InternalRecord $relationship, array &$data): void {
    $grant = $this->deny($relationship);
    $data['next_action'] = NULL;
    $data['proposal'] = NULL;
    $data['terminal_at'] = $this->time->getCurrentTime();
    if (in_array($grant->scalar('status'), ['suspended', 'revoked'], TRUE)) {
      return;
    }
    try {
      $until = $this->policy->deadline('summary_window');
      $this->policy->deadline('retention_after_close');
      $retained = self::text($this->policy->value('retained_information_statement'), 2000);
      $summary = [
        'state' => $relationship->scalar('state'),
        'effective_at' => $data['terminal_at'],
        'retained_information' => $retained,
        'retention_period' => $this->policy->value('retention_after_close'),
        'contact_route' => $data['close']['contact'] ?? $data['owner']['contact'],
      ];
      if ($relationship->scalar('state') === 'closed') {
        $summary['message'] = $data['close']['message'];
      }
      $savepoint = $this->store->database->startTransaction();
      try {
        $grant->set('status', 'summary_only')->set('readable_until', $until)->setDetails($summary)->save();
        $savepoint->commitOrRelease();
      }
      catch (\Throwable) {
        $savepoint->rollBack();
        $this->store->clear();
      }
    }
    catch (Refusal) {
      // Missing/invalid summary policy never prevents participant denial.
    }
  }

  /**
   * Removes every generation and participant capability under the domain lock.
   */
  private function deny(InternalRecord $relationship, string $status = 'denied'): InternalRecord {
    $grant = $this->store->find('agency_entitlement', ['relationship_uuid' => $relationship->uuid()]);
    if (!$grant) {
      throw new Refusal('not_found');
    }
    if ($grant->scalar('status') === 'suspended' || ($grant->scalar('status') === 'revoked' && $status !== 'suspended')) {
      $status = $grant->scalar('status');
    }
    $grant->set('status', $status)->set('readable_until', NULL)->set('version', (int) $grant->scalar('version') + 1)->setDetails([])->save();
    foreach ($this->store->all('agency_invitation', ['relationship_uuid' => $relationship->uuid()]) as $generation) {
      $generation->set('status', 'revoked')->save();
    }
    return $grant;
  }

  /**
   * Records notification intent, not provider delivery or consent.
   */
  private function notify(array &$data, string $code): void {
    $data['notification'] = ['code' => $code, 'state' => 'captured', 'recorded_at' => $this->time->getCurrentTime()];
  }

  /**
   * Canonicalizes structured command data without retaining sensitive payloads.
   */
  private static function fingerprint(array $input): string {
    $sort = static function (array $value) use (&$sort): array {
      if (!array_is_list($value)) {
        ksort($value);
      }
      foreach ($value as &$item) {
        if (is_array($item)) {
          $item = $sort($item);
        }
      }
      return $value;
    };
    return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR));
  }

}
