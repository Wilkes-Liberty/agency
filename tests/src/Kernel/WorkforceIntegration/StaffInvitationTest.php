<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\WorkforceIntegration;

use Drupal\agency_workforce\Exception\InvitationRefusal;
use Drupal\agency_workforce\UnavailableInvitationPorts;
use Drupal\agency_workforce\InvitationService;
use Drupal\agency_workforce_test\SqlInvitationStore;
use Drupal\agency_workforce_test\DeliveryMarkerFault;
use Drupal\agency_workforce_test\LocalProvisioner;
use Drupal\audit_chain\AuditChainLoggerInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

/**
 * Existing-bearer compatibility and local transaction failure proofs.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
class StaffInvitationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'language', 'key', 'encrypt', 'audit_chain',
    'agency', 'agency_workforce', 'agency_workforce_test',
  ];

  protected const NOW = 1800000000;

  /**
   * {@inheritdoc}
   */
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
      'scope' => 'synthetic-workforce',
      'state' => 'legacy',
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
    $sessionWasStarted = $this->container->get('request_stack')->getSession()->isStarted();
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
    $this->assertSame(['authenticated', 'synthetic_contractor'], User::load($profile['uid'])->getRoles());
    $this->assertRefused(fn () => $this->service()->accept($token), 'invalid_invitation');
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
    $this->assertSame($sessionWasStarted, $this->container->get('request_stack')->getSession()->isStarted());
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_staff'));
  }

  /**
   * Issuance uses configured bounds, retained language and a transient bearer.
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
    $this->assertSame(4, $this->countRows('agency_workforce_outbox'));
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Issuance refuses client, administrator and intern classifications.
   *
   * Generic StaffUserType and provisioner refusals. Host role and profile
   * maps stay on the webcms host.
   */
  public function testIssueRefusesCrossDomainClassification(): void {
    $this->container->get('current_user')->setAccount(User::load(2));
    $before = $this->freshSnapshot();
    foreach ([
      'client',
      'partner',
      'administrator',
      'intern',
      'publisher',
      'content_editor',
      'consultant',
    ] as $kind) {
      $this->assertRefused(
        fn () => $this->service()->issue('staff@example.test', $kind),
        'invalid_invitation',
      );
    }
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * A malformed bearer inspects as invalid and never writes.
   *
   * Generic InviteTokenStore deny path. Retired claim APIs stay on the
   * host adapter.
   */
  public function testMalformedBearerInspectsInvalidWithoutWrites(): void {
    $before = $this->freshSnapshot();
    foreach ([
      '',
      'short',
      str_repeat('A', 42),
      str_repeat('A', 43) . '+',
      str_repeat('A', 44),
    ] as $bearer) {
      $view = $this->service()->inspect($bearer);
      $this->assertSame(['valid' => FALSE], $view);
    }
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * An unavailable issuer never stores an invitation.
   *
   * Generic InviteTokenStore unavailable-port case.
   */
  public function testUnavailableIssuerNeverWrites(): void {
    $this->container->get('current_user')->setAccount(User::load(2));
    $before = $this->freshSnapshot();
    $this->container->set(
      'agency_workforce.policy',
      new UnavailableInvitationPorts($this->container->get('database')),
    );
    $this->assertRefused(
      fn () => $this->service()->issue('staff@example.test', 'employee'),
      'unavailable',
    );
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * Acceptance grants only the assigned classification role.
   *
   * Generic StaffInviteProvisioner extra-role refusal. field_user_type
   * ACL and preferred-name usernames stay on the host.
   */
  public function testProvisionedAccountDoesNotGainForeignRoles(): void {
    foreach ([
      'client',
      'partner',
      'content_editor',
      'administrator',
    ] as $id) {
      if (!Role::load($id)) {
        Role::create(['id' => $id, 'label' => $id])->save();
      }
    }
    $token = $this->legacy([
      'email' => 'role-bound@example.test',
      'type' => 'employee',
    ]);
    $result = $this->service()->accept($token);
    $this->assertSame('completed', $result['status']);
    $profile = $this->container->get('database')
      ->select('agency_workforce_test_profile', 'p')
      ->fields('p')
      ->execute()
      ->fetchAssoc();
    $account = User::load($profile['uid']);
    $this->assertSame(
      ['authenticated', 'synthetic_employee'],
      $account->getRoles(),
    );
    foreach ([
      'client',
      'partner',
      'content_editor',
      'administrator',
    ] as $id) {
      $this->assertFalse($account->hasRole($id));
    }
  }

  /**
   * The test provisioner keeps usernames within Drupal's length bound.
   *
   * Host StaffAccountProvisioner derives names from preferred name; that
   * mapping stays on the host. The synthetic provisioner still respects
   * the Drupal username ceiling.
   */
  public function testProvisionedUsernameStaysWithinDrupalLimit(): void {
    $token = $this->legacy([
      'email' => 'named@example.test',
      'type' => 'contractor',
    ]);
    $result = $this->service()->accept($token, str_repeat('N', 255));
    $this->assertSame('completed', $result['status']);
    $profile = $this->container->get('database')
      ->select('agency_workforce_test_profile', 'p')
      ->fields('p')
      ->execute()
      ->fetchAssoc();
    $account = User::load($profile['uid']);
    $this->assertNotSame('', $account->getAccountName());
    $this->assertLessThanOrEqual(
      UserInterface::USERNAME_MAX_LENGTH,
      strlen($account->getAccountName()),
    );
  }

  /**
   * An existing mixed-case mailbox collides and leaves the invitation.
   *
   * Generic StaffInviteProvisioner accountExists case.
   */
  public function testExistingMixedCaseAccountIsCollision(): void {
    User::create([
      'name' => 'existing-cased',
      'mail' => 'Cased@Example.COM',
      'status' => 1,
    ])->save();
    $token = $this->legacy([
      'email' => 'cased@example.com',
      'type' => 'employee',
    ]);
    $before = $this->freshSnapshot();
    $this->assertRefused(
      fn () => $this->service()->accept($token),
      'collision',
    );
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->assertRefused(
      fn () => $this->service()->issue('cased@example.com', 'employee'),
      'collision',
    );
    $this->assertSame($before, $this->freshSnapshot());
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

  /**
   * Lists independent local failure boundaries before durable acceptance.
   */
  public static function localFailures(): array {
    return [
      'user failure' => ['user', 'failed'],
      'profile failure' => ['profile', 'failed'],
      'conditional consume affects zero' => ['consume_zero', 'storage_conflict'],
      'noop consume reports one' => ['consume_noop', 'storage_conflict'],
      'required evidence unavailable' => ['outbox', 'failed'],
      'changed contact under exclusion' => ['changed_contact', 'invalid_invitation'],
      'noop provision' => ['provision_noop', 'storage_conflict'],
      'wrong binding' => ['binding_wrong', 'storage_conflict'],
      'missing profile' => ['profile_noop', 'storage_conflict'],
    ];
  }

  /**
   * A void insert port that persists nothing cannot report an issued bearer.
   */
  public function testNoopInsertCannotReportIssued(): void {
    $this->container->get('current_user')->setAccount(User::load(2));
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('fault', 'insert_noop')->save();
    $this->assertRefused(fn () => $this->service()->issue('recipient@example.test', 'employee'), 'storage_conflict');
    $this->assertSame($before, $this->freshSnapshot());
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
    $unconfirmed = $this->service()->reconcile($result['receipt_id'], $token);
    $this->assertSame('unresolved', $unconfirmed['status']);
    $this->assertArrayNotHasKey('binding', $unconfirmed);
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
   * Consumed bearer recovery expires with its original invitation window.
   */
  public function testConsumedBearerRecoveryExpires(): void {
    $token = $this->legacy();
    $result = $this->service()->accept($token);
    $this->config('agency_workforce_test.settings')->set('clock', self::NOW + 600)->save();
    $this->assertRefused(fn () => $this->service()->recover($token), 'forbidden');
    $this->assertRefused(fn () => $this->service()->reconcile($result['receipt_id'], $token), 'forbidden');
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->assertSame('completed', $this->service()->recoverByDigest(hash('sha256', $token))['status']);
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
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('GET');
    $this->assertRefused(fn () => $this->service()->accept($token), 'forbidden');
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('POST');
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

  /**
   * Lists malformed or expired legacy records that must remain untouched.
   */
  public static function refusedRecords(): array {
    return [
      'exact expiry' => [['email' => 'recipient@example.test', 'type' => 'employee'], self::NOW, 'invalid_invitation'],
      'cross-domain class' => [
        ['email' => 'recipient@example.test', 'type' => 'discovery'],
        self::NOW + 600,
        'invalid_invitation',
      ],
      'removed language' => [
        ['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => 'removed'],
        self::NOW + 600,
        'language',
      ],
      'corrupt language' => [
        ['email' => 'recipient@example.test', 'type' => 'employee', 'langcode' => FALSE],
        self::NOW + 600,
        'invalid_invitation',
      ],
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
   * A broken no-op commit participant cannot return durable success.
   */
  public function testNoopCommitCannotReportSuccess(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('fault', 'commit_noop')->save();
    $result = $this->service()->accept($token);
    $this->assertSame('uncertain', $result['status']);
    $this->assertArrayNotHasKey('binding', $result);
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * Another connection cannot participate in the same root transaction.
   *
   * @dataProvider connectionParticipants
   */
  #[DataProvider('connectionParticipants')]
  public function testParticipantConnectionIdentity(string $participant): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $options = $this->container->get('database')->getConnectionOptions();
    $options['prefix'] = $this->container->get('database')->getPrefix();
    $key = 'foreign-workforce-' . bin2hex(random_bytes(6));
    Database::addConnectionInfo($key, 'default', $options);
    try {
      $foreign = Database::getConnection('default', $key);
      $port = $participant === 'store'
        ? new SqlInvitationStore($foreign, $this->container->get('config.factory'))
        : new LocalProvisioner($foreign, $this->container->get('entity_type.manager'), $this->container->get('config.factory'));
      $this->container->set('agency_workforce.' . $participant, $port);
      $this->assertRefused(fn () => $this->service()->accept($token), 'transaction_boundary');
      $this->assertSame($before, $this->freshSnapshot());
    }
    finally {
      Database::removeConnection($key);
    }
  }

  /**
   * Lists transaction participants whose connection identity is mandatory.
   */
  public static function connectionParticipants(): array {
    return ['storage connection' => ['store'], 'provisioner connection' => ['provisioner']];
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
    $this->installChain();
    $ids = ['ffffffff-ffff-4fff-8fff-ffffffffffff', '00000000-0000-4000-8000-000000000001'];
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturnCallback(static function () use (&$ids): string {
      return array_shift($ids) ?? (new Php())->generate();
    });
    $this->container->set('uuid', $uuid);
    $this->container->get('current_user')->setAccount(User::load(2));
    $contact = 'recipient@example.test';
    $issued = $this->service()->issue($contact, 'employee');
    $this->assertSame('invitation_issued', $this->service()->reconcile($issued['receipt_id'])['operation']);
    $this->anonymousPost();
    $this->assertTrue($this->service()->inspect($issued['bearer'])['valid']);
    $this->assertSame(1, $this->countRows('agency_workforce_outbox'));

    $url = 'https://example.test/onboard/' . $issued['bearer'];
    $name = 'Synthetic Preferred Name';
    $phone = '+15550100123';
    $subject = 'raw-subject-canary';
    $filename = 'private-example.pdf';
    $jwt = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJjYW5hcnkifQ.synthetic';
    $preferredName = implode(' ', [$name, $phone, $subject, $filename, $jwt, $url]);
    $canaries = [
      $contact, $issued['bearer'], hash('sha256', $issued['bearer']),
      $url, $name, $phone, $subject, $filename, $jwt,
    ];
    $accepted = $this->service()->accept($issued['bearer'], $preferredName);
    $this->assertSame('completed', $accepted['status']);
    $canaries[] = $accepted['binding'];
    $this->assertSame('invitation_accepted', $this->service()->recover($issued['bearer'])['operation']);
    $this->assertSame('invitation_accepted', $this->service()->reconcile($accepted['receipt_id'], $issued['bearer'])['operation']);
    $outbox = $this->container->get('agency_workforce.outbox');
    $this->assertSame(0, $outbox->drain());
    $operations = ['invite.issued', 'invite.claimed', 'invite.provisioned', 'invite.consumed'];
    $this->assertEvidence('agency_workforce_outbox', 'sequence', $operations, $canaries);
    $this->assertSame(4, (int) $this->container->get('database')->select('agency_workforce_outbox', 'o')
      ->isNull('delivered')->countQuery()->execute()->fetchField());
    $this->signingKey();
    $this->container->get('module_handler')->invoke('agency_workforce', 'cron');
    $this->assertSame(0, $outbox->drain());
    $this->assertEvidence('audit_chain_log', 'id', $operations, $canaries);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Replay and expiry are opaque refusals, with only one completed consumption.
   */
  public function testApplicationRefusesReplayAndExpiryWithSignedEvidence(): void {
    $this->installChain();
    $this->signingKey();
    $this->container->get('current_user')->setAccount(User::load(2));
    $first = $this->service()->issue('first@example.test', 'employee');
    $expires = $this->service()->issue('expires@example.test', 'contractor');
    $this->anonymousPost();
    $this->assertTrue($this->service()->inspect($expires['bearer'])['valid']);
    $accepted = $this->service()->acceptForApplication($first['bearer']);
    $this->assertSame('completed', $accepted['status']);
    $before = $this->freshSnapshot();
    $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
    $this->assertSame($denied, $this->service()->acceptForApplication($first['bearer']));
    $this->assertRefused(fn () => $this->service()->accept($first['bearer']), 'invalid_invitation');
    $this->config('agency_workforce_test.settings')->set('clock', $expires['expires'])->save();
    $this->assertSame($denied, $this->service()->acceptForApplication($expires['bearer']));
    $this->assertRefused(fn () => $this->service()->accept($expires['bearer']), 'invalid_invitation');
    $after = $this->freshSnapshot();
    unset($before['agency_workforce_outbox'], $after['agency_workforce_outbox']);
    $this->assertSame($before, $after);

    $operations = [
      'invite.issued', 'invite.issued', 'invite.claimed', 'invite.provisioned',
      'invite.consumed', 'invite.refused', 'invite.refused',
    ];
    $canaries = [
      'first@example.test', 'expires@example.test', $first['bearer'], $expires['bearer'], $accepted['binding'],
    ];
    $this->assertEvidence('agency_workforce_outbox', 'sequence', $operations, $canaries);
    $outbox = $this->container->get('agency_workforce.outbox');
    $this->assertSame(7, $outbox->drain());
    $this->assertSame(0, $outbox->drain());
    $this->assertEvidence('audit_chain_log', 'id', $operations, $canaries);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Preflight refusals record evidence outside an ambient transaction.
   */
  public function testApplicationJournalsPreflightRefusalsWithoutBusinessWrites(): void {
    $this->installChain();
    $this->signingKey();
    $token = $this->legacy();
    $invalidBearer = $token . '-malformed';
    $name = str_repeat('Private preferred name ', 16);
    $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
    $before = $this->freshSnapshot();

    $this->assertSame($denied, $this->service()->acceptForApplication($invalidBearer));
    $this->assertSame($denied, $this->service()->acceptForApplication($token, $name));
    $this->assertRefused(fn () => $this->service()->accept($invalidBearer), 'invalid_invitation');
    $this->assertRefused(fn () => $this->service()->accept($token, $name), 'invalid_invitation');
    $this->assertSame(2, $this->countRows('agency_workforce_outbox'));

    $database = $this->container->get('database');
    $transaction = $database->startTransaction();
    try {
      $this->assertSame(['status' => 'unresolved'], $this->service()->acceptForApplication($token));
      $this->assertRefused(fn () => $this->service()->accept($token), 'transaction_boundary');
      $this->assertTrue($database->inTransaction());
      $this->assertSame(2, $this->countRows('agency_workforce_outbox'));
    }
    finally {
      $transaction->rollBack();
    }
    $after = $this->freshSnapshot();
    unset($before['agency_workforce_outbox'], $after['agency_workforce_outbox']);
    $this->assertSame($before, $after);

    $operations = ['invite.refused', 'invite.refused'];
    $canaries = [$invalidBearer, $token, 'Private preferred name'];
    $this->assertEvidence('agency_workforce_outbox', 'sequence', $operations, $canaries);
    $this->assertSame(2, $this->container->get('agency_workforce.outbox')->drain());
    $this->assertEvidence('audit_chain_log', 'id', $operations, $canaries);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Refusal evidence storage must acknowledge the independent write.
   */
  public function testApplicationReturnsUnresolvedWhenRefusalEvidenceIsUnavailable(): void {
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->container->get('database')->schema()->dropTable('agency_workforce_outbox');
    try {
      $this->assertSame(['status' => 'unresolved'], $this->service()->acceptForApplication($token . '-malformed'));
    }
    finally {
      $this->installSchema('agency_workforce', ['agency_workforce_outbox']);
    }
    $this->assertSame($before, $this->freshSnapshot());
  }

  /**
   * A failure after claiming rolls back the account and its stage evidence.
   */
  public function testApplicationJournalsRefusalAfterProvisionRollback(): void {
    $this->installChain();
    $this->signingKey();
    $token = $this->legacy();
    $before = $this->freshSnapshot();
    $this->config('agency_workforce_test.settings')->set('fault', 'profile')->save();
    $this->assertSame(['outcome' => 'refused', 'reason' => 'not_found'], $this->service()->acceptForApplication($token));
    $after = $this->freshSnapshot();
    unset($before['agency_workforce_outbox'], $after['agency_workforce_outbox']);
    $this->assertSame($before, $after);
    $this->assertEvidence('agency_workforce_outbox', 'sequence', ['invite.refused'], [$token]);
    $this->assertSame(1, $this->container->get('agency_workforce.outbox')->drain());
    $this->assertEvidence('audit_chain_log', 'id', ['invite.refused'], [$token]);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Uncertain acknowledgements never manufacture a refusal.
   */
  public function testApplicationDoesNotJournalAnUncertainCommitAsRefusal(): void {
    $this->installChain();
    $this->signingKey();
    $token = $this->legacy();
    $this->config('agency_workforce_test.settings')->set('fault', 'commit_before')->save();
    $result = $this->service()->acceptForApplication($token);
    $this->assertSame(['status' => 'uncertain', 'receipt_id' => $result['receipt_id']], $result);
    $this->assertSame(0, $this->countRows('agency_workforce_outbox'));

    $this->config('agency_workforce_test.settings')->set('fault', 'commit_ack')->save();
    $result = $this->service()->acceptForApplication($token);
    $this->assertSame(['status' => 'uncertain', 'receipt_id' => $result['receipt_id']], $result);
    $this->assertSame('invitation_accepted', $this->service()->reconcile($result['receipt_id'], $token)['operation']);
    $operations = ['invite.claimed', 'invite.provisioned', 'invite.consumed'];
    $this->assertEvidence('agency_workforce_outbox', 'sequence', $operations, [$token]);
    $this->assertSame(3, $this->container->get('agency_workforce.outbox')->drain());
    $this->assertEvidence('audit_chain_log', 'id', $operations, [$token]);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Checks ordered operations and the exact signed metadata allowlist.
   */
  private function assertEvidence(string $table, string $order, array $operations, array $canaries): void {
    $rows = $this->container->get('database')->select($table, 'e')
      ->fields('e', ['operation', 'metadata'])->orderBy($order)->execute();
    $actual = [];
    $eventIds = [];
    foreach ($rows as $row) {
      $actual[] = $row->operation;
      $metadata = json_decode($row->metadata, TRUE, 32, JSON_THROW_ON_ERROR);
      $keys = array_keys($metadata);
      sort($keys);
      if ($row->operation === 'invite.refused') {
        $this->assertSame(['effective_at', 'event_id', 'reason'], $keys);
        $this->assertSame('not_found', $metadata['reason']);
      }
      else {
        $this->assertSame(['classification', 'effective_at', 'event_id'], $keys);
        $this->assertContains($metadata['classification'], ['employee', 'contractor']);
      }
      $this->assertIsInt($metadata['effective_at']);
      $this->assertNotContains($metadata['event_id'], $eventIds);
      $eventIds[] = $metadata['event_id'];
      $content = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
      foreach ($canaries as $canary) {
        $this->assertStringNotContainsString($canary, $content);
      }
    }
    $this->assertSame($operations, $actual);
  }

  /**
   * Older pending operation names remain deliverable after a source upgrade.
   */
  public function testRetainedReceiptNamedIntentsDrainUnchanged(): void {
    $this->installChain();
    $this->signingKey();
    $operations = ['invitation_issued', 'invitation_accepted'];
    foreach ($operations as $operation) {
      // These rows represent the pending queue from an earlier module version.
      $id = (new Php())->generate();
      $this->container->get('database')->insert('agency_workforce_outbox')->fields([
        'id' => $id,
        'operation' => $operation,
        'metadata' => json_encode([
          'event_id' => $id,
          'classification' => 'contractor',
          'effective_at' => self::NOW,
        ], JSON_THROW_ON_ERROR),
        'recorded' => self::NOW,
        'delivered' => NULL,
        'attempts' => 0,
      ])->execute();
    }
    $outbox = $this->container->get('agency_workforce.outbox');
    $this->assertSame(2, $outbox->drain());
    $this->assertSame(0, $outbox->drain());
    $this->assertEvidence('audit_chain_log', 'id', $operations, []);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Actual signed append rolls back with a failed same-connection marker.
   */
  public function testAuditAppendAndDeliveryMarkerAreAtomic(): void {
    $this->installChain();
    $this->signingKey();
    $this->container->get('current_user')->setAccount(User::load(2));
    $this->service()->issue('recipient@example.test', 'employee');
    $fault = new DeliveryMarkerFault($this->container->get('database'));
    $fault->install();
    try {
      $this->assertSame(0, $this->container->get('agency_workforce.outbox')->drain());
      $this->assertFalse($this->container->get('database')->inTransaction());
      $this->assertSame(0, $this->countRows('audit_chain_log'));
      $row = $this->container->get('database')->select('agency_workforce_outbox', 'o')->fields('o')->execute()->fetchAssoc();
      $this->assertNull($row['delivered']);
      $this->assertSame(1, (int) $row['attempts']);
    }
    finally {
      $fault->remove();
    }
    $this->assertSame(1, $this->container->get('agency_workforce.outbox')->drain());
    $this->assertSame(0, $this->container->get('agency_workforce.outbox')->drain());
    $this->assertSame(1, $this->countRows('audit_chain_log'));
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * Independent Drupal cron supplies a bounded, real delivery path.
   */
  public function testCronDeliversAtMostTwentyIntents(): void {
    $this->installChain();
    $this->signingKey();
    $this->container->get('current_user')->setAccount(User::load(2));
    for ($i = 0; $i < 21; $i++) {
      $this->service()->issue('recipient-' . $i . '@example.test', 'employee');
    }
    $this->container->get('module_handler')->invoke('agency_workforce', 'cron');
    $this->assertSame(20, $this->countRows('audit_chain_log'));
    $pending = $this->container->get('database')->select('agency_workforce_outbox', 'o')
      ->isNull('delivered')->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $pending);
    $this->container->get('module_handler')->invoke('agency_workforce', 'cron');
    $this->assertSame(21, $this->countRows('audit_chain_log'));
    $this->assertSame(2, $this->countRows('users'));
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Installs only the public logger's real local tables.
   */
  private function installChain(): void {
    $this->installSchema('audit_chain', [
      'audit_chain_log', 'audit_chain_mutex', 'audit_chain_recovery',
      'audit_chain_checkpoint', 'audit_chain_witness_receipt',
    ]);
    $this->container->get('database')->insert('audit_chain_mutex')->fields(['id' => 1, 'locked' => 1])->execute();
  }

  /**
   * Configures synthetic signing material through the Key module's public API.
   */
  private function signingKey(): void {
    Key::create([
      'id' => 'workforce_test',
      'label' => 'Synthetic workforce signing',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-workforce-signing-key'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'workforce_test')->save();
  }

  /**
   * The old lease can expire without releasing durable same-token exclusion.
   */
  public function testSameTokenConcurrencyBeyondOldLease(): void {
    $this->concurrentAcceptance('same_token');
  }

  /**
   * Separate tokens cannot race the same case-insensitive account contact.
   */
  public function testDifferentTokensSameContactConcurrency(): void {
    $this->concurrentAcceptance('same_contact');
  }

  /**
   * Owning authority cannot change while acceptance still holds its boundary.
   */
  public function testAuthorityChangeWaitsForAcceptanceCommit(): void {
    $this->concurrentAcceptance('authority');
  }

  /**
   * A token expiring while waiting cannot reuse the older request timestamp.
   */
  public function testExpiryIsFreshAfterWaitingForWriterBoundary(): void {
    $this->concurrentAcceptance('expiry');
  }

  /**
   * Runs two independent SQL hosts against the actual portable core service.
   */
  private function concurrentAcceptance(string $mode): void {
    $this->installSchema('agency_workforce_test', [
      'agency_workforce_test_concurrent_account',
      'agency_workforce_test_concurrent_profile',
    ]);
    $token = $this->legacy();
    $other = $mode === 'same_token' ? $token : $this->legacy([
      'email' => $mode === 'expiry' ? 'other@example.test' : ' RECIPIENT@Example.test ',
      'type' => 'contractor',
      'langcode' => 'es',
    ]);
    $database = $this->container->get('database');
    $prefix = sys_get_temp_dir() . '/workforce-concurrency-' . bin2hex(random_bytes(8));
    $files = [
      'ready' => $prefix . '-ready',
      'release' => $prefix . '-release',
      'first' => $prefix . '-first',
      'second' => $prefix . '-second',
    ];
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $moduleRoot = dirname(__DIR__, 4);
    $script = $moduleRoot . '/tests/fixtures/workforce-worker.php';
    $base = [
      'database' => $database->getConnectionOptions(),
      'module_root' => $moduleRoot,
      'namespaces' => [
        'Drupal\\agency_workforce\\' => dirname((new \ReflectionClass(InvitationService::class))->getFileName()),
        'Drupal\\agency_workforce_test\\' => dirname((new \ReflectionClass(SqlInvitationStore::class))->getFileName()),
        'Drupal\\audit_chain\\' => dirname((new \ReflectionClass(AuditChainLoggerInterface::class))->getFileName()),
      ],
    ];
    $first = new Process([PHP_BINARY, $script, $this->root, $vendor]);
    $second = new Process([PHP_BINARY, $script, $this->root, $vendor]);
    $first->setInput(json_encode($base + [
      'bearer' => $token,
      'started' => $files['first'],
      'ready' => $files['ready'],
      'release' => $files['release'],
    ], JSON_THROW_ON_ERROR));
    $second->setInput(json_encode($base + [
      'bearer' => $other,
      'started' => $files['second'],
      'mode' => $mode === 'authority' ? 'authority' : 'accept',
    ], JSON_THROW_ON_ERROR));
    $first->setTimeout(35);
    $second->setTimeout(35);
    try {
      $first->start();
      $this->waitForMarker($first, $files['ready']);
      $second->start();
      $this->waitForMarker($second, $files['second']);
      // The source's cooperative lease was five seconds. The SQL exclusion
      // must remain effective even when provisioning takes longer than that.
      usleep(6100000);
      $this->assertTrue($second->isRunning(), 'Second writer must still await the first root transaction.');
      $id = (int) file_get_contents($files['second']);
      if ($database->driver() === 'pgsql') {
        $database->query('SELECT pg_stat_clear_snapshot()');
        $waiting = $database->query("SELECT query FROM pg_stat_activity WHERE pid = :id AND wait_event_type = 'Lock'", [':id' => $id])->fetchField();
      }
      else {
        $waiting = $database->query("SELECT INFO FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = :id AND COMMAND = 'Query'", [':id' => $id])->fetchField();
      }
      $this->assertIsString($waiting);
      $this->assertTrue(str_contains($waiting, 'agency_workforce_test_authority') || str_contains($waiting, 'agency_workforce_mutex'));
      if ($mode === 'expiry') {
        $this->config('agency_workforce_test.settings')->set('clock', self::NOW + 600)->save();
      }
      touch($files['release']);
      $this->assertSame(0, $first->wait(), $first->getErrorOutput());
      $this->assertSame(0, $second->wait(), $second->getErrorOutput());
      $accepted = json_decode($first->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
      $otherResult = json_decode($second->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
      $this->assertSame('completed', $accepted['status']);
      if ($mode === 'authority') {
        $this->assertSame('authority_changed', $otherResult['status']);
        $this->assertRefused(fn () => $this->service()->accept($other), 'authority');
      }
      else {
        $this->assertSame('refused', $otherResult['status']);
        $this->assertSame(in_array($mode, ['same_token', 'expiry'], TRUE) ? 'invalid_invitation' : 'collision', $otherResult['reason']);
      }
      $this->assertSame(1, $this->countRows('agency_workforce_test_concurrent_account'));
      $this->assertSame(1, $this->countRows('agency_workforce_test_concurrent_profile'));
      $this->assertSame(1, $this->countRows('agency_workforce_receipt'));
      $this->assertSame(3, $this->countRows('agency_workforce_outbox'));
      $this->assertSame($mode === 'same_token' ? 0 : 1, $this->countRows('agency_workforce_test_invitation'));
      $this->assertSame(2, $this->countRows('users'), 'Synthetic SQL peer must not create real account privileges.');
    }
    finally {
      touch($files['release']);
      $first->stop(1);
      $second->stop(1);
      foreach ($files as $file) {
        @unlink($file);
      }
    }
  }

  /**
   * Waits for a real independent process to reach its declared SQL boundary.
   */
  private function waitForMarker(Process $worker, string $file): void {
    $deadline = microtime(TRUE) + 15;
    while (!is_file($file)) {
      if (!$worker->isRunning() || microtime(TRUE) > $deadline) {
        $this->fail('Worker did not reach its boundary: ' . $worker->getOutput() . $worker->getErrorOutput());
      }
      usleep(20000);
    }
  }

  /**
   * Returns the actual registered portable service.
   */
  protected function service(): object {
    return $this->container->get('agency_workforce.invitations');
  }

  /**
   * Retains the kernel session while selecting anonymous POST context.
   */
  protected function anonymousPost(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $stack = $this->container->get('request_stack');
    $previous = $stack->getCurrentRequest();
    $session = $previous?->hasSession() ? $previous->getSession() : NULL;
    while ($stack->getCurrentRequest()) {
      $stack->pop();
    }
    $request = Request::create('/onboard', 'POST');
    if ($session !== NULL) {
      $request->setSession($session);
    }
    $stack->push($request);
  }

  /**
   * Stores a pre-extraction-shaped record without going through issuance.
   */
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

  /**
   * Asserts a stable refusal without inspecting private adapter messages.
   */
  protected function assertRefused(callable $work, string $reason): void {
    try {
      $work();
      $this->fail('Expected a workforce refusal.');
    }
    catch (InvitationRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
    }
  }

  /**
   * Counts independently stored rows for a business invariant.
   */
  protected function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table, 't')->countQuery()->execute()->fetchField();
  }

  /**
   * Reads committed business state from an independent connection.
   */
  protected function freshSnapshot(): array {
    $database = $this->container->get('database');
    $options = $database->getConnectionOptions();
    $options['prefix'] = $database->getPrefix();
    $key = 'workforce-proof-' . bin2hex(random_bytes(5));
    Database::addConnectionInfo($key, 'default', $options);
    try {
      $fresh = Database::getConnection('default', $key);
      $snapshot = [];
      $tables = [
        'users', 'agency_workforce_test_profile', 'agency_workforce_test_invitation',
        'agency_workforce_receipt', 'agency_workforce_outbox',
      ];
      foreach ($tables as $table) {
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
