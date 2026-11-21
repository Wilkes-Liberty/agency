<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once dirname(__DIR__, 4)
  . '/modules/agency_intake/src/IntakeDispatchGatewayInterface.php';
require_once __DIR__ . '/RecordingIntakeGateway.php';
require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\AllowDenyContactPolicy;
use Drupal\agency_commercial\CommercialCommands;
use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\MemoryCommercialRecordLocator;
use Drupal\agency_commercial\MemoryContactDirectory;
use Drupal\agency_intake\DispatchOrchestrator;
use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\Exception\UncertainDelivery;
use Drupal\agency_intake\IntakeCommands;
use Drupal\agency_intake\IntakePrincipal;
use Drupal\agency_intake\MemoryDispatchKeyStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves slice 3 G2 deny, replay and timeout without entity types.
 *
 * @group agency
 */
#[Group('agency')]
final class S3DenyReplayTimeoutTest extends TestCase {

  /**
   * Opaque key reused across uncertain delivery and retry.
   */
  private const DISPATCH_KEY = 'intake.dispatch.original';

  /**
   * Digest bound to that key.
   */
  private const DIGEST = 'intent-digest-a';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $map = [
        'Drupal\\agency_commercial\\' => $root
        . '/modules/agency_commercial/src/',
        'Drupal\\agency_intake\\' => $root . '/modules/agency_intake/src/',
      ];
      foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
          continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $dir . $relative . '.php';
        if (is_file($file)) {
          require_once $file;
        }
      }
    });
  }

  /**
   * Uncertain delivery retries the original key and omits it from the body.
   */
  public function testUncertainReceiptRetriesOriginalKey(): void {
    $keys = new MemoryDispatchKeyStore();
    $gateway = new RecordingIntakeGateway();
    $orchestrator = new DispatchOrchestrator($keys, $gateway);
    $gateway->next = new UncertainDelivery();
    try {
      $orchestrator->send(
        self::DISPATCH_KEY,
        ['kind' => 'intake'],
        self::DIGEST,
      );
      $this->fail('First send must be uncertain.');
    }
    catch (UncertainDelivery $exception) {
      $this->assertSame('uncertain', $exception->getMessage());
      $this->assertStringNotContainsString(
        'dispatch_key',
        $exception->getMessage(),
      );
      $this->assertStringNotContainsString(
        self::DISPATCH_KEY,
        $exception->getMessage(),
      );
    }
    $this->assertSame('uncertain', $keys->occupancy(self::DISPATCH_KEY));
    $this->assertSame(1, $gateway->sends);
    $body = $orchestrator->send(
      self::DISPATCH_KEY,
      ['kind' => 'intake'],
      self::DIGEST,
    );
    $this->assertSame(['outcome' => 'sent'], $body);
    $this->assertSame(2, $gateway->sends);
    $this->assertSame(
      [self::DISPATCH_KEY, self::DISPATCH_KEY],
      $gateway->keys,
    );
    $this->assertSame('sent', $keys->occupancy(self::DISPATCH_KEY));
    $this->assertArrayNotHasKey('dispatch_key', $body);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
  }

  /**
   * A new key for a bound digest is not_found. Replay of sent is not_found.
   */
  public function testNewKeySameDigestIsRefused(): void {
    $keys = new MemoryDispatchKeyStore();
    $gateway = new RecordingIntakeGateway();
    $orchestrator = new DispatchOrchestrator($keys, $gateway);
    $body = $orchestrator->send(
      self::DISPATCH_KEY,
      ['kind' => 'intake'],
      self::DIGEST,
    );
    $this->assertSame(['outcome' => 'sent'], $body);
    $this->assertSame(1, $gateway->sends);
    S3DenyAssertions::assertPayloadAbsent($this, $body);

    try {
      $orchestrator->send(
        'intake.dispatch.other',
        ['kind' => 'intake'],
        self::DIGEST,
      );
      $this->fail('A new key for a bound digest must be refused.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertNotFound($exception);
    }
    $this->assertSame(1, $gateway->sends);

    try {
      $orchestrator->send(
        self::DISPATCH_KEY,
        ['kind' => 'intake'],
        self::DIGEST,
      );
      $this->fail('Replay of a sent key must be refused.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertNotFound($exception);
    }
    $this->assertSame(1, $gateway->sends);

    try {
      $orchestrator->send('', ['kind' => 'intake'], self::DIGEST);
      $this->fail('An empty key must be refused.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertNotFound($exception);
    }
    try {
      $orchestrator->send(
        'intake.dispatch.fresh',
        ['kind' => 'intake'],
        '',
      );
      $this->fail('An empty digest must be refused.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertNotFound($exception);
    }
    $this->assertSame(1, $gateway->sends);
  }

  /**
   * Creating an opportunity does not grant Discovery authority.
   */
  public function testCreateOpportunityLeavesDiscoveryRoleAbsent(): void {
    $commands = new CommercialCommands(new MemoryCommercialRecordLocator());
    $principal = new CommercialPrincipal('tenant-a');
    $body = $commands->createOpportunity($principal);
    $this->assertSame('provisioned', $body['outcome']);
    $this->assertSame(CommercialRefusal::ROLE_ABSENT, $body['discovery']);
    $this->assertArrayNotHasKey('relationship_id', $body);
    $this->assertArrayNotHasKey('invitation_id', $body);
    $this->assertArrayNotHasKey('entitlement_id', $body);
    $this->assertArrayNotHasKey('workspace_id', $body);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    foreach ([
      'relationship_id',
      'invitation_id',
      'entitlement_id',
      'workspace_id',
    ] as $key) {
      try {
        $commands->createOpportunity($principal, [$key => 'synthetic']);
        $this->fail($key . ' must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertNotFound($exception);
      }
    }
  }

  /**
   * Recording an activity does not grant Discovery authority.
   */
  public function testRecordActivityLeavesDiscoveryRoleAbsent(): void {
    $commands = new CommercialCommands(new MemoryCommercialRecordLocator());
    $principal = new CommercialPrincipal('tenant-a');
    $body = $commands->recordActivity($principal);
    $this->assertSame('provisioned', $body['outcome']);
    $this->assertSame(CommercialRefusal::ROLE_ABSENT, $body['discovery']);
    $this->assertArrayNotHasKey('relationship_id', $body);
    $this->assertArrayNotHasKey('invitation_id', $body);
    $this->assertArrayNotHasKey('entitlement_id', $body);
    $this->assertArrayNotHasKey('workspace_id', $body);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    foreach ([
      'relationship_id',
      'invitation_id',
      'entitlement_id',
      'workspace_id',
    ] as $key) {
      try {
        $commands->recordActivity($principal, [$key => 'synthetic']);
        $this->fail($key . ' must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertNotFound($exception);
      }
    }
  }

  /**
   * Submitting intake does not grant commercial authority.
   */
  public function testSubmitIntakeLeavesCommercialRoleAbsent(): void {
    $commands = new IntakeCommands();
    $principal = new IntakePrincipal('tenant-a');
    $body = $commands->submit($principal);
    $this->assertSame('received', $body['outcome']);
    $this->assertSame(IntakeRefusal::ROLE_ABSENT, $body['commercial']);
    $this->assertArrayNotHasKey('opportunity_id', $body);
    $this->assertArrayNotHasKey('activity_id', $body);
    $this->assertArrayNotHasKey('relationship_id', $body);
    $this->assertArrayNotHasKey('invitation_id', $body);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    foreach ([
      'opportunity_id',
      'activity_id',
      'relationship_id',
      'invitation_id',
    ] as $key) {
      try {
        $commands->submit($principal, [$key => 'synthetic']);
        $this->fail($key . ' must be refused.');
      }
      catch (IntakeRefusal $exception) {
        $this->assertNotFound($exception);
      }
    }
  }

  /**
   * Foreign tenant load and a missing id share one not_found body.
   */
  public function testForeignAndMissingShareNotFound(): void {
    $locator = new MemoryCommercialRecordLocator();
    $locator->remember('rec-1', 'tenant-a');
    $commands = new CommercialCommands($locator);
    $owner = new CommercialPrincipal('tenant-a');
    $found = $commands->loadRecord($owner, 'rec-1');
    $this->assertSame(['outcome' => 'found', 'id' => 'rec-1'], $found);
    $this->assertArrayNotHasKey('tenant_id', $found);
    S3DenyAssertions::assertPayloadAbsent($this, $found);

    try {
      $commands->loadRecord(new CommercialPrincipal('tenant-b'), 'rec-1');
      $this->fail('Foreign tenant must be not_found.');
    }
    catch (CommercialRefusal $exception) {
      $this->assertNotFound($exception);
      $foreign = $exception->outcome();
    }
    try {
      $commands->loadRecord($owner, 'rec-missing');
      $this->fail('Missing id must be not_found.');
    }
    catch (CommercialRefusal $exception) {
      $this->assertNotFound($exception);
      $missing = $exception->outcome();
    }
    $this->assertSame($foreign, $missing);
    $this->assertSame(
      ['outcome' => 'refused', 'reason' => CommercialRefusal::NOT_FOUND],
      $foreign,
    );
  }

  /**
   * Null or blank hatch tenant is missing_tenant_claim, not not_found.
   */
  public function testMissingTenantClaimIsNotNotFound(): void {
    $commercial = new CommercialCommands(new MemoryCommercialRecordLocator());
    $intake = new IntakeCommands();
    foreach ([NULL, '', '   '] as $claim) {
      try {
        $commercial->createOpportunity(new CommercialPrincipal($claim));
        $this->fail('Commercial hatch without tenant must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertMissingTenant($exception);
      }
      try {
        $commercial->recordActivity(new CommercialPrincipal($claim));
        $this->fail('Activity hatch without tenant must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertMissingTenant($exception);
      }
      try {
        $intake->submit(new IntakePrincipal($claim));
        $this->fail('Intake hatch without tenant must be refused.');
      }
      catch (IntakeRefusal $exception) {
        $this->assertMissingTenant($exception);
      }
    }
  }

  /**
   * Deny, missing, ambiguous and empty contact share not_found, no payload.
   */
  public function testDenyAndMissingShareNotFoundWithoutPayload(): void {
    $directory = new MemoryContactDirectory();
    $directory->remember('mailbox', 'allowed@example.test', TRUE);
    $directory->remember('mailbox', 'denied@example.test', FALSE);
    $directory->remember('mailbox', 'maybe@example.test', TRUE);
    $directory->remember('mailbox', 'maybe@example.test', FALSE);
    $policy = new AllowDenyContactPolicy($directory);
    $this->assertTrue($policy->mayContact([
      'kind' => 'mailbox',
      'value' => 'allowed@example.test',
    ]));

    $bodies = [];
    foreach ([
      ['kind' => 'mailbox', 'value' => 'denied@example.test'],
      ['kind' => 'mailbox', 'value' => 'missing@example.test'],
      ['kind' => 'mailbox', 'value' => 'maybe@example.test'],
      ['kind' => 'voice', 'value' => 'allowed@example.test'],
      ['kind' => 'mailbox', 'value' => ''],
      ['kind' => '', 'value' => 'allowed@example.test'],
    ] as $contact) {
      try {
        $policy->mayContact($contact);
        $this->fail('Contact must be not_found.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertNotFound($exception);
        $bodies[] = $exception->outcome();
        $json = json_encode($exception->outcome(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"email":', $json);
        $this->assertStringNotContainsString('"tax_id":', $json);
        $this->assertStringNotContainsString('"amount":', $json);
      }
    }
    $expected = [
      'outcome' => 'refused',
      'reason' => CommercialRefusal::NOT_FOUND,
    ];
    foreach ($bodies as $body) {
      $this->assertSame($expected, $body);
    }
  }

  /**
   * Commercial and intake source omit vendor identifiers.
   */
  public function testPackageSourceOmitsVendorIdentifiers(): void {
    $root = dirname(__DIR__, 4);
    $needles = [
      'Atlassian',
      'ServiceDesk',
      'rest/api/',
      'gateway_url',
      'api_token',
      'JiraSoftware',
    ];
    $scanned = 0;
    foreach ([
      $root . '/modules/agency_commercial/src',
      $root . '/modules/agency_intake/src',
    ] as $dir) {
      $this->assertDirectoryExists($dir);
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir),
      );
      foreach ($iterator as $file) {
        if (!$file->isFile()) {
          continue;
        }
        $source = file_get_contents($file->getPathname());
        $this->assertNotFalse($source, $file->getPathname());
        $scanned++;
        foreach ($needles as $needle) {
          $this->assertStringNotContainsString(
            $needle,
            $source,
            $file->getPathname() . ' must not contain ' . $needle,
          );
        }
      }
    }
    $this->assertGreaterThan(0, $scanned);
  }

  /**
   * Asserts a Discovery not_found body with no payload keys.
   *
   * @param \Drupal\agency_commercial\Exception\CommercialRefusal|\Drupal\agency_intake\Exception\IntakeRefusal $exception
   *   Commercial or intake refusal.
   */
  private function assertNotFound(
    CommercialRefusal|IntakeRefusal $exception,
  ): void {
    $this->assertSame(CommercialRefusal::NOT_FOUND, $exception->reason);
    $body = $exception->outcome();
    $this->assertSame(
      ['outcome' => 'refused', 'reason' => 'not_found'],
      $body,
    );
    S3DenyAssertions::assertPayloadAbsent($this, $body);
  }

  /**
   * Asserts missing_tenant_claim rather than not_found.
   *
   * @param \Drupal\agency_commercial\Exception\CommercialRefusal|\Drupal\agency_intake\Exception\IntakeRefusal $exception
   *   Commercial or intake refusal.
   */
  private function assertMissingTenant(
    CommercialRefusal|IntakeRefusal $exception,
  ): void {
    $this->assertSame(
      CommercialRefusal::MISSING_TENANT_CLAIM,
      $exception->reason,
    );
    $this->assertNotSame(CommercialRefusal::NOT_FOUND, $exception->reason);
    $body = $exception->outcome();
    $this->assertSame(
      [
        'outcome' => 'refused',
        'reason' => 'missing_tenant_claim',
      ],
      $body,
    );
    S3DenyAssertions::assertPayloadAbsent($this, $body);
  }

}
