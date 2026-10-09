<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Access\ActorAccess;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Separate generation lifecycle; no User, Profile, role or email-side mutation.
 */
final class InvitationCommands {

  public function __construct(
    private readonly DomainStore $store,
    private readonly ActorAccess $access,
    private readonly IdentityProviderInterface $identity,
    private readonly Policy $policy,
    private readonly RelationshipReads $reads,
    private readonly RelationshipCommands $commands,
    private readonly RequestStack $requests,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Captures the first invitation; the token is returned only on first success.
   */
  public function issue(string $id, string $key, int $version): array {
    return $this->generate($id, $key, $version, FALSE);
  }

  /**
   * Supersedes earlier generations, without ever reactivating an old token.
   */
  public function resend(string $id, string $key, int $version): array {
    return $this->generate($id, $key, $version, TRUE);
  }

  /**
   * Delegates the locked denial transition to the relationship command.
   */
  public function revoke(string $id, string $key, int $version): array {
    return $this->commands->revokeInvitation($id, [], $key, $version);
  }

  /**
   * Returns only invitation status; GET never consumes or binds anything.
   */
  public function inspect(string $token): array {
    return $this->store->transaction(function () use ($token): array {
      $support = $this->policy->supportContact();
      $generation = $this->store->find('agency_invitation', ['token_hash' => hash('sha256', $token)]);
      if (!$generation) {
        return ['status' => 'closed', 'support_contact' => $support];
      }
      if ($generation->scalar('status') === 'consumed') {
        $relationship = $this->access->relationship($generation->scalar('relationship_uuid'));
        try {
          $this->access->participant($relationship);
          return ['status' => 'workspace', 'relationship_id' => $relationship->uuid(), 'support_contact' => $support];
        }
        catch (Refusal) {
          return ['status' => 'already_used', 'support_contact' => $support];
        }
      }
      if ($generation->scalar('status') !== 'active' || (int) $generation->scalar('expires') <= $this->time->getCurrentTime()) {
        return ['status' => 'closed', 'support_contact' => $support];
      }
      $relationship = $this->access->relationship($generation->scalar('relationship_uuid'));
      return [
        'status' => 'open',
        'agency_name' => $this->policy->name(),
        'expires' => (int) $generation->scalar('expires'),
        'owner_name' => $relationship->details()['owner']['name'],
        'support_contact' => $support,
      ];
    });
  }

  /**
   * Activates an exact intended principal and consumes once under a row lock.
   */
  public function accept(string $token, string $key): array {
    if (!$this->requests->getCurrentRequest()?->isMethod('POST')) {
      return ['outcome' => 'refused', 'reason' => 'method_not_allowed'];
    }
    return $this->store->transaction(function () use ($token, $key): array {
      $principal = $this->access->principal();
      if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new Refusal('invitation_closed');
      }
      $token_hash = hash('sha256', $token);
      $lock = $this->store->database->select('agency_invitation', 'i')->fields('i', [
        'id',
      ])->condition('token_hash', $token_hash);
      $lock->forUpdate();
      $lock->execute()->fetchField();
      $generation = $this->store->find('agency_invitation', ['token_hash' => $token_hash]);
      if (!$generation || !in_array($generation->scalar('status'), ['active', 'consumed'], TRUE)) {
        throw new Refusal('invitation_closed');
      }
      if ($generation->scalar('status') === 'active' && (int) $generation->scalar('expires') <= $this->time->getCurrentTime()) {
        throw new Refusal('invitation_closed');
      }
      $binding = $this->store->find('agency_principal', ['uuid' => $generation->scalar('principal_uuid')]);
      if (!$binding || $binding->scalar('status') !== 'active') {
        throw new Refusal('invitation_closed');
      }
      if ($binding->scalar('issuer') !== $principal->issuer || $binding->scalar('subject') !== $principal->subject) {
        throw new Refusal($generation->scalar('status') === 'consumed' ? 'already_used' : 'wrong_account');
      }
      $relationship = $this->access->relationship($generation->scalar('relationship_uuid'));
      [
        $intent,
        $replay,
      ] = $this->commands->intent($principal->key(), 'accept', $relationship->uuid(), $key, [
        'generation' => $generation->uuid(),
      ]);
      if ($generation->scalar('status') === 'consumed') {
        $this->access->participant($relationship);
        if ($replay) {
          return [
            'outcome' => 'committed',
            'receipt' => $replay,
          ] + $this->reads->replayProjection($relationship);
        }
        throw new Refusal('already_used');
      }
      $this->policy->name();
      if ((int) $generation->scalar('expires') <= $this->time->getCurrentTime() || $relationship->scalar('state') !== 'invited') {
        throw new Refusal('invitation_closed');
      }
      $grant = $this->store->find('agency_entitlement', [
        'relationship_uuid' => $relationship->uuid(),
        'principal_uuid' => $binding->uuid(),
      ]);
      if (!$grant || $grant->scalar('status') !== 'pending') {
        throw new Refusal('invitation_closed');
      }
      $generation->set('status', 'consumed')->save();
      $grant->set('status', 'active')->set('version', (int) $grant->scalar('version') + 1)->save();
      $relationship->set('state', 'exploring');
      $result = $this->commands->finish($relationship, 'accept', $principal->key(), $intent, [
        'generation' => $generation->uuid(),
      ]);
      $result['workspace'] = $this->reads->workspace($relationship);
      return $result;
    });
  }

