<?php

/**
 * @file
 * Runtime settings for a disposable loopback-only synthetic host.
 */

$synthetic_url = getenv('AGENCY_SYNTHETIC_DB');
if (!is_string($synthetic_url) || $synthetic_url === '') {
  throw new \RuntimeException('AGENCY_SYNTHETIC_DB is required.');
}
$synthetic_parts = parse_url($synthetic_url);
if (($synthetic_parts['scheme'] ?? '') !== 'pgsql'
  || !in_array($synthetic_parts['host'] ?? '', ['127.0.0.1', 'localhost'], TRUE)
  || ($synthetic_parts['path'] ?? '') !== '/agency_host_http_test') {
  throw new \RuntimeException('Use only the isolated agency_host_http_test database on loopback.');
}
$databases['default']['default'] = [
  'driver' => 'pgsql',
  'namespace' => 'Drupal\\pgsql\\Driver\\Database\\pgsql',
  'autoload' => 'core/modules/pgsql/src/Driver/Database/pgsql/',
  'database' => 'agency_host_http_test',
  'host' => $synthetic_parts['host'],
  'port' => $synthetic_parts['port'] ?? 5432,
  'username' => rawurldecode($synthetic_parts['user'] ?? ''),
  'password' => rawurldecode($synthetic_parts['pass'] ?? ''),
  'prefix' => '',
];
$settings['hash_salt'] = 'public-synthetic-fixture-only-not-for-deployment';
$settings['agency_synthetic_host'] = TRUE;
$settings['extension_discovery_scan_tests'] = TRUE;
$settings['trusted_host_patterns'] = ['^127\.0\.0\.1$', '^localhost$'];
$settings['reverse_proxy'] = FALSE;
$settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private/synthetic';
$settings['agency_host_identity'] = [
  'issuer' => 'https://identity.example.test/realms/example',
  'binding_version' => 'synthetic-origin-v1',
  'binding_mode' => 'mapped_or_unbound',
];
$config['agency_host.settings']['enabled'] = TRUE;
$config['agency_host.settings']['bff_client_id'] = 'synthetic-bff';
$config['agency_host.identity_tokens']['paladin_issuer'] = 'https://identity.example.test/realms/example';
$config['agency_host.identity_tokens']['paladin_audience'] = 'synthetic-agency';
