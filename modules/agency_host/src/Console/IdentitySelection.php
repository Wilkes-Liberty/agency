<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\agency_host\DiscoveryTransport;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\agency_host\Identity\IdentityDirectoryInterface;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\agency_host\Identity\IdentityLookupInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;

/**
 * Revalidates a bounded, server-held selection; never provisions identities.
 */
final class IdentitySelection {

  public const LIFETIME = 300;

  public function __construct(
    private readonly IdentityDirectoryInterface $directory,
    private readonly AgencyIdentityProvider $identity,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly DiscoveryTransport $transport,
  ) {}

  /**
   * Checks contact rules before any bounded directory read.
   */
  public function find(string $email): array {
    $contact = ['kind' => 'email', 'value' => trim($email)];
    if (!$this->identity->mayContact($contact)) {
      return ['outcome' => 'refused', 'reason' => 'contact_refused'];
    }
    if (!$this->transport->allow('console_lookup', (string) $this->account->id(), 20)) {
      return ['outcome' => 'refused', 'reason' => 'rate_limited'];
    }
    try {
      if (!$this->directory instanceof IdentityLookupInterface) {
        throw new IdentityDirectoryUnavailable('identity_unavailable');
      }
      $record = $this->directory->findEmail($contact['value']);
      if ($record === NULL) {
        return ['outcome' => 'none'];
      }
      return $this->identity->withIntendedSubject($record['subject'], $contact, fn(): array => [
        'outcome' => 'available',
        'selection' => [
          'subject' => $record['subject'],
          'issuer' => Settings::get('agency_host_identity', [])['issuer'] ?? '',
          'email' => $contact['value'],
          'name' => is_string($record['name'] ?? NULL) ? mb_substr($record['name'], 0, 255) : '',
          'uid' => (int) $this->account->id(),
          'expires' => $this->time->getCurrentTime() + self::LIFETIME,
        ],
      ]);
    }
    catch (IdentityDirectoryUnavailable) {
      return ['outcome' => 'refused', 'reason' => 'identity_unavailable'];
    }
  }

  /**
   * Rechecks ambiguity and identity before using the confirmed tuple.
   */
  public function apply(array $selection, string $email, callable $command): array {
    if (($selection['uid'] ?? NULL) !== (int) $this->account->id()
      || ($selection['expires'] ?? 0) <= $this->time->getCurrentTime()
      || ($selection['email'] ?? NULL) !== trim($email)
      || ($selection['issuer'] ?? NULL) !== (Settings::get('agency_host_identity', [])['issuer'] ?? NULL)) {
      return ['outcome' => 'refused', 'reason' => 'selection_lapsed'];
    }
    $fresh = $this->find($email);
    if (($fresh['outcome'] ?? '') !== 'available') {
      return ($fresh['reason'] ?? '') === 'identity_unavailable' ? $fresh : [
        'outcome' => 'refused',
        'reason' => 'selection_lapsed',
      ];
    }
    foreach (['subject', 'issuer', 'email', 'name'] as $key) {
      if ($fresh['selection'][$key] !== $selection[$key]) {
        return ['outcome' => 'refused', 'reason' => 'selection_lapsed'];
      }
    }
    return $this->identity->withIntendedSubject($selection['subject'], ['kind' => 'email', 'value' => $email], $command);
  }

}
