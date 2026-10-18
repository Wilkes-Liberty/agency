<?php

declare(strict_types=1);

namespace Drupal\agency_host_synthetic;

use Drupal\agency_host\Identity\IdentityDirectoryInterface;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\agency_host\Identity\IdentityLookupInterface;
use Drupal\agency_host\Identity\AccessTokenValidatorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Two fixed fixture identities; never enabled in application configuration.
 */
final class SyntheticIdentity implements IdentityDirectoryInterface, IdentityLookupInterface, AccessTokenValidatorInterface {

  public const ISSUER = 'https://identity.example.test/realms/example';

  public function __construct(private readonly StateInterface $state) {
    if (Settings::get('agency_synthetic_host') !== TRUE) {
      throw new \LogicException('Synthetic identity requires isolated fixture settings.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $jwt): ?string {
    foreach (['person-one', 'person-two'] as $subject) {
      if (hash_equals(self::proof($subject), $jwt)) {
        return $subject;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function read(string $subject): ?array {
    if ($this->state->get('agency_synthetic.identity_failure', FALSE)) {
      throw new IdentityDirectoryUnavailable('Synthetic identity outage.');
    }
    if (!in_array($subject, ['person-one', 'person-two'], TRUE)) {
      return NULL;
    }
    return [
      'subject' => $subject,
      'active' => TRUE,
      'email' => $subject . '@example.test',
      'email_verified' => TRUE,
      'service' => FALSE,
      'name' => ucwords(str_replace('-', ' ', $subject)),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function findEmail(string $email): ?array {
    foreach (['person-one', 'person-two'] as $subject) {
      $record = $this->read($subject);
      if ($record['email'] === $email) {
        return $record;
      }
    }
    return NULL;
  }

  /**
   * Produces a fixture proof, deliberately not a real signed IdP token.
   */
  public static function proof(string $subject): string {
    $claims = rtrim(strtr(base64_encode(json_encode([
      'iss' => self::ISSUER,
      'sub' => $subject,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return 'synthetic.' . $claims . '.fixture';
  }

}
