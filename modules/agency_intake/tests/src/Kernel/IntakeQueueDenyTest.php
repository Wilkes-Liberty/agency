<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\IntakePrincipal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deny-closed queued delivery and tools hatch never dump or dispatch.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeQueueDenyTest extends IntakeKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_commercial',
  ];

  /**
   * JSON object keys that must never appear on a public refusal body.
   */
  private const PAYLOAD_KEYS = [
    'email',
    'phone',
    'payload',
    'dispatch_key',
    'external_key',
    'request_type_id',
    'opportunity_id',
    'activity_id',
    'notify_route',
    'gateway_url',
    'api_token',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('agency_opportunity');
    $this->installEntitySchema('agency_activity');
  }

  /**
   * Hatch delivery and tools without a tenant claim are missing_tenant_claim.
   */
  public function testMissingTenantClaimOnDeliveryAndTools(): void {
    $delivery = $this->container->get('agency_intake.delivery');
    $tools = $this->container->get('agency_intake.tools');
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new IntakePrincipal($claim);
      try {
        $delivery->process($principal, '1');
        $this->fail('Delivery process without tenant must be refused.');
      }
      catch (IntakeRefusal $exception) {
        $this->assertRefusal(
          $exception,
          IntakeRefusal::MISSING_TENANT_CLAIM,
        );
      }
      try {
        $tools->status($principal, '1');
        $this->fail('Tools status without tenant must be refused.');
      }
      catch (IntakeRefusal $exception) {
        $this->assertRefusal(
          $exception,
          IntakeRefusal::MISSING_TENANT_CLAIM,
        );
      }
    }
  }

  /**
   * Missing and foreign queued intents share one not_found body.
   */
  public function testMissingAndForeignDeliveryShareNotFound(): void {
    $submission = $this->createSubmission();
    $submission->save();
    $id = (string) $submission->id();
    $this->assertNotSame('', $id);
    $owner = new IntakePrincipal('tenant-a');
    $foreign = $this->refuseProcess(
      new IntakePrincipal('tenant-b'),
      $id,
    );
    $missing = $this->refuseProcess($owner, 'missing');
    $this->assertSame($foreign, $missing);
    $this->assertSame($foreign, $this->refuseProcess($owner, $id));
    $this->assertSame($foreign, $this->refuseStatus($owner, $id));
    $this->assertSame($foreign, $this->refuseLookup($owner, 'ext-1'));
    $this->assertSame($foreign, $this->refusePending($owner));
  }

  /**
   * Delivery and tools never create an opportunity or activity row.
   */
  public function testDeliveryNeverCreatesOpportunity(): void {
    $opportunities = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $activities = $this->container->get('entity_type.manager')
      ->getStorage('agency_activity');
    $submission = $this->createSubmission();
    $submission->save();
    $id = (string) $submission->id();
    $this->assertSame(0, (int) $opportunities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $this->assertSame(0, (int) $activities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());

    $principal = new IntakePrincipal('tenant-a');
    $this->refuseProcess($principal, $id);
    $this->refuseStatus($principal, $id);
    $this->refuseLookup($principal, 'ext-1');
    $this->refusePending($principal);

    $this->assertSame(0, (int) $opportunities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $this->assertSame(0, (int) $activities->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute());
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $this->assertNotNull($storage->load($submission->id()));
  }

  /**
   * Asserts delivery.process refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseProcess(
    IntakePrincipal $principal,
    string $id,
  ): array {
    $delivery = $this->container->get('agency_intake.delivery');
    try {
      $delivery->process($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Delivery process must be not_found.');
  }

  /**
   * Asserts tools.status refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseStatus(
    IntakePrincipal $principal,
    string $id,
  ): array {
    $tools = $this->container->get('agency_intake.tools');
    try {
      $tools->status($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Tools status must be not_found.');
  }

  /**
   * Asserts tools.lookup refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $key
   *   Opaque external delivery key.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseLookup(
    IntakePrincipal $principal,
    string $key,
  ): array {
    $tools = $this->container->get('agency_intake.tools');
    try {
      $tools->lookup($principal, $key);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Tools lookup must be not_found.');
  }

  /**
   * Asserts tools.pending refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refusePending(IntakePrincipal $principal): array {
    $tools = $this->container->get('agency_intake.tools');
    try {
      $tools->pending($principal);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Tools pending must be not_found.');
  }

  /**
   * Asserts a payload-free public refusal body.
   *
   * @param \Drupal\agency_intake\Exception\IntakeRefusal $exception
   *   Intake refusal.
   * @param string $reason
   *   Allowlisted public reason.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function assertRefusal(
    IntakeRefusal $exception,
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
