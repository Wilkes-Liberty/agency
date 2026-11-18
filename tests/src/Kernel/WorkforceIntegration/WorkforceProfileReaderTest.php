<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\WorkforceIntegration;

use Drupal\agency_workforce\Exception\WorkforceProfileReadUnavailable;
use Drupal\agency_workforce\Read\WorkforcePersonBinding;
use Drupal\agency_workforce\Read\WorkforceProfileReader;
use Drupal\agency_workforce\Read\WorkforceProfileSourceInterface;
use Drupal\agency_workforce\Read\WorkforceReadAuthority;
use Drupal\agency_workforce\Read\WorkforceReadAuthorityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves own-profile access, indistinguishable denials and signed evidence.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class WorkforceProfileReaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'key', 'encrypt', 'audit_chain', 'agency',
    'agency_workforce',
  ];

  private const ISSUER = 'https://identity.example.test/primary';
  private const OTHER_ISSUER = 'https://identity.example.test/other';
  private const SUBJECT = 'verified-subject-874';
  private const OTHER_SUBJECT = 'foreign-subject-521';
  private const TENANT = 'verified-tenant-639';
  private const OTHER_TENANT = 'foreign-tenant-413';
  private const OWN_PERSON = '00000000-0000-4000-8000-000000000001';
  private const FOREIGN_PERSON = '00000000-0000-4000-8000-000000000002';
  private const MISSING_PERSON = '00000000-0000-4000-8000-000000000003';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['audit_chain']);
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])->execute();
    Key::create([
      'id' => 'workforce_read_test',
      'label' => 'Synthetic workforce read signing key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-profile-audit-key'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'workforce_read_test')->save();
  }

  /**
   * An unconfigured installation refuses even when request input claims access.
   */
  public function testDefaultPortsDeny(): void {
    $this->assertSame($this->refusalBody(), $this->reader()->read(self::OWN_PERSON, self::SUBJECT));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found']);
  }

  /**
   * Same-tenant, entitled own access returns only the profile projection.
   */
  public function testEntitledOwnRead(): void {
    $binding = new WorkforcePersonBinding(self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT);
    $this->useReadableProfile($binding);
    $this->assertSame([
      'outcome' => 'read',
      'payload' => ['display_name' => 'Example Person'],
    ], $this->reader()->read(self::OWN_PERSON, self::SUBJECT));
    $this->assertEvidence('person.profile_listed', []);
  }

  /**
   * Foreign, missing and wrong-issuer resources disclose no profile.
   *
   * @dataProvider resourceDenials
   */
  #[DataProvider('resourceDenials')]
  public function testResourceRefusal(string $requested, ?array $identity, string $operation): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->method('current')->willReturn($this->entitled());
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $binding = $identity === NULL ? NULL : new WorkforcePersonBinding(...$identity);
    $source->expects($this->once())->method('bindingFor')->with($requested)->willReturn($binding);
    $source->expects($this->never())->method('readDisplayName');
    $this->usePorts($authority, $source);

    $requestData = [
      'tenant' => self::OTHER_TENANT,
      'sub' => self::OTHER_SUBJECT,
      'token' => 'synthetic-request-token',
      'email' => 'synthetic-person@example.test',
      'phone' => '+1-202-555-0105',
      'filename' => 'synthetic-personnel.pdf',
      'jwt' => 'synthetic-bearer-token',
      'onboard_url' => 'https://portal.example.test/synthetic-onboarding',
    ];
    $request = Request::create('/synthetic-workforce-profile', 'POST', $requestData);
    $request->headers->set('Authorization', 'Bearer ' . $requestData['jwt']);
    $this->container->get('request_stack')->push($request);
    try {
      $this->assertSame($this->refusalBody(), $this->reader()->read($requested));
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
    $this->assertEvidence($operation, ['reason' => 'not_found'], $requestData);
  }

  /**
   * Identifies resource failures including an inconsistent binding response.
   */
  public static function resourceDenials(): array {
    return [
      'A1 foreign person ID' => [
        self::FOREIGN_PERSON,
        [self::FOREIGN_PERSON, self::ISSUER, self::OTHER_SUBJECT, self::TENANT],
        'person.profile_refused',
      ],
      'A1 missing person' => [self::MISSING_PERSON, NULL, 'person.profile_refused'],
      'A1 same subject from a different issuer' => [
        self::OWN_PERSON,
        [self::OWN_PERSON, self::OTHER_ISSUER, self::SUBJECT, self::TENANT],
        'person.profile_refused',
      ],
      'A1 lookup returns a different person ID' => [
        self::FOREIGN_PERSON,
        [self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT],
        'person.profile_refused',
      ],
      'A2 foreign tenant' => [
        self::FOREIGN_PERSON,
        [self::FOREIGN_PERSON, self::ISSUER, self::SUBJECT, self::OTHER_TENANT],
        'paladin_agent.denied',
      ],
    ];
  }

  /**
   * A foreign requested subject or unusable authority stops before lookup.
   *
   * @dataProvider authorityDenials
   */
  #[DataProvider('authorityDenials')]
  public function testAuthorityRefusal(?array $claims, ?string $requestedSubject, string $requestedPerson = self::OWN_PERSON): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $context = $claims === NULL ? NULL : new WorkforceReadAuthority(...$claims);
    $authority->method('current')->willReturn($context);
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->expects($this->never())->method('bindingFor');
    $source->expects($this->never())->method('readDisplayName');
    $this->usePorts($authority, $source);
    $this->assertSame($this->refusalBody(), $this->reader()->read($requestedPerson, $requestedSubject));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found']);
  }

  /**
   * Unusable authority and a foreign selector are equivalent refusals.
   */
  public static function authorityDenials(): array {
    return [
      'A1 foreign subject argument' => [[self::ISSUER, self::SUBJECT, self::TENANT, TRUE], self::OTHER_SUBJECT],
      'no verified context' => [NULL, self::SUBJECT],
      'revoked workforce entitlement' => [[self::ISSUER, self::SUBJECT, self::TENANT, FALSE], self::SUBJECT],
      'blank issuer' => [[' ', self::SUBJECT, self::TENANT, TRUE], self::SUBJECT],
      'missing subject' => [[self::ISSUER, '', self::TENANT, TRUE], NULL],
      'missing workforce tenant' => [[self::ISSUER, self::SUBJECT, '', TRUE], self::SUBJECT],
      'numeric account ID is not a workforce Person' => [[self::ISSUER, self::SUBJECT, self::TENANT, TRUE], NULL, '7'],
    ];
  }

  /**
   * A grant revoked during binding lookup does not reach protected storage.
   */
  public function testEntitlementRevokedDuringBinding(): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->method('current')->willReturnOnConsecutiveCalls(
      $this->entitled(),
      new WorkforceReadAuthority(self::ISSUER, self::SUBJECT, self::TENANT, FALSE),
    );
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->expects($this->once())->method('bindingFor')->with(self::OWN_PERSON)
      ->willReturn(new WorkforcePersonBinding(self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT));
    $source->expects($this->never())->method('readDisplayName');
    $this->usePorts($authority, $source);
    $this->assertSame($this->refusalBody(), $this->reader()->read(self::OWN_PERSON));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found']);
  }

  /**
   * A stale binding at projection cannot produce a success payload or audit.
   */
  public function testProjectionRefusesStaleBinding(): void {
    $binding = new WorkforcePersonBinding(self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT);
    $this->useReadableProfile($binding, NULL);
    $this->assertSame($this->refusalBody(), $this->reader()->read(self::OWN_PERSON));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found']);
  }

  /**
   * Revocation during projection discards the value before the success audit.
   */
  public function testEntitlementRevokedDuringProjection(): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->method('current')->willReturnOnConsecutiveCalls(
      $this->entitled(),
      $this->entitled(),
      new WorkforceReadAuthority(self::ISSUER, self::SUBJECT, self::TENANT, FALSE),
    );
    $binding = new WorkforcePersonBinding(self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT);
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->method('bindingFor')->with(self::OWN_PERSON)->willReturn($binding);
    $source->expects($this->once())->method('readDisplayName')->with($binding)->willReturn('Example Person');
    $this->usePorts($authority, $source);
    $this->assertSame($this->refusalBody(), $this->reader()->read(self::OWN_PERSON));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found']);
  }

  /**
   * A provider failure exposes neither diagnostics nor resource data.
   */
  public function testProviderFailureIsSanitized(): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->method('current')->willThrowException(new \RuntimeException('synthetic-provider-secret'));
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->expects($this->never())->method('bindingFor');
    $source->expects($this->never())->method('readDisplayName');
    $this->usePorts($authority, $source);
    $this->assertSame($this->refusalBody(), $this->reader()->read(self::OWN_PERSON));
    $this->assertEvidence('person.profile_refused', ['reason' => 'not_found'], ['synthetic-provider-secret']);
  }

  /**
   * A valid read yields no projection when audit signing is unavailable.
   */
  public function testSigningFailurePreventsDisclosure(): void {
    $this->config('audit_chain.settings')->set('hash_key', '')->save();
    $binding = new WorkforcePersonBinding(self::OWN_PERSON, self::ISSUER, self::SUBJECT, self::TENANT);
    $this->useReadableProfile($binding);
    try {
      $this->reader()->read(self::OWN_PERSON);
      $this->fail('A read must abort when no signed evidence can be written.');
    }
    catch (WorkforceProfileReadUnavailable $exception) {
      $this->assertSame('The workforce profile read is unavailable.', $exception->getMessage());
      $this->assertNull($exception->getPrevious());
      $rows = $this->container->get('database')->select('audit_chain_log', 'l')
        ->countQuery()->execute()->fetchField();
      $this->assertSame(0, (int) $rows);
    }
  }

  /**
   * A caller transaction cannot undo a required audit after a response.
   */
  public function testAmbientTransactionCannotDisclose(): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->expects($this->never())->method('current');
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->expects($this->never())->method('bindingFor');
    $source->expects($this->never())->method('readDisplayName');
    $this->usePorts($authority, $source);

    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage('Workforce profile reads require an independent audit append.');
      $this->reader()->read(self::OWN_PERSON);
    }
    finally {
      $transaction->rollBack();
    }
  }

  /**
   * Returns the service under test from the installed module's container.
   */
  private function reader(): WorkforceProfileReader {
    return $this->container->get('agency_workforce.profile_reader');
  }

  /**
   * Returns synthetic current authority for the own workforce projection.
   */
  private function entitled(): WorkforceReadAuthority {
    return new WorkforceReadAuthority(self::ISSUER, self::SUBJECT, self::TENANT, TRUE);
  }

  /**
   * Configures an authorized binding and its projection result.
   */
  private function useReadableProfile(WorkforcePersonBinding $binding, ?string $displayName = 'Example Person'): void {
    $authority = $this->createMock(WorkforceReadAuthorityInterface::class);
    $authority->method('current')->willReturn($this->entitled());
    $source = $this->createMock(WorkforceProfileSourceInterface::class);
    $source->expects($this->once())->method('bindingFor')->with(self::OWN_PERSON)->willReturn($binding);
    $source->expects($this->once())->method('readDisplayName')->with($binding)->willReturn($displayName);
    $this->usePorts($authority, $source);
  }

  /**
   * Installs fixture adapters before resolving the shipped reader service.
   */
  private function usePorts(WorkforceReadAuthorityInterface $authority, WorkforceProfileSourceInterface $source): void {
    $this->container->set('agency_workforce.read_authority', $authority);
    $this->container->set('agency_workforce.profile_source', $source);
  }

  /**
   * Loads the caller-facing identity denial fixture.
   */
  private function refusalBody(): array {
    $json = file_get_contents(dirname(__DIR__, 3) . '/fixtures/s2-identity-deny.json');
    $this->assertIsString($json);
    return json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Verifies exactly one signed, minimized row was durably appended.
   */
  private function assertEvidence(string $operation, array $metadata, array $secrets = []): void {
    $rows = $this->container->get('database')->select('audit_chain_log', 'l')
      ->fields('l')->execute()->fetchAll();
    $this->assertCount(1, $rows);
    $row = (array) $rows[0];
    $this->assertSame('agency_workforce', $row['channel']);
    $this->assertSame($operation, $row['operation']);
    $this->assertSame($metadata, json_decode($row['metadata'], TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertSame('workforce_read_test', $row['key_id']);
    $this->assertNull($row['entity_type']);
    $this->assertNull($row['bundle']);
    $this->assertSame('', $row['entity_id']);
    $this->assertNull($row['entity_label']);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
    $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $identityValues = [
      self::ISSUER, self::OTHER_ISSUER, self::SUBJECT, self::OTHER_SUBJECT,
      self::TENANT, self::OTHER_TENANT, self::OWN_PERSON, self::FOREIGN_PERSON,
      self::MISSING_PERSON,
    ];
    foreach ([...$identityValues, ...$secrets] as $secret) {
      $this->assertStringNotContainsString($secret, $encoded);
    }
  }

}
