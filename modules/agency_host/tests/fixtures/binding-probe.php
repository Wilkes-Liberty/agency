<?php

/**
 * @file
 * Exercises qualified synthetic binding history with positive controls.
 */

use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\agency_host_synthetic\SyntheticIdentity;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;

if (Settings::get('agency_synthetic_host') !== TRUE
  || \Drupal::database()->getConnectionOptions()['database'] !== 'agency_host_http_test') {
  throw new \RuntimeException('This probe requires the isolated synthetic host database.');
}
$seed = json_decode(file_get_contents(getenv('AGENCY_SYNTHETIC_SEED') ?: '/tmp/agency-synthetic-seed.json'), TRUE, 8, JSON_THROW_ON_ERROR);
$id = $seed['subjects']['person-one']['relationship_id'];
\Drupal::request()->headers->set(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, SyntheticIdentity::proof('person-one'));
$reads = \Drupal::service('agency_staff.reads');
$user = User::create(['name' => 'synthetic-binding-probe', 'status' => 1]);
$user->save();
$authmap = \Drupal::service('externalauth.authmap');
$provider = 'openid_connect.sign_in_with_keycloak';
$results = [];
try {
  $results['never_bound'] = $reads->forActor($id);
  $authmap->save($user, $provider, 'person-one');
  $results['bound'] = $reads->forActor($id);
  $authmap->save($user, $provider, 'person-two');
  $results['replaced_old_subject'] = $reads->forActor($id);
  $authmap->save($user, $provider, 'person-one');
  $results['relinked_old_subject'] = $reads->forActor($id);
  $authmap->delete((int) $user->id(), $provider);
  $results['deleted_old_subject'] = $reads->forActor($id);
}
finally {
  $authmap->delete((int) $user->id());
  $user->delete();
}
$passed = TRUE;
foreach (['never_bound', 'bound'] as $positive) {
  $passed = $passed && ($results[$positive]['outcome'] ?? NULL) === 'available';
}
foreach (['replaced_old_subject', 'relinked_old_subject', 'deleted_old_subject'] as $denied) {
  $passed = $passed && ($results[$denied]['outcome'] ?? NULL) === 'refused'
    && ($results[$denied]['reason'] ?? NULL) === 'not_found';
}
$results['acceptance'] = $passed ? 'passed' : 'failed';
print json_encode($results, JSON_PRETTY_PRINT) . "\n";
if ($results['acceptance'] !== 'passed') {
  throw new \RuntimeException('Pre-live gate failed: current authmap absence cannot establish revocation history.');
}
