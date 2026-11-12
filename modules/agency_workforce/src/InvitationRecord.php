<?php

declare(strict_types=1);

namespace Drupal\agency_workforce;

use Drupal\agency_workforce\Exception\InvitationRefusal;

/**
 * A source snapshot, identified only by collection and digest.
 */
final class InvitationRecord {

  public readonly string $contact;

  public function __construct(
    public readonly string $collection,
    public readonly string $digest,
    string $contact,
    public readonly string $classification,
    public readonly ?string $language,
    public readonly int $expires,
    public readonly string $version = '',
  ) {
    $this->contact = strtolower(trim($contact));
    if ($collection === '' || strlen($collection) > 128
      || !preg_match('/^[a-f0-9]{64}$/', $digest)
      || !filter_var($this->contact, FILTER_VALIDATE_EMAIL)
      || !in_array($classification, ['employee', 'contractor'], TRUE)
      || ($language !== NULL && $language === '')
      || $expires <= 0) {
      throw new InvitationRefusal('invalid_invitation');
    }
  }

  /**
   * Hydrates the existing serialized payload without inventing missing fields.
   *
   * @param array<string, mixed> $payload
   *   The stored email/type and optional retained language.
   */
  public static function fromStorage(string $collection, string $digest, array $payload, int $expires, string $version): self {
    if (!isset($payload['email'], $payload['type'])
      || !is_string($payload['email']) || !is_string($payload['type'])
      || (array_key_exists('langcode', $payload) && !is_string($payload['langcode']))) {
      throw new InvitationRefusal('invalid_invitation');
    }
    return new self($collection, $digest, $payload['email'], $payload['type'], $payload['langcode'] ?? NULL, $expires, $version);
  }

}
