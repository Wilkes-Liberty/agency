<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\agency_staff\Identity\Principal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\externalauth\AuthmapInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Supplies fresh host identity and contact-policy decisions to Agency.
 */
final class AgencyIdentityProvider implements IdentityProviderInterface {

  /**
   * Header used by the authenticated BFF for the participant subject token.
   */
  public const PARTICIPANT_TOKEN_HEADER = 'X-Agency-Identity-Token';

  /**
   * The verified intended principal scoped to one preparation call.
   */
  private ?Principal $intended = NULL;

  public function __construct(
    private readonly RequestStack $requests,
    private readonly AccessTokenValidatorInterface $validator,
    private readonly IdentityDirectoryInterface $directory,
    private readonly Settings $settings,
    private readonly ContactPolicyInterface $contacts,
    private readonly CoordinatorPolicy $coordinators,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
    private readonly AuthmapInterface $authmap,
    private readonly BindingHistoryInterface $history,
    private readonly IdentityAuthority $authority,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function current(): ?Principal {
    $request = $this->requests->getCurrentRequest();
    $proof = trim((string) $request?->headers->get(self::PARTICIPANT_TOKEN_HEADER, ''));
    if ($proof === '' || strlen($proof) > 16384) {
      return NULL;
    }
    try {
      $subject = $this->validator->isConfigured() ? $this->validator->validate($proof) : NULL;
      if ($subject === NULL) {
        // A changed audience can reject the proof before subject authorization.
        // Observe and durably latch drift before returning; no HTTP follows
        // this fence, and the rejected token grants no subject authority.
        $this->observeRejectedAuthority();
        return NULL;
      }
      $issuer = $this->issuer();
      // The JWT validator normalizes issuer slashes. After signature
      // validation, additionally require the literal claims for this binding.
      $parts = explode('.', $proof);
      $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), TRUE), TRUE, 8) : NULL;
      if (!is_array($claims) || ($claims['iss'] ?? NULL) !== $issuer || ($claims['sub'] ?? NULL) !== $subject) {
        $this->observeRejectedAuthority();
        return NULL;
      }
      $record = $this->directory->read($subject);
      $binding_current = $this->history->authorize($subject, fn(): bool => $this->bindingIsCurrent($subject));
    }
    catch (IdentityDirectoryUnavailable) {
      $this->observeRejectedAuthority();
      return NULL;
    }
    if ($record === NULL || ($record['subject'] ?? NULL) !== $subject
      || ($record['service'] ?? TRUE) === TRUE
      || !$binding_current) {
      return new Principal($issuer, $subject, FALSE);
    }
    return new Principal($issuer, $subject, ($record['active'] ?? FALSE) === TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function intended(array $contact): ?Principal {
    if ($this->intended === NULL) {
      return NULL;
    }
    // Agency invokes this inside its root transaction before intent/replay.
    // Recheck under the same history mutex held until that transaction ends.
    try {
      return $this->history->authorize($this->intended->subject,
        fn(): bool => $this->bindingIsCurrent($this->intended->subject)) ? $this->intended : NULL;
    }
    catch (IdentityDirectoryUnavailable) {
      return NULL;
    }
  }

  /**
   * Verifies an explicitly selected subject and confines it to one command.
   */
  public function withIntendedSubject(string $subject, array $contact, callable $command): array {
    if ($this->intended !== NULL || !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $subject)) {
      return ['outcome' => 'refused', 'reason' => 'identity_not_found'];
    }
    try {
      $issuer = $this->issuer();
      $record = $this->directory->read($subject);
      $binding_current = $this->history->authorize($subject, fn(): bool => $this->bindingIsCurrent($subject));
    }
    catch (IdentityDirectoryUnavailable) {
      $this->observeRejectedAuthority();
      return ['outcome' => 'refused', 'reason' => 'identity_unavailable'];
    }
    if ($record === NULL || ($record['subject'] ?? NULL) !== $subject || ($record['active'] ?? FALSE) !== TRUE
      || ($record['service'] ?? TRUE) === TRUE || !$this->matchesContact($record, $contact)
      || !$binding_current) {
      return ['outcome' => 'refused', 'reason' => 'identity_not_found'];
    }
    $this->intended = new Principal($issuer, $subject);
    try {
      $result = $command();
      return is_array($result) ? $result : ['outcome' => 'refused', 'reason' => 'identity_unavailable'];
    }
    finally {
      $this->intended = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function coordinatorEligible(AccountInterface $account): bool {
    $effective = $account instanceof AccountProxyInterface ? $account->getAccount() : $account;
    if ($effective instanceof TokenAuthUserInterface) {
      return FALSE;
    }
    $uid = (int) $account->id();
    if ($uid <= 1 || $this->hasDisallowedRole($account)) {
      return FALSE;
    }
    $user = $this->entities->getStorage('user')->loadUnchanged($uid);
    return $user instanceof UserInterface && $user->isActive()
      && !$this->hasDisallowedRole($user) && !$this->serviceAccount($user);
  }

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    if (($contact['kind'] ?? NULL) !== 'email' || !is_string($contact['value'] ?? NULL)
      || !filter_var($contact['value'], FILTER_VALIDATE_EMAIL)) {
      return FALSE;
    }
    return $this->contacts->mayContact($contact);
  }

  /**
   * Requires verified IdP contact ownership after subject selection.
   */
  private function matchesContact(array $record, array $contact): bool {
    if (($contact['kind'] ?? NULL) !== 'email' || !is_string($contact['value'] ?? NULL)
      || ($record['email_verified'] ?? FALSE) !== TRUE || !is_string($record['email'] ?? NULL)) {
      return FALSE;
    }
    return hash_equals(mb_strtolower(trim($contact['value'])), mb_strtolower(trim($record['email'])));
  }

  /**
   * Attempts sticky drift observation without changing an identity refusal.
   */
  private function observeRejectedAuthority(): void {
    try {
      $this->history->checkAuthority();
    }
    catch (IdentityDirectoryUnavailable) {
      // This path already refuses identity. An unavailable invalidation store
      // must retain its independently committed guard, not become a business
      // recording failure or allow a later restoration to revive the epoch.
    }
  }

  /**
   * Rejects inactive or conflicting optional Drupal account bindings.
   */
  private function bindingIsCurrent(string $subject): bool {
    $matches = [];
    foreach ($this->history->providers() as $provider) {
      $uid = $this->authmap->getUid($subject, $provider);
      if ($uid === FALSE) {
        continue;
      }
      if ($this->authority->bindingMode() === 'unbound_only') {
        return FALSE;
      }
      $user = $this->entities->getStorage('user')->loadUnchanged((int) $uid);
      if (!$user instanceof UserInterface || !$user->isActive()) {
        return FALSE;
      }
      $matches[(string) $user->id()] = $user;
    }
    if ($matches === []) {
      return TRUE;
    }
    if (count($matches) !== 1) {
      return FALSE;
    }
    $user = $this->entities->getStorage('user')->loadUnchanged((int) array_key_first($matches));
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return FALSE;
    }
    $bindings = $this->authmap->getAll((int) $user->id());
    foreach ($this->history->providers() as $provider) {
      if (isset($bindings[$provider]) && $bindings[$provider] !== $subject) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Rejects machine, client, partner, pending, and mixed delegated roles.
   */
  private function hasDisallowedRole(AccountInterface $account): bool {
    return $this->coordinators->hasDisallowedRole($account);
  }

  /**
   * Consumer owners and protected accounts are never human coordinators.
   */
  private function serviceAccount(UserInterface $user): bool {
    if ($this->coordinators->isProtectedAccount($user)) {
      return TRUE;
    }
    return $this->entities->hasDefinition('consumer')
      && (bool) $this->entities->getStorage('consumer')->getQuery()->accessCheck(FALSE)
        ->condition($this->entities->getDefinition('consumer')->getKey('owner'), $user->id())
        ->range(0, 1)->execute();
  }

  /**
   * Returns the dedicated configured and validated participant issuer.
   */
  private function issuer(): string {
    return $this->authority->issuer();
  }

}
