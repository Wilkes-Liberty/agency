<?php

/**
 * @file
 * Reversible failure injection in the disposable synthetic host only.
 */

use Drupal\Core\Site\Settings;

$database = \Drupal::database();
if (Settings::get('agency_synthetic_host') !== TRUE
  || $database->getConnectionOptions()['database'] !== 'agency_host_http_test') {
  throw new \RuntimeException('Failure injection requires the isolated synthetic host database.');
}
$mode = getenv('AGENCY_SYNTHETIC_FAULT');
if (!in_array($mode, ['none', 'identity', 'recording'], TRUE)) {
  throw new \InvalidArgumentException('Choose none, identity, or recording.');
}
\Drupal::state()->set('agency_synthetic.identity_failure', $mode === 'identity');
\Drupal::state()->set('agency_synthetic.recording_failure', $mode === 'recording');
print 'Synthetic fault: ' . $mode . "\n";
