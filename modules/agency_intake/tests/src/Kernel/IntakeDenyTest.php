<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\IntakePrincipal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deny-closed outbox and policy ports never dump or dispatch.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeDenyTest extends IntakeKernelTestBase {

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
   * Hatch outbox and policy without a tenant claim are missing_tenant_claim.
   */
  public function testMissingTenantClaimOnOutboxAndPolicy(): void {
    $outbox = $this->container->get('agency_intake.outbox');
    $policy = $this->container->get('agency_intake.policy');
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new IntakePrincipal($claim);
      try {
        $outbox->enqueue($principal, '1');
        $this->fail('Outbox enqueue without tenant must be refused.');
      }
      catch (IntakeRefusal $exception) {
        $this->assertRefusal(
          $exception,
          IntakeRefusal::MISSING_TENANT_CLAIM,
        );
      }
      try {
        $policy->allows($principal, 'form-a', 'store');
        $this->fail('Policy allow without tenant must be refused.');
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
   * Missing and foreign intents share one not_found body without payload.
   */
  public function testMissingAndForeignIntentsShareNotFound(): void {
    $submission = $this->createSubmission();
    $submission->save();
    $id = (string) $submission->id();
    $this->assertNotSame('', $id);
    $owner = new IntakePrincipal('tenant-a');
    $foreign = $this->refuseEnqueue(
      new IntakePrincipal('tenant-b'),
      $id,
    );
    $missing = $this->refuseEnqueue($owner, 'missing');
    $this->assertSame($foreign, $missing);
    $this->assertSame($foreign, $this->refuseEnqueue($owner, $id));
    $this->assertSame(
      $foreign,
      $this->refuseProcess($owner, $id, 'store'),
    );
    $this->assertSame(
      $foreign,
      $this->refuseAllows($owner, 'missing-form', 'store'),
    );
    $this->assertSame(
      $foreign,
      $this->refuseResolve($owner, 'missing-form'),
    );
  }

  /**
   * Enqueue and process never create an opportunity or activity row.
   */
  public function testEnqueueNeverCreatesOpportunity(): void {
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
    $this->refuseEnqueue($principal, $id);
    $this->refuseProcess($principal, $id, 'store');
    $this->refuseAllows($principal, 'form-a', 'store');

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
   * Access-checked intake queries stay empty while rows exist.
   */
  public function testIntakeQueryRemainsDenied(): void {
    $submission = $this->createSubmission();
    $submission->save();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $this->assertSame([], $storage->getQuery()->accessCheck(TRUE)->execute());
  }

  /**
   * Asserts outbox.enqueue refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseEnqueue(
    IntakePrincipal $principal,
    string $id,
  ): array {
    $outbox = $this->container->get('agency_intake.outbox');
    try {
      $outbox->enqueue($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Outbox enqueue must be not_found.');
  }

  /**
   * Asserts outbox.process refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   * @param string $action
   *   Opaque action name.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseProcess(
    IntakePrincipal $principal,
    string $id,
    string $action,
  ): array {
    $outbox = $this->container->get('agency_intake.outbox');
    try {
      $outbox->process($principal, $id, $action);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Outbox process must be not_found.');
  }

  /**
   * Asserts policy.allows refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $form_id
   *   Opaque form identifier.
   * @param string $action
   *   Opaque action name.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseAllows(
    IntakePrincipal $principal,
    string $form_id,
    string $action,
  ): array {
    $policy = $this->container->get('agency_intake.policy');
    try {
      $policy->allows($principal, $form_id, $action);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Policy allow must be not_found.');
  }

  /**
   * Asserts policy.resolve refuses with not_found and no payload.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $form_id
   *   Opaque form identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseResolve(
    IntakePrincipal $principal,
    string $form_id,
  ): array {
    $policy = $this->container->get('agency_intake.policy');
    try {
      $policy->resolve($principal, $form_id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal(
        $exception,
        IntakeRefusal::NOT_FOUND,
      );
    }
    $this->fail('Policy resolve must be not_found.');
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
