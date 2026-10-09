<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Curates current authorized data; raw entities and evidence never leave here.
 */
final class RelationshipReads {

  public function __construct(
    private readonly DomainStore $store,
    private readonly ActorAccess $access,
    private readonly Policy $policy,
    private readonly TimeInterface $time,
    private readonly EvidenceOutbox $outbox,
    private readonly ObservationRecorder $observations,
  ) {}

  /**
   * Returns one workspace, or a list belonging to the current principal.
   *
   * @phpstan-impure
   */
  public function forActor(?string $relationshipId = NULL): array {
    return $this->store->transaction(function () use ($relationshipId): array {
      $this->access->principal();
      $this->policy->name();
      if ($relationshipId !== NULL) {
        return [
          'outcome' => 'available',
          'workspace' => $this->workspace($this->access->relationship($relationshipId)),
        ];
      }
      $contexts = [];
      foreach ($this->access->participantRelationships() as $relationship) {
        try {
          $contexts[] = $this->workspace($relationship);
        }
        catch (Refusal $exception) {
          if ($exception->reason !== 'not_found') {
            throw $exception;
          }
        }
      }
      return ['outcome' => 'available', 'contexts' => $contexts];
    });
  }

  /**
   * Projects only the independent, expiring closing-summary capability.
   *
   * @phpstan-impure
   */
  public function summary(string $relationshipId): array {
    return $this->store->transaction(function () use ($relationshipId): array {
      $this->access->principal();
      $relationship = $this->access->relationship($relationshipId);
      return ['outcome' => 'available', 'summary' => $this->summaryProjection($relationship)];
    });
  }

  /**
   * Lists past conversations using only their separate summary capabilities.
   *
   * @phpstan-impure
   */
  public function summaries(): array {
    return $this->store->transaction(function (): array {
      $this->access->principal();
      $contexts = [];
      foreach ($this->access->participantRelationships() as $relationship) {
        try {
          $contexts[] = [
            'id' => $relationship->uuid(),
            'kind' => 'discovery',
            'summary' => $this->summaryProjection($relationship),
          ];
        }
        catch (Refusal $exception) {
          if ($exception->reason !== 'not_found') {
            throw $exception;
          }
        }
      }
      return ['outcome' => 'available', 'contexts' => $contexts];
    });
  }

  /**
   * Applies the summary allowlist only after fresh capability authorization.
   */
  private function summaryProjection(InternalRecord $relationship): array {
    $grant = $this->access->participant($relationship, 'summary_only');
    if (!in_array($relationship->scalar('state'), ['withdrawn', 'closed'], TRUE)) {
      throw new Refusal('not_found');
    }
    $result = array_intersect_key($grant->details(), array_flip([
      'state',
      'effective_at',
      'message',
      'retained_information',
      'retention_period',
      'contact_route',
    ]));
    $result['readable_until'] = (int) $grant->scalar('readable_until');
    return $result;
  }

  /**
   * Returns the assigned coordinator queue without exposing audit payloads.
   *
   * @phpstan-impure
   */
  public function queue(): array {
    return $this->store->transaction(function (): array {
      $owner = $this->access->coordinator();
      $contexts = [];
      $recovery = $this->access->canRecoverOwnership();
      $scope = $recovery ? [] : ['owner_uid' => $owner->id()];
      foreach ($this->store->all('agency_discovery', $scope) as $relationship) {
        $ownerless = $this->access->ownerUnavailable($relationship);
        if ((int) $relationship->scalar('owner_uid') !== (int) $owner->id() && !($recovery && $ownerless)) {
          continue;
        }
        $data = $relationship->details();
        $row = $this->projection($relationship);
        $row['permitted_actions'] = $this->coordinatorActions($relationship, $ownerless);
        $person = $this->store->find('agency_person', ['uuid' => $relationship->scalar('person_uuid')]);
        $row['display_name'] = $person?->scalar('display_name');
        $row['response'] = $data['response'] ?? NULL;
        $row['audit_pending'] = $this->outbox->pending($relationship->uuid());
        $row['closing_delivery'] = $data['closing_delivery'] ?? NULL;
        $row['notification'] = $data['notification'] ?? NULL;
        $row['last_agency_action_at'] = $data['last_agency_action_at'] ?? NULL;
        $row['owner_unavailable'] = $ownerless;
        $row['invitation_lapsed'] = FALSE;
        foreach ($this->store->all('agency_invitation', [
          'relationship_uuid' => $relationship->uuid(),
          'status' => 'active',
        ]) as $invitation) {
          $row['invitation_lapsed'] = (int) $invitation->scalar('expires') <= $this->time->getCurrentTime();
        }
        $contexts[] = $row;
      }
      usort($contexts, static fn(array $a, array $b): int => [
        !$a['agency_late'],
        !$a['owner_unavailable'],
        $a['next_action']['expected_by'] ?? PHP_INT_MAX,
        $a['id'],
      ] <=> [
        !$b['agency_late'],
        !$b['owner_unavailable'],
        $b['next_action']['expected_by'] ?? PHP_INT_MAX,
        $b['id'],
      ]);
      return ['outcome' => 'available', 'contexts' => $contexts, 'alerts' => $this->observations->alerts()];
    });
  }

