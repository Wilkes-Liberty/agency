<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free dedicated instruction-source capability.
 *
 * Host OAuth entities, flood and audit stay on the adapter. A human
 * grant, mixed consumer grants, or a non-zero token user never
 * qualifies. Missing audit is unavailable, not deny.
 */
final class InstructionSourcePolicy {

  /**
   * Dedicated source scope id.
   */
  public const SCOPE = 'commerce_instruction_source';

  /**
   * Permission the scope must grant.
   */
  public const PERMISSION = 'read billing instruction authority';

  /**
   * UUID shape used by enrollment identifiers.
   */
  // phpcs:ignore Drupal.Files.LineLength.TooLong
  private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

  /**
   * Returns enrollment settings when the caller is the dedicated source.
   *
   * @param array<string, mixed> $attempt
   *   Host adapter facts. Extra keys are unread.
   *
   * @return array<string, mixed>|null
   *   Enrollment, or NULL when the caller is not this source.
   */
  public function capability(array $attempt): ?array {
    if (($attempt['enabled'] ?? FALSE) !== TRUE) {
      return NULL;
    }
    $settings = [
      'enabled' => TRUE,
      'consumer_uuid' => $attempt['consumer_uuid'] ?? NULL,
      'source_id' => $attempt['source_id'] ?? NULL,
      'merchant_id' => $attempt['merchant_id'] ?? NULL,
      'environment' => $attempt['environment'] ?? NULL,
      'profile_uuids' => $attempt['profile_uuids'] ?? NULL,
    ];
    if (!$this->uuid($settings['consumer_uuid'])
      || !$this->uuid($settings['source_id'])
      || !is_string($settings['merchant_id'])
      || $settings['merchant_id'] === ''
      || !is_string($settings['environment'])
      || $settings['environment'] === ''
      || !is_array($settings['profile_uuids'])
      || $settings['profile_uuids'] === []) {
      return NULL;
    }
    foreach ($settings['profile_uuids'] as $uuid) {
      if (!$this->uuid($uuid)) {
        return NULL;
      }
    }
    if (($attempt['token_uid'] ?? NULL) !== 0
      || ($attempt['token_revoked'] ?? FALSE) === TRUE
      || ($attempt['token_expired'] ?? FALSE) === TRUE
      || ($attempt['token_has_user'] ?? FALSE) === TRUE
      || ($attempt['consumer_published'] ?? FALSE) !== TRUE
      || ($attempt['consumer_confidential'] ?? FALSE) !== TRUE
      || ($attempt['consumer_uuid_match'] ?? FALSE) !== TRUE) {
      return NULL;
    }
    $grants = $attempt['grant_types'] ?? NULL;
    $token_scopes = $attempt['token_scopes'] ?? NULL;
    $consumer_scopes = $attempt['consumer_scopes'] ?? NULL;
    $permission = $attempt['scope_permission'] ?? NULL;
    if ($grants !== ['client_credentials']
      || $token_scopes !== [self::SCOPE]
      || $consumer_scopes !== [self::SCOPE]
      || $permission !== self::PERMISSION) {
      return NULL;
    }
    return $settings;
  }

  /**
   * Exact instruction read. Missing audit or flood is unavailable.
   *
   * @param array<string, mixed> $attempt
   *   Host adapter facts plus optional instruction result.
   *
   * @return array{decision: string}
   *   Deny, unavailable, or the instruction decision.
   */
  public function evaluate(array $attempt): array {
    if ($this->capability($attempt) === NULL) {
      return ['decision' => 'deny'];
    }
    if (($attempt['audit'] ?? FALSE) !== TRUE
      || ($attempt['flood'] ?? FALSE) === TRUE
      || !isset($attempt['instruction'])) {
      return ['decision' => 'unavailable'];
    }
    $instruction = $attempt['instruction'];
    if (!is_array($instruction)
      || !is_string($instruction['decision'] ?? NULL)) {
      return ['decision' => 'unavailable'];
    }
    return ['decision' => $instruction['decision']];
  }

  /**
   * True when the value is a UUID string.
   *
   * @param mixed $value
   *   Candidate identifier.
   *
   * @return bool
   *   TRUE when the value is a UUID string.
   */
  private function uuid(mixed $value): bool {
    return is_string($value) && preg_match(self::UUID, $value) === 1;
  }

}
