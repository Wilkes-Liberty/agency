<?php

/**
 * @file
 * Drush php:script seed for the disposable synthetic HTTP host only.
 */

use Drupal\agency_host_synthetic\SyntheticIdentity;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Site\Settings;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

if (Settings::get('agency_synthetic_host') !== TRUE
  || \Drupal::database()->getConnectionOptions()['database'] !== 'agency_host_http_test') {
  throw new \RuntimeException('This seed requires the isolated synthetic host database.');
}
if (\Drupal::state()->get('agency_synthetic.seeded', FALSE)) {
  throw new \RuntimeException('Already seeded. Reuse the seed output; do not duplicate records.');
}
$fixture = json_decode(file_get_contents(__DIR__ . '/synthetic-host.json'), TRUE, 8, JSON_THROW_ON_ERROR);
// Test-only known-empty origin. This is never a production qualification path.
$history = \Drupal::service('agency_host.binding_history');
$database = \Drupal::database();
if ($history->mappings() !== []) {
  throw new \RuntimeException('Synthetic origin must have no existing bindings.');
}
foreach (['coverage', 'history', 'association', 'invalid', 'guard'] as $suffix) {
  if ($database->select('agency_host_binding_' . $suffix, 'h')->countQuery()->execute()->fetchField()) {
    throw new \RuntimeException('Synthetic history must start empty; use a fresh disposable site.');
  }
}
$database->insert('agency_host_binding_coverage')->fields([
  'scope' => $history->scope(),
  'epoch' => bin2hex(random_bytes(32)),
  'fingerprint' => $history->fingerprint(),
  'provenance' => 'Test-only fresh synthetic installation; not upgraded data',
  'qualified' => 1,
])->execute();

\Drupal::configFactory()->getEditable('agency.settings')->setData($fixture['tenant'])->save();
foreach ([
  'employee' => [],
  'synthetic_coordinator' => [
    'coordinate agency discovery', 'suspend agency discovery access',
    'edit crm records', 'access content',
  ],
  'synthetic_bff' => ['use agency discovery participant channel'],
] as $id => $permissions) {
  $role = Role::load($id) ?? Role::create(['id' => $id, 'label' => $id]);
  foreach ($permissions as $permission) {
    $role->grantPermission($permission);
  }
  if ($id === 'synthetic_coordinator' && Settings::get('agency_delivery_synthetic') === TRUE) {
    $role->grantPermission('approve agency discovery invitation delivery');
  }
  $role->save();
}
$coordinator = User::create([
  'name' => 'synthetic-coordinator',
  'pass' => 'synthetic-only',
  'status' => 1,
  'roles' => ['employee', 'synthetic_coordinator'],
]);
$coordinator->save();
$service = User::create(['name' => 'synthetic-bff', 'status' => 1, 'roles' => ['synthetic_bff']]);
$service->save();
$scope = Oauth2Scope::create([
  'name' => 'discovery',
  'description' => 'Synthetic Discovery scope',
  'grant_types' => ['client_credentials' => ['status' => TRUE, 'description' => 'Synthetic']],
  'umbrella' => FALSE,
  'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
  'granularity_configuration' => ['role' => 'synthetic_bff'],
]);
$scope->save();
Consumer::create([
  'client_id' => 'synthetic-bff',
  'label' => 'Synthetic BFF',
  'grant_types' => ['client_credentials'],
  'scopes' => [$scope->id()],
  'secret' => 'synthetic-only',
  'user_id' => $service->id(),
  'confidential' => TRUE,
  'is_default' => TRUE,
])->save();
$key_directory = Settings::get('file_private_path') . '/oauth';
\Drupal::service('file_system')->mkdir($key_directory, 0700, TRUE);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $private);
file_put_contents($key_directory . '/private.key', $private);
chmod($key_directory . '/private.key', 0600);
file_put_contents($key_directory . '/public.key', openssl_pkey_get_details($key)['key']);
\Drupal::configFactory()->getEditable('simple_oauth.settings')
  ->set('private_key', $key_directory . '/private.key')
  ->set('public_key', $key_directory . '/public.key')->save();
\Drupal::currentUser()->setAccount($coordinator);
$result = ['tenant' => $fixture['tenant']['display_name'], 'subjects' => []];
foreach ($fixture['subjects'] as $person) {
  $input = [
    'display_name' => $person['id'],
    'contact' => ['kind' => 'email', 'value' => $person['email']],
    'owner_contact' => ['kind' => 'url', 'value' => 'https://example.test/help'],
    'next_action' => [
      'summary' => 'Choose whether to talk.',
      'why_it_helps' => 'Learn what happens next.',
      'side' => 'participant',
      'expected_by' => time() + 3600,
      'optional' => TRUE,
    ],
  ];
  $start = \Drupal::service('agency_host.identity')->withIntendedSubject(
    $person['id'], $input['contact'],
    fn(): array => \Drupal::service('agency_staff.commands')->startDiscovery($input, 'seed-' . $person['id']),
  );
  if (($start['outcome'] ?? NULL) !== 'committed') {
    throw new \RuntimeException('Synthetic preparation failed: ' . json_encode($start));
  }
  $id = $start['receipt']['relationship_id'];
  $issued = \Drupal::service('agency_staff.invitations')->issue($id, 'issue-' . $person['id'], 1);
  if (($issued['outcome'] ?? NULL) !== 'committed') {
    throw new \RuntimeException('Synthetic issue failed: ' . json_encode($issued));
  }
  $result['subjects'][$person['id']] = [
    'relationship_id' => $id,
    'invitation_token' => $issued['invitation']['token'],
    'identity_token' => SyntheticIdentity::proof($person['id']),
  ];
}
\Drupal::state()->set('agency_synthetic.seeded', TRUE);
print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
