<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\agency_host\HostSettings;
use Drupal\agency_staff\Exception\Refusal;

/**
 * Requires explicit private custody configuration, without business defaults.
 */
final class DeliveryConfiguration {

  /**
   * Returns validated settings; missing retention disables even custody.
   */
  public function read(): array {
    $config = HostSettings::current('invitation_delivery', []);
    $key_id = $config['key_id'] ?? NULL;
    $key = is_string($key_id) ? ($config['keys'][$key_id] ?? NULL) : NULL;
    if (!in_array($config['mode'] ?? NULL, ['capture', 'smtp'], TRUE)
      || !is_string($key_id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key_id)
      || !is_string($key) || strlen((string) base64_decode($key, TRUE)) !== 32
      || !is_int($config['payload_retention_seconds'] ?? NULL)
      || $config['payload_retention_seconds'] <= 0) {
      throw new Refusal('delivery_disabled');
    }
    return $config;
  }

  /**
   * Authenticates each ciphertext against its immutable owning context.
   */
  public function encrypt(array $payload, string $context): string {
    $config = $this->read();
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm',
      base64_decode($config['keys'][$config['key_id']], TRUE), OPENSSL_RAW_DATA,
      $nonce, $tag, 'agency-delivery:1:' . $context);
    if ($cipher === FALSE) {
      throw new Refusal('delivery_unavailable');
    }
    return json_encode([
      'v' => 1,
      'kid' => $config['key_id'],
      'nonce' => base64_encode($nonce),
      'tag' => base64_encode($tag),
      'cipher' => base64_encode($cipher),
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Refuses missing old keys, altered payloads and cross-row substitution.
   */
  public function decrypt(string $encoded, string $context): array {
    $config = $this->read();
    $payload = json_decode($encoded, TRUE, 8, JSON_THROW_ON_ERROR);
    $key = $config['keys'][$payload['kid'] ?? ''] ?? NULL;
    if (($payload['v'] ?? NULL) !== 1 || !is_string($key)
      || strlen((string) base64_decode($key, TRUE)) !== 32) {
      throw new Refusal('delivery_unavailable');
    }
    $nonce = base64_decode($payload['nonce'] ?? '', TRUE);
    $tag = base64_decode($payload['tag'] ?? '', TRUE);
    $cipher = base64_decode($payload['cipher'] ?? '', TRUE);
    if ($nonce === FALSE || strlen($nonce) !== 12 || $tag === FALSE || strlen($tag) !== 16 || $cipher === FALSE) {
      throw new Refusal('delivery_unavailable');
    }
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', base64_decode($key, TRUE),
      OPENSSL_RAW_DATA, $nonce, $tag, 'agency-delivery:1:' . $context);
    if ($plain === FALSE) {
      throw new Refusal('delivery_unavailable');
    }
    return json_decode($plain, TRUE, 32, JSON_THROW_ON_ERROR);
  }

  /**
   * Fingerprints semantic values independently of associative key ordering.
   */
  public static function fingerprint(array $value): string {
    $sort = static function (array $input) use (&$sort): array {
      if (!array_is_list($input)) {
        ksort($input);
      }
      return array_map(static fn($item) => is_array($item) ? $sort($item) : $item, $input);
    };
    return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
  }

}
