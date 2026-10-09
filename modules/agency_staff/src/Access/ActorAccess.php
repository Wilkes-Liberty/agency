<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Access;

use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\agency_staff\Identity\Principal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\user\UserInterface;

/**
 * Resolves fresh session authority before projecting or changing a record.
 */
final class ActorAccess {

  public function __construct(
    private readonly IdentityProviderInterface $identity,
    private readonly DomainStore $store,
    private readonly AccountProxyInterface $account,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ExplicitPermissionChecker $permissions,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns an eligible, explicitly authorized, assigned coordinator.
   */
  public function coordinator(?InternalRecord $relationship = NULL, string $permission = 'coordinate agency discovery', bool $recover = FALSE): UserInterface {
    $user = $this->entities->getStorage('user')->loadUnchanged($this->account->id());
    if (!$user instanceof UserInterface || !$user->isActive() || $user->isAnonymous()
      || !$this->identity->coordinatorEligible($this->account)
      || !$this->identity->coordinatorEligible($user)
      || !$this->permissions->hasExplicitPermission($this->account, $permission)
      || !$this->permissions->hasExplicitPermission($user, $permission)
      || ($relationship && (int) $relationship->scalar('owner_uid') !== (int) $user->id()
        && (!$recover || !$this->ownerUnavailable($relationship) || !$this->canRecoverOwnership()))) {
      throw new Refusal('not_found');
    }
    return $user;
  }

  /**
   * Validates a proposed assignee without granting the caller their authority.
   */
  public function assignee(int $id): UserInterface {
    $user = $this->entities->getStorage('user')->loadUnchanged($id);
    if (!$user instanceof UserInterface || !$user->isActive() || $user->isAnonymous()
      || !$this->identity->coordinatorEligible($user)
      || !$this->permissions->hasExplicitPermission($user, 'coordinate agency discovery')) {
      throw new Refusal('invalid');
    }
    return $user;
  }

  /**
   * Requires explicit recovery permission on the effective and fresh account.
   */
  public function canRecoverOwnership(): bool {
    $user = $this->entities->getStorage('user')->loadUnchanged($this->account->id());
    return $user instanceof UserInterface && $user->isActive()
      && $this->identity->coordinatorEligible($this->account)
      && $this->identity->coordinatorEligible($user)
      && $this->permissions->hasExplicitPermission($this->account, 'recover agency discovery ownership')
      && $this->permissions->hasExplicitPermission($user, 'recover agency discovery ownership');
  }

  /**
   * Detects an unavailable owner without transferring their authority.
   */
  public function ownerUnavailable(InternalRecord $relationship): bool {
    try {
      $this->assignee((int) $relationship->scalar('owner_uid'));
      return FALSE;
    }
    catch (Refusal) {
      return TRUE;
    }
  }

  /**
   * Returns the fresh authenticated principal or refuses unavailable identity.
   */
  public function principal(): Principal {
    $principal = $this->identity->current();
    if (!$principal) {
      throw new Refusal('identity_unavailable');
    }
    if (!$principal->active) {
      throw new Refusal('not_found');
    }
    return $principal;
  }

  /**
   * Scopes lists to the current exact principal before loading records.
   *
   * @return \Drupal\agency_staff\Entity\InternalRecord[]
   *   Owned records; each still requires its projection-specific entitlement.
   */
  public function participantRelationships(): array {
    $principal = $this->principal();
    $binding = $this->store->find('agency_principal', ['principal_key' => $principal->key()]);
    if (!$binding || $binding->scalar('status') !== 'active'
      || $binding->scalar('issuer') !== $principal->issuer || $binding->scalar('subject') !== $principal->subject) {
      return [];
    }
    return $this->store->all('agency_discovery', ['principal_uuid' => $binding->uuid()]);
  }

  /**
   * Requires exact binding and current workspace or separate summary authority.
   */
  public function participant(InternalRecord $relationship, string $required = 'active'): InternalRecord {
    $principal = $this->principal();
    $binding = $this->store->find('agency_principal', ['principal_key' => $principal->key()]);
    if (!$binding || $binding->scalar('status') !== 'active'
      || $binding->scalar('issuer') !== $principal->issuer || $binding->scalar('subject') !== $principal->subject
      || $relationship->scalar('principal_uuid') !== $binding->uuid()) {
      throw new Refusal('not_found');
    }
    $grant = $this->store->find('agency_entitlement', [
      'relationship_uuid' => $relationship->uuid(),
      'principal_uuid' => $binding->uuid(),
    ]);
    if (!$grant || $grant->scalar('status') !== $required
      || ($required === 'summary_only' && (int) $grant->scalar('readable_until') <= $this->time->getCurrentTime())) {
      throw new Refusal('not_found');
    }
    if ($required === 'active' && in_array($relationship->scalar('state'), ['invited', 'withdrawn', 'closed'], TRUE)) {
      throw new Refusal('not_found');
    }
    return $grant;
  }

  /**
   * Authorizes one relationship without leaking its existence.
   */
  public function relationship(string $id): InternalRecord {
    $relationship = $this->store->find('agency_discovery', ['uuid' => $id]);
    if (!$relationship) {
      throw new Refusal('not_found');
    }
    return $relationship;
  }

}
