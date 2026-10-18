<?php

/**
 * @file
 * Environment-specific Agency invitation custody; no delivery defaults.
 *
 * Generic copy of the host-site bootstrap include. Keep keys in the
 * deployment secret store. Never log source or parse errors.
 */

$settings['agency_host_invitation_delivery'] = (static function (): array {
  $mode = getenv('DRUPAL_AGENCY_DELIVERY_MODE');
  $key_id = getenv('DRUPAL_AGENCY_DELIVERY_KEY_ID');
  $encoded = getenv('DRUPAL_AGENCY_DELIVERY_KEYS_JSON');
  $retention = getenv('DRUPAL_AGENCY_DELIVERY_RETENTION_SECONDS');
  if (!in_array($mode, ['capture', 'smtp'], TRUE)
    || !is_string($key_id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key_id)
    || !is_string($encoded) || strlen($encoded) > 16384
    || !is_string($retention) || !preg_match('/^[1-9][0-9]*$/D', $retention)) {
    return [];
  }
  $seconds = filter_var($retention, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($seconds === FALSE) {
    return [];
  }
  try {
    $keys = json_decode($encoded, FALSE, 4, JSON_THROW_ON_ERROR);
  }
  catch (\JsonException) {
    return [];
  }
  if (!$keys instanceof \stdClass || count((array) $keys) > 32 || !isset($keys->{$key_id})) {
    return [];
  }
  foreach ($keys as $id => $key) {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) $id)
      || !is_string($key) || strlen($key) !== 44
      || strlen((string) base64_decode($key, TRUE)) !== 32
      || base64_encode(base64_decode($key, TRUE)) !== $key) {
      return [];
    }
  }
  return [
    'mode' => $mode,
    'key_id' => $key_id,
    'keys' => (array) $keys,
    'payload_retention_seconds' => $seconds,
  ];
})();
