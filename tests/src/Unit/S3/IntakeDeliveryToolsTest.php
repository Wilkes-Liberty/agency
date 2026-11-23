<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\Exception\UncertainDelivery;
use Drupal\agency_intake\IntakeDeliveryWorker;
use Drupal\agency_intake\IntakePrincipal;
use Drupal\agency_intake\MemoryDispatchKeyStore;
use Drupal\agency_intake\UnavailableIntakeDeliveryWorker;
use Drupal\agency_intake\UnavailableIntakeTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves deny-closed queued delivery and tools hatch omit payload.
 *
 * @group agency
 */
#[Group('agency')]
final class IntakeDeliveryToolsTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_intake\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_intake/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Hatch delivery and tools without a tenant claim are missing_tenant_claim.
   */
  public function testDeliveryAndToolsMissingTenantClaim(): void {
    $delivery = new UnavailableIntakeDeliveryWorker();
    $occupied = new IntakeDeliveryWorker(new MemoryDispatchKeyStore());
    $tools = new UnavailableIntakeTools();
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new IntakePrincipal($claim);
      foreach ([$delivery, $occupied] as $worker) {
        try {
          $worker->process($principal, '1');
          $this->fail('Delivery process without tenant must be refused.');
        }
        catch (IntakeRefusal $exception) {
          $this->assertRefusal(
            $exception,
            IntakeRefusal::MISSING_TENANT_CLAIM,
          );
        }
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
   * Missing and foreign queued intents share not_found without payload.
   */
  public function testDeliveryAndToolsShareNotFoundWithoutPayload(): void {
    $delivery = new UnavailableIntakeDeliveryWorker();
    $tools = new UnavailableIntakeTools();
    $owner = new IntakePrincipal('tenant-a');
    $foreign = new IntakePrincipal('tenant-b');
    $bodies = [
      $this->refuseProcess($delivery, $owner, 'missing'),
      $this->refuseProcess($delivery, $foreign, '1'),
      $this->refuseProcess($delivery, $owner, ''),
      $this->refuseStatus($tools, $owner, '1'),
      $this->refuseLookup($tools, $owner, 'ext-1'),
      $this->refusePending($tools, $owner),
      $this->refusePending($tools, $foreign),
    ];
    foreach ($bodies as $body) {
      $this->assertSame($bodies[0], $body);
    }
  }

  /**
   * Reserved and uncertain occupancy reuse UncertainDelivery, not a reason.
   */
  public function testPendingOccupancyIsUncertainDelivery(): void {
    $keys = new MemoryDispatchKeyStore();
    $worker = new IntakeDeliveryWorker($keys);
    $principal = new IntakePrincipal('tenant-a');
    $keys->reserve('intake.dispatch.reserved', 'digest-a');
    try {
      $worker->process($principal, 'intake.dispatch.reserved');
      $this->fail('Reserved occupancy must stay uncertain.');
    }
    catch (UncertainDelivery $exception) {
      $this->assertUncertain($exception);
    }
    $keys->reserve('intake.dispatch.uncertain', 'digest-b');
    $keys->rememberUncertain('intake.dispatch.uncertain');
    try {
      $worker->process($principal, 'intake.dispatch.uncertain');
      $this->fail('Uncertain occupancy must stay uncertain.');
    }
    catch (UncertainDelivery $exception) {
      $this->assertUncertain($exception);
    }
  }

  /**
   * Caller-facing replay of a consumed key is not_found, same as missing.
   */
  public function testConsumedKeyReplayIsNotFound(): void {
    $keys = new MemoryDispatchKeyStore();
    $worker = new IntakeDeliveryWorker($keys);
    $principal = new IntakePrincipal('tenant-a');
    $keys->reserve('intake.dispatch.sent', 'digest-c');
    $keys->rememberSent('intake.dispatch.sent');
    $consumed = $this->refuseOccupied($worker, $principal, 'intake.dispatch.sent');
    $missing = $this->refuseOccupied($worker, $principal, 'missing');
    $this->assertSame($consumed, $missing);
  }

  /**
   * Asserts delivery.process is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeDeliveryWorker $delivery
   *   Deny-closed queued-delivery port.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseProcess(
    UnavailableIntakeDeliveryWorker $delivery,
    IntakePrincipal $principal,
    string $id,
  ): array {
    try {
      $delivery->process($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Delivery process must be not_found.');
  }

  /**
   * Asserts occupancy-aware consumed or missing keys are not_found.
   *
   * @param \Drupal\agency_intake\IntakeDeliveryWorker $worker
   *   Occupancy-aware queued-delivery worker.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseOccupied(
    IntakeDeliveryWorker $worker,
    IntakePrincipal $principal,
    string $id,
  ): array {
    try {
      $worker->process($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Consumed or missing key must be not_found.');
  }

  /**
   * Asserts tools.status is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeTools $tools
   *   Deny-closed governed-tools hatch.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseStatus(
    UnavailableIntakeTools $tools,
    IntakePrincipal $principal,
    string $id,
  ): array {
    try {
      $tools->status($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Tools status must be not_found.');
  }

  /**
   * Asserts tools.lookup is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeTools $tools
   *   Deny-closed governed-tools hatch.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $key
   *   Opaque external delivery key.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseLookup(
    UnavailableIntakeTools $tools,
    IntakePrincipal $principal,
    string $key,
  ): array {
    try {
      $tools->lookup($principal, $key);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Tools lookup must be not_found.');
  }

  /**
   * Asserts tools.pending is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeTools $tools
   *   Deny-closed governed-tools hatch.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refusePending(
    UnavailableIntakeTools $tools,
    IntakePrincipal $principal,
  ): array {
    try {
      $tools->pending($principal);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Tools pending must be not_found.');
  }

  /**
   * Asserts UncertainDelivery is not a public refusal reason.
   *
   * @param \Drupal\agency_intake\Exception\UncertainDelivery $exception
   *   Occupancy-preserving uncertain delivery.
   */
  private function assertUncertain(UncertainDelivery $exception): void {
    $this->assertSame('uncertain', $exception->getMessage());
    $this->assertStringNotContainsString(
      'dispatch_key',
      $exception->getMessage(),
    );
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
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    $this->assertArrayNotHasKey('external_key', $body);
    $this->assertArrayNotHasKey('request_type_id', $body);
    return $body;
  }

}