  /**
   * Builds a freshly authorized projection inside an existing domain boundary.
   *
   * @internal
   */
  public function workspace(InternalRecord $relationship): array {
    $this->access->participant($relationship);
    return $this->projection($relationship);
  }

  /**
   * Derives coordinator actions independently of participant workspace actions.
   */
  private function coordinatorActions(InternalRecord $relationship, bool $ownerless): array {
    if ($ownerless) {
      return in_array($relationship->scalar('state'), ['invited', 'exploring', 'prepared', 'paused'], TRUE)
        ? ['setNextAction'] : [];
    }
    $state = $relationship->scalar('state');
    $actions = match ($state) {
      'invited' => ['setNextAction', 'revokeInvitation'],
      'exploring', 'prepared' => ['setNextAction', 'pauseRelationship', 'close', 'moveForward', 'revokeInvitation'],
      'paused' => ['setNextAction', 'close', 'revokeInvitation'],
      default => ['revokeInvitation'],
    };
    if ($state === 'paused' && ($relationship->details()['pause_origin'] ?? '') === 'agency') {
      $actions[] = 'resumeRelationship';
    }
    if ($state === 'invited') {
      $grant = $this->store->find('agency_entitlement', ['relationship_uuid' => $relationship->uuid()]);
      if ($grant?->scalar('status') === 'pending') {
        $generations = $this->store->all('agency_invitation', ['relationship_uuid' => $relationship->uuid()]);
        $actions[] = $generations ? 'resend' : 'issue';
      }
    }
    try {
      $this->access->coordinator($relationship, 'suspend agency discovery access');
      $actions[] = 'suspendAccess';
    }
    catch (Refusal) {
      // Coordination alone does not grant the separate suspension permission.
    }
    return $actions;
  }

  /**
   * Builds the shared fields after the caller has established authority.
   *
   * @internal
   */
  private function projection(InternalRecord $relationship): array {
    $data = $relationship->details();
    $state = $relationship->scalar('state');
    $pause = $data['pause_origin'] ?? NULL;
    $action = $data['next_action'] ?? NULL;
    $actions = match ($state) {
      'exploring', 'prepared' => ['respond', 'pauseRelationship', 'withdrawRelationship'],
      'paused' => $pause === 'participant' ? ['resumeRelationship', 'withdrawRelationship'] : ['withdrawRelationship'],
      default => [],
    };
    if (in_array($state, ['exploring', 'prepared'], TRUE) && !empty($data['proposal'])) {
      $actions[] = 'moveForward';
    }
    return [
      'id' => $relationship->uuid(),
      'kind' => 'discovery',
      'state' => $state,
      'waiting_on' => $relationship->scalar('waiting_on'),
      'display_waiting_on' => $state === 'paused' && $pause === 'participant' ? 'nobody' : $relationship->scalar('waiting_on'),
      'agency_name' => $this->policy->name(),
      'owner' => $data['owner'],
      'owner_changed_at' => $data['owner_changed_at'] ?? NULL,
      'next_action' => $action,
      'agency_late' => $action && $action['side'] === 'agency' && $action['expected_by'] < $this->time->getCurrentTime(),
      'updated_at' => (int) $relationship->scalar('changed'),
      'version' => (int) $relationship->scalar('version'),
      'pause_origin' => $pause,
      'proposal' => $data['proposal'] ?? NULL,
      'agreed_next_step' => $data['agreed_next_step'] ?? NULL,
      'permitted_actions' => $actions,
    ];
  }

}
