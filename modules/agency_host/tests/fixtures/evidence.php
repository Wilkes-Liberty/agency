<?php

/**
 * @file
 * Checks and recovers keyed evidence delivery in the synthetic host only.
 */

use Drupal\Core\Site\Settings;
use Drupal\key\Entity\Key;

if (Settings::get('agency_synthetic_host') !== TRUE
  || \Drupal::database()->getConnectionOptions()['database'] !== 'agency_host_http_test') {
  throw new \RuntimeException('This probe requires the isolated synthetic host database.');
}
$mode = getenv('AGENCY_SYNTHETIC_EVIDENCE');
if (!in_array($mode, ['check', 'recover'], TRUE)) {
  throw new \InvalidArgumentException('Choose check or recover.');
}
if ($mode === 'recover') {
  if (!Key::load('synthetic_chain')) {
    Key::create([
      'id' => 'synthetic_chain',
      'label' => 'Synthetic chain',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-only-signing-material'],
    ])->save();
  }
  \Drupal::configFactory()->getEditable('audit_chain.settings')->set('hash_key', 'synthetic_chain')->save();
  \Drupal::service('agency_staff.outbox')->drain(1000);
}
print json_encode(\Drupal::service('agency_host.evidence_health_monitor')->check(), JSON_PRETTY_PRINT) . "\n";