  /**
   * Captures a generation after fresh authority and policy checks.
   */
  private function generate(string $id, string $key, int $version, bool $resend): array {
    return $this->store->transaction(function () use ($id, $key, $version, $resend): array {
      $relationship = $this->access->relationship($id);
      $owner = $this->access->coordinator($relationship);
      $actor = hash('sha256', 'coordinator:' . $owner->id());
      $operation = $resend ? 'resend' : 'issue';
      [$intent, $replay] = $this->commands->intent($actor, $operation, $id, $key, []);
      if ($replay) {
        return ['outcome' => 'committed', 'receipt' => $replay];
      }
      $this->policy->invitationReady();
      if ($version !== (int) $relationship->scalar('version')) {
        return ['outcome' => 'conflict'];
      }
      $grant = $this->store->find('agency_entitlement', ['relationship_uuid' => $id]);
      $binding = $this->store->find('agency_principal', ['uuid' => $relationship->scalar('principal_uuid')]);
      if ($relationship->scalar('state') !== 'invited' || !$grant || $grant->scalar('status') !== 'pending' || !$binding || $binding->scalar('status') !== 'active') {
        throw new Refusal('not_found');
      }
      $person = $this->store->find('agency_person', ['uuid' => $relationship->scalar('person_uuid')]);
      if (!$person || !$this->identity->mayContact([
        'kind' => $person->scalar('contact_kind'),
        'value' => $person->scalar('contact_value'),
      ])) {
        throw new Refusal('contact_refused');
      }
      $generations = $this->store->all('agency_invitation', ['relationship_uuid' => $id]);
      if (!$resend && $generations) {
        throw new Refusal('invalid');
      }
      foreach ($generations as $generation) {
        if ($generation->scalar('status') === 'active') {
          $generation->set('status', 'superseded')->save();
        }
      }
      $token = bin2hex(random_bytes(32));
      $expires = $this->policy->deadline('invitation_validity');
      $this->store->create('agency_invitation', [
        'relationship_uuid' => $id,
        'principal_uuid' => $binding->uuid(),
        'token_hash' => hash('sha256', $token),
        'status' => 'active',
        'expires' => $expires,
        'delivery_state' => 'captured',
      ]);
      $result = $this->commands->finish($relationship, $operation, $actor, $intent, []);
      $result['invitation'] = ['token' => $token, 'expires' => $expires, 'delivery_state' => 'captured'];
      return $result;
    });
  }

}
