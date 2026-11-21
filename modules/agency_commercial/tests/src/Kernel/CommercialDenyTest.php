<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\CommercialCommands;
use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Entity\Opportunity;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\MemoryCommercialRecordLocator;
use Drupal\agency_commercial\UnavailableCommercialRecords;
use Drupal\agency_commercial\UnavailableContactMatch;
use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\IntakeCommands;
use Drupal\agency_intake\IntakePrincipal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deny-closed record and match ports never dump or create commercial rows.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialDenyTest extends CommercialKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_intake',
  ];

  /**
   * JSON object keys that must never appear on a public refusal body.
   */
  private const PAYLOAD_KEYS = [
    'email',
    'phone',
    'tax_id',
    'amount',
    'contact_id',
    'payload',
    'opportunity_id',
    'activity_id',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('agency_intake_submission');
  }

  /**
   * Hatch and match without a tenant claim are missing_tenant_claim.
   */
  public function testMissingTenantClaimOnHatchAndMatch(): void {
    $records = $this->container->get('agency_commercial.records');
    $this->assertInstanceOf(UnavailableCommercialRecords::class, $records);
    $match = $this->container->get('agency_commercial.contact_match');
    $this->assertInstanceOf(UnavailableContactMatch::class, $match);
    $contact = [
      'kind' => 'mailbox',
      'value' => 'claim@example.test',
    ];
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
      try {
        $records->load($principal, '1');
        $this->fail('Record load without tenant must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertRefusal(
          $exception,
          CommercialRefusal::MISSING_TENANT_CLAIM,
        );
      }
      try {
        $match->lookup($principal, $contact);
        $this->fail('Match without tenant must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertRefusal(
          $exception,
          CommercialRefusal::MISSING_TENANT_CLAIM,
        );
      }
    }
  }

  /**
   * Missing and foreign records share one not_found body without payload.
   */
  public function testMissingAndForeignRecordsShareNotFound(): void {
    $opportunity = $this->createOpportunity();
    $opportunity->save();
    $id = (string) $opportunity->id();
    $this->assertNotSame('', $id);
    $owner = new CommercialPrincipal('tenant-a');
    $foreign = $this->refuseRecordLoad(
      new CommercialPrincipal('tenant-b'),
      $id,
    );
    $missing = $this->refuseRecordLoad($owner, 'missing');
    $this->assertSame($foreign, $missing);
    $this->assertSame($foreign, $this->refuseRecordLoad($owner, $id));
  }

  /**
   * A claim-only hatch never creates an opportunity or activity row.
   */
  public function testClaimOnlyNeverCreatesOpportunity(): void {
    $opportunities = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $activities = $this->container->get('entity_type.manager')
      ->getStorage('agency_activity');
    $submissions = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $submission = $submissions->create($this->claimSubmission());
    $submission->save();
    $this->assertSame(0, (int) $opportunities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $this->assertSame(0, (int) $activities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());

    $match = $this->container->get('agency_commercial.contact_match');
    try {
      $match->lookup(new CommercialPrincipal('tenant-a'), [
        'kind' => 'mailbox',
        'value' => 'claim@example.test',
      ]);
      $this->fail('A claim match must not find a contact.');
    }
    catch (CommercialRefusal $exception) {
      $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }

    $commands = new CommercialCommands(new MemoryCommercialRecordLocator());
    $body = $commands->createOpportunity(new CommercialPrincipal('tenant-a'));
    $this->assertSame('provisioned', $body['outcome']);
    $this->assertSame(CommercialRefusal::ROLE_ABSENT, $body['discovery']);
    $this->assertArrayNotHasKey('opportunity_id', $body);

    $intake = new IntakeCommands();
    $received = $intake->submit(new IntakePrincipal('tenant-a'));
    $this->assertSame('received', $received['outcome']);
    $this->assertSame(IntakeRefusal::ROLE_ABSENT, $received['commercial']);
    $this->assertArrayNotHasKey('opportunity_id', $received);

    $this->assertSame(0, (int) $opportunities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $this->assertSame(0, (int) $activities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $this->assertNotNull($submissions->load($submission->id()));
  }

  /**
   * Access-checked opportunity queries stay empty while rows exist.
   */
  public function testOpportunityQueryRemainsDenied(): void {
    $opportunity = $this->createOpportunity();
    $opportunity->save();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $this->assertSame([], $storage->getQuery()->accessCheck(TRUE)->execute());
  }

  /**
   * Stage changes through the records port do not mutate stored rows.
   */
  public function testStageBypassIsRefused(): void {
    $opportunity = $this->createOpportunity(['stage' => 'qualify']);
    $opportunity->save();
    $id = (string) $opportunity->id();
    $records = $this->container->get('agency_commercial.records');
    try {
      $records->update(
        new CommercialPrincipal('tenant-a'),
        $id,
        ['stage' => 'won'],
      );
      $this->fail('Stage bypass must be refused.');
    }
    catch (CommercialRefusal $exception) {
      $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $storage->resetCache([$opportunity->id()]);
    $loaded = $storage->load($opportunity->id());
    $this->assertInstanceOf(Opportunity::class, $loaded);
    $this->assertSame('qualify', $loaded->get('stage')->value);
  }

  /**
   * Asserts records.load refuses with not_found and no payload.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque commercial record identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseRecordLoad(
    CommercialPrincipal $principal,
    string $id,
  ): array {
    $records = $this->container->get('agency_commercial.records');
    try {
      $records->load($principal, $id);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        CommercialRefusal::NOT_FOUND,
      );
    }
    $this->fail('Record load must be not_found.');
  }

  /**
   * Builds a synthetic intake claim row.
   *
   * @return array<string, mixed>
   *   Values for agency_intake_submission.
   */
  private function claimSubmission(): array {
    return [
      'organization_uuid' => '00000000-0000-4000-8000-0000000000b1',
      'status' => 'received',
      'payload' => '{"source":"example.test"}',
      'dispatch_key' => 'intake.dispatch.claim',
      'owner_uid' => 0,
    ];
  }

  /**
   * Asserts a payload-free public refusal body.
   *
   * @param \Drupal\agency_commercial\Exception\CommercialRefusal $exception
   *   Commercial refusal.
   * @param string $reason
   *   Allowlisted public reason.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function assertRefusal(
    CommercialRefusal $exception,
    string $reason,
  ): array {
    $this->assertSame($reason, $exception->reason);
    $body = $exception->outcome();
    $this->assertSame(
      [
        'outcome' => 'refused',
        'reason' => $reason,
      ],
      $body,
    );
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    foreach (self::PAYLOAD_KEYS as $key) {
      $this->assertStringNotContainsString('"' . $key . '":', $json, $key);
      $this->assertArrayNotHasKey($key, $body, $key);
    }
    return $body;
  }

}
