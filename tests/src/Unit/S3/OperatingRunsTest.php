<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\OperatingRuns;
use Drupal\agency_commercial\UnavailableOperatingRuns;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies operating seats and the deny-closed run hatch.
 *
 * @group agency
 */
#[Group('agency')]
final class OperatingRunsTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_commercial\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_commercial/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Known seats expose the four lifecycle actions; others stay empty.
   */
  public function testSeatsExposeLifecycleActions(): void {
    $actions = ['claim', 'context', 'submit', 'fail'];
    foreach (['steward', 'intake', 'delivery', 'renewal'] as $seat) {
      $this->assertSame($actions, OperatingRuns::forSeat($seat));
    }
    $this->assertSame([], OperatingRuns::forSeat('unknown'));
    $this->assertSame([], OperatingRuns::forSeat('finance'));
    $this->assertSame([], OperatingRuns::forSeat('outbound'));
  }

  /**
   * Hatch without a tenant claim is missing_tenant_claim.
   */
  public function testHatchMissingTenantClaim(): void {
    $runs = new UnavailableOperatingRuns();
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
      try {
        $runs->claim($principal);
        $this->fail('Claim without tenant must be refused.');
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
   * Missing runs share not_found without a lease or draft payload.
   */
  public function testHatchShareNotFoundWithoutPayload(): void {
    $runs = new UnavailableOperatingRuns();
    $owner = new CommercialPrincipal('tenant-a');
    $foreign = new CommercialPrincipal('tenant-b');
    $bodies = [
      $this->refuseClaim($runs, $owner),
      $this->refuseClaim($runs, $foreign),
      $this->refuseContext($runs, $owner, '1', 'lease'),
      $this->refuseSubmit($runs, $owner, '1', 'lease', [
        'summary' => 'must-not-leave',
      ]),
      $this->refuseFail($runs, $owner, '1', 'lease', 'timeout'),
    ];
    foreach ($bodies as $body) {
      $this->assertSame($bodies[0], $body);
    }
  }

  /**
   * Asserts claim is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableOperatingRuns $runs
   *   Deny-closed operating-run port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseClaim(
    UnavailableOperatingRuns $runs,
    CommercialPrincipal $principal,
  ): array {
    try {
      $runs->claim($principal);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Operating claim must be not_found.');
  }

  /**
   * Asserts context is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableOperatingRuns $runs
   *   Deny-closed operating-run port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseContext(
    UnavailableOperatingRuns $runs,
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
  ): array {
    try {
      $runs->context($principal, $run_id, $lease);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Operating context must be not_found.');
  }

  /**
   * Asserts submit is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableOperatingRuns $runs
   *   Deny-closed operating-run port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   * @param array<string, mixed> $draft
   *   Candidate recommendation.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseSubmit(
    UnavailableOperatingRuns $runs,
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    array $draft,
  ): array {
    try {
      $runs->submit($principal, $run_id, $lease, $draft);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Operating submit must be not_found.');
  }

  /**
   * Asserts fail is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableOperatingRuns $runs
   *   Deny-closed operating-run port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $run_id
   *   Opaque run identifier.
   * @param string $lease
   *   Opaque lease token.
   * @param string $failure_code
   *   Allowlisted public failure code.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseFail(
    UnavailableOperatingRuns $runs,
    CommercialPrincipal $principal,
    string $run_id,
    string $lease,
    string $failure_code,
  ): array {
    try {
      $runs->fail($principal, $run_id, $lease, $failure_code);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Operating fail must be not_found.');
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
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    return $body;
  }

}
