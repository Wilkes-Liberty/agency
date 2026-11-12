<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\WorkforceIntegration;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\agency_workforce\UnavailableInvitationPorts;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Existing-bearer compatibility and local transaction failure proofs.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
class StaffInvitationTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'language', 'key', 'encrypt', 'audit_chain',
    'agency', 'agency_workforce', 'agency_workforce_test',
  ];

  protected const NOW = 1800000000;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('agency_workforce', [
      'agency_workforce_mutex', 'agency_workforce_receipt', 'agency_workforce_outbox',
    ]);
    $this->installSchema('agency_workforce_test', [
      'agency_workforce_test_invitation', 'agency_workforce_test_profile',
      'agency_workforce_test_authority',
    ]);
    $this->installConfig(['system', 'user', 'language', 'audit_chain']);
    $this->config('agency.settings')->set('display_name', 'Example Agency')->save();
    $this->config('agency_workforce_test.settings')->setData([
      'scope' => 'synthetic-workforce',
      'minimum' => 60,
      'maximum' => 3600,
      'duration' => 600,
      'default_language' => 'en',
      'languages' => ['en', 'es'],
      'clock' => self::NOW,
      'fault' => '',
      'hold_file' => '',
      'release_file' => '',
    ])->save();
    ConfigurableLanguage::createFromLangcode('es')->save();
    User::create(['name' => 'reserved', 'status' => 1])->save();
    foreach (['employee', 'contractor'] as $classification) {
      Role::create(['id' => 'synthetic_' . $classification, 'label' => $classification])->save();
    }
    Role::create([
      'id' => 'synthetic_issuer',
      'label' => 'Synthetic issuer',
      'permissions' => ['issue agency workforce invitations', 'reconcile agency workforce invitations'],
    ])->save();
    User::create(['name' => 'issuer', 'status' => 1, 'roles' => ['synthetic_issuer']])->save();
    $this->container->get('database')->insert('agency_workforce_test_authority')->fields([
      'scope' => 'synthetic-workforce', 'state' => 'legacy',
    ])->execute();
    $this->anonymousPost();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
  }

  /**
   * A pre-extraction digest and absolute expiry survive inspect and acceptance.
   */
  public function testExistingBearerAndMissingLanguageRemainCompatible(): void {
    $token = $this->legacy(['email' => ' Recipient@Example.test ', 'type' => 'contractor']);
    $before = $this->freshSnapshot();
    $view = $this->service()->inspect($token);
    $this->assertTrue($view['valid']);
    $this->assertSame('en', $view['language']);
    $this->assertSame($before, $this->freshSnapshot());
    $result = $this->service()->accept($token, 'Preferred');
    $this->assertSame('completed', $result['status']);
    $this->assertSame(0, $this->countRows('agency_workforce_test_invitation'));
    $this->assertSame(3, $this->countRows('users'));
    $this->assertSame(1, $this->countRows('agency_workforce_test_profile'));
    $profile = $this->container->get('database')->select('agency_workforce_test_profile', 'p')->fields('p')->execute()->fetchAssoc();
    $this->assertSame('contractor', $profile['classification']);
    $this->assertSame('en', $profile['language']);
    $this->assertRefused(fn () => $this->service()->accept($token), 'invalid_invitation');
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_staff'));
  }

  /**
   * Issuance uses host bounds, explicit retained language and a transient bearer.
   */
  public function testIssueClampsAndRetainsLanguageWithoutMail(): void {
    $this->container->get('current_user')->setAccount(User::load(2));
    $issued = $this->service()->issue(' Recipient@Example.test ', 'employee', 'es', 1);
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued['bearer']);
    $this->assertSame(self::NOW + 60, $issued['expires']);
    $row = $this->container->get('database')->select('agency_workforce_test_invitation', 'i')->fields('i')->execute()->fetchAssoc();
    $this->assertSame(hash('sha256', $issued['bearer']), $row['name']);
    $this->assertSame(['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => 'es'], unserialize($row['value'], ['allowed_classes' => FALSE]));
    $this->assertStringNotContainsString($issued['bearer'], $row['value']);
    $this->anonymousPost();
    $accepted = $this->service()->accept($issued['bearer']);
    $this->assertSame('completed', $accepted['status']);
    $profile = $this->container->get('database')->select('agency_workforce_test_profile', 'p')->fields('p')->execute()->fetchAssoc();
    $this->assertSame('es', $profile['language']);
    $this->assertSame(2, $this->countRows('agency_workforce_outbox'));
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Every ordinary local failure preserves the original usable invitation.
   *
   * @dataProvider localFailures
   */
  #[DataProvider('localFailures')]
  public function testLocalFailureRollsBack(string $fault, string $reason): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('fault', $fault)->save();
    if ($fault === 'outbox') {
      $this->container->get('database')->schema()->dropTable('agency_workforce_outbox');
    }
    $this->assertRefused(fn () => $this->service()->accept($token), $reason);
    if ($fault === 'outbox') {
      $this->installSchema('agency_workforce', ['agency_workforce_outbox']);
    }
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame($before, $this->freshSnapshot());
    $this->config('agency_workforce_test.settings')->set('fault', '')->save();
    $this->assertSame('completed', $this->service()->accept($token)['status']);
  }

  public static function localFailures(): array {
    return [
      'user failure' => ['user', 'failed'],
      'profile failure' => ['profile', 'failed'],
      'conditional consume affects zero' => ['consume_zero', 'storage_conflict'],
      'required evidence unavailable' => ['outbox', 'failed'],
      'changed contact under exclusion' => ['changed_contact', 'invalid_invitation'],
    ];
  }

  /**
   * Lost acknowledgement is reconciled with possession proof, never reminting.
   */
  public function testCommitAcknowledgementRequiresScopedProof(): void {
    $token = $this->legacy();
    $this->config('agency_workforce_test.settings')->set('fault', 'commit_ack')->save();
    $result = $this->service()->accept($token);
    $this->assertSame('uncertain', $result['status']);
    $this->assertArrayNotHasKey('binding', $result);
    $this->assertSame(3, $this->countRows('users'));
    $this->assertSame(0, $this->countRows('agency_workforce_test_invitation'));
    $this->assertRefused(fn () => $this->service()->reconcile($result['receipt_id'], str_repeat('B', 43)), 'forbidden');
    $receipt = $this->service()->reconcile($result['receipt_id'], $token);
    $this->assertSame('completed', $receipt['status']);
    $this->assertStringStartsWith('user:', $receipt['binding']);
    $this->config('agency_workforce_test.settings')->set('scope', 'another-scope')->save();
    $this->assertRefused(fn () => $this->service()->reconcile($result['receipt_id'], $token), 'forbidden');
  }

  /**
   * Lost successful HTTP responses can recover by bearer without a receipt id.
   */
  public function testDiscardedResponseRecoversByBearerWithoutReplay(): void {
    $token = $this->legacy();
    $this->service()->accept($token);
    $before = $this->freshSnapshot();
    $this->assertRefused(fn () => $this->service()->accept($token), 'invalid_invitation');
    $recovered = $this->service()->recover($token);
    $this->assertSame('completed', $recovered['status']);
    $this->assertStringStartsWith('user:', $recovered['binding']);
    $this->assertSame('unresolved', $this->service()->recover(str_repeat('C', 43))['status']);
    $this->assertRefused(fn () => $this->service()->recoverByDigest(hash('sha256', $token)), 'forbidden');
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->assertSame($recovered, $this->service()->recoverByDigest(hash('sha256', $token)));
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * Invalid scope, collection or binding cannot bypass SQL storage boundaries.
   */
  public function testSchemaBoundsFailClosedWithoutPartialProvisioning(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('scope', 'Invalid-Scope')->save();
    $this->assertRefused(fn () => $this->service()->accept($token), 'configuration');
    $this->config('agency_workforce_test.settings')->set('scope', 'synthetic-workforce')->set('collection', str_repeat('x', 129))->save();
    $this->assertRefused(fn () => $this->service()->accept($token), 'configuration');
    $this->config('agency_workforce_test.settings')->set('collection', '')->set('fault', 'binding_long')->save();
    $this->assertRefused(fn () => $this->service()->accept($token), 'storage_conflict');
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * A savepoint cannot be reported as durable acceptance.
   */
  public function testOuterTransactionAndContextRefuseWithoutWrites(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->assertRefused(fn () => $this->service()->accept($token), 'transaction_boundary');
    }
    finally {
      $transaction->rollBack();
      unset($transaction);
    }
    $this->container->get('request_stack')->pop();
    $this->container->get('request_stack')->push(Request::create('/onboard', 'GET'));
    $this->assertRefused(fn () => $this->service()->accept($token), 'forbidden');
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->assertRefused(fn () => $this->service()->accept($token), 'forbidden');
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * Invalid stored policy and invitation values do not reach provisioning.
   *
   * @dataProvider refusedRecords
   */
  #[DataProvider('refusedRecords')]
  public function testInvalidRecordRefuses(array $payload, int $expires, string $reason): void {
    $token = $this->legacy($payload, $expires);
    $before = $this->freshSnapshot();
    $this->assertRefused(fn () => $this->service()->accept($token), $reason);
    $this->assertSame($before, $this->freshSnapshot());
  }

  public static function refusedRecords(): array {
    return [
      'exact expiry' => [['email' => 'recipient@example.test', 'type' => 'employee'], self::NOW, 'invalid_invitation'],
      'cross-domain class' => [['email' => 'recipient@example.test', 'type' => 'discovery'], self::NOW + 600, 'invalid_invitation'],
      'removed language' => [['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => 'removed'], self::NOW + 600, 'language'],
      'corrupt language' => [['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => FALSE], self::NOW + 600, 'invalid_invitation'],
      'invalid contact' => [['email' => 'invalid', 'type' => 'employee'], self::NOW + 600, 'invalid_invitation'],
    ];
  }

  /**
   * Product defaults and missing agency/policy cannot silently perform work.
   */
  public function testMissingNamePolicyAndDefaultProvisionerDeny(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency.settings')->set('display_name', ' ')->save();
    $this->assertRefused(fn () => $this->service()->accept($token), 'configuration');
    $this->config('agency.settings')->set('display_name', 'Example Agency')->save();
    $this->config('agency_workforce_test.settings')->clear('scope')->save();
    $this->assertRefused(fn () => $this->service()->accept($token), 'configuration');
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * The supplied default provisioner cannot consume an otherwise valid bearer.
   */
  public function testDefaultProvisionerDeniesWithoutSideEffects(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->container->set('agency_workforce.provisioner', new UnavailableInvitationPorts($this->container->get('database')));
    $this->assertRefused(fn () => $this->service()->accept($token), 'unavailable');
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * Failed commit calls stay uncertain and never claim durable acceptance.
   */
  public function testCommitFailureDoesNotReportSuccess(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('fault', 'commit_before')->save();
    $result = $this->service()->accept($token);
    $this->assertSame('uncertain', $result['status']);
    $this->assertArrayNotHasKey('binding', $result);
    $this->assertSame($before, $this->freshSnapshot());
    $this->assertSame('unresolved', $this->service()->reconcile($result['receipt_id'], $token)['status']);
  }

  /**
   * A lost issuance acknowledgement cannot recover a plaintext bearer.
   */
  public function testUncertainIssuanceNeverRemintsOrReturnsBearer(): void {
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->config('agency_workforce_test.settings')->set('fault', 'commit_ack')->save();
    $result = $this->service()->issue('recipient@example.test', 'employee');
    $this->assertSame('uncertain', $result['status']);
    $this->assertArrayNotHasKey('bearer', $result);
    $this->assertSame(1, $this->countRows('agency_workforce_test_invitation'));
    $reconciled = $this->service()->reconcile($result['receipt_id']);
    $this->assertSame('completed', $reconciled['status']);
    $this->assertArrayNotHasKey('bearer', $reconciled);
    $this->assertSame(1, $this->countRows('agency_workforce_test_invitation'));
  }

  /**
   * Required intents survive a signing outage and drain exactly once afterward.
   */
  public function testPendingEvidenceDrainsOnceWithoutPrivateMetadata(): void {
    $this->installSchema('audit_chain', [
      'audit_chain_log', 'audit_chain_mutex', 'audit_chain_recovery',
      'audit_chain_checkpoint', 'audit_chain_witness_receipt',
    ]);
    $this->container->get('database')->insert('audit_chain_mutex')->fields(['id' => 1, 'locked' => 1])->execute();
    $this->container->get('current_user')->setAccount(User::load(2));
    $issued = $this->service()->issue('recipient@example.test', 'employee');
    $this->anonymousPost();
    $this->service()->accept($issued['bearer']);
    $outbox = $this->container->get('agency_workforce.outbox');
    $this->assertSame(0, $outbox->drain());
    $this->assertSame(2, $this->countRows('agency_workforce_outbox'));
    $rows = $this->container->get('database')->select('agency_workforce_outbox', 'o')->fields('o')->execute();
    while ($row = $rows->fetchAssoc()) {
      $metadata = json_decode($row['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
      $keys = array_keys($metadata);
      sort($keys);
      $this->assertSame(['classification', 'effective_at', 'event_id'], $keys);
      $this->assertStringNotContainsString('recipient@example.test', $row['metadata']);
      $this->assertStringNotContainsString($issued['bearer'], $row['metadata']);
      $this->assertNull($row['delivered']);
    }
    Key::create([
      'id' => 'workforce_test', 'label' => 'Synthetic workforce signing',
      'key_type' => 'authentication', 'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-workforce-signing-key'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'workforce_test')->save();
    $this->assertSame(2, $outbox->drain());
    $this->assertSame(0, $outbox->drain());
    $this->assertSame(2, $this->countRows('audit_chain_log'));
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  protected function service(): object {
    return $this->container->get('agency_workforce.invitations');
  }

  protected function anonymousPost(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $stack = $this->container->get('request_stack');
    while ($stack->getCurrentRequest()) {
      $stack->pop();
    }
    $stack->push(Request::create('/onboard', 'POST'));
  }

  protected function legacy(?array $payload = NULL, ?int $expires = NULL): string {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $this->container->get('database')->insert('agency_workforce_test_invitation')->fields([
      'collection' => 'synthetic.staff.invitation',
      'name' => hash('sha256', $token),
      'value' => serialize($payload ?? ['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => 'en']),
      'expire' => $expires ?? self::NOW + 600,
    ])->execute();
    return $token;
  }

  protected function assertRefused(callable $work, string $reason): void {
    try {
      $work();
      $this->fail('Expected a workforce refusal.');
    }
    catch (InvitationRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
    }
  }

  protected function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table, 't')->countQuery()->execute()->fetchField();
  }

  protected function freshSnapshot(): array {
    $database = $this->container->get('database');
    $options = $database->getConnectionOptions();
    $options['prefix'] = $database->getPrefix();
    $key = 'workforce-proof-' . bin2hex(random_bytes(5));
    Database::addConnectionInfo($key, 'default', $options);
    try {
      $fresh = Database::getConnection('default', $key);
      $snapshot = [];
      foreach (['users', 'agency_workforce_test_profile', 'agency_workforce_test_invitation', 'agency_workforce_receipt', 'agency_workforce_outbox'] as $table) {
        $result = $fresh->select($table, 't')->fields('t')->execute();
        $snapshot[$table] = [];
        while ($row = $result->fetchAssoc()) {
          $snapshot[$table][] = $row;
        }
      }
      return $snapshot;
    }
    finally {
      Database::removeConnection($key);
    }
  }

}
