<?php

/**
 * @file
 * Captured mail settings for a separate disposable synthetic host only.
 */

require DRUPAL_ROOT . '/modules/custom/agency_host/tests/fixtures/settings.synthetic.php';

$settings['agency_delivery_synthetic'] = TRUE;
$settings['agency_host_invitation_origin'] = 'https://participant.example.test';
$settings['agency_host_invitation_delivery'] = [
  'mode' => 'capture',
  'key_id' => 'synthetic',
  // Public fixture material, unusable as a deployment secret.
  'keys' => ['synthetic' => base64_encode(str_repeat('x', 32))],
  'payload_retention_seconds' => 3600,
  'sender' => [
    'email' => 'agency@example.test',
    'name' => 'Synthetic agency',
    'reply_to' => 'support@example.test',
  ],
];
