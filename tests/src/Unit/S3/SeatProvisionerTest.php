<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\SeatIsolation;
use Drupal\agency_commercial\UnavailableSeatProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves operating seats stay isolated from portal and human grants.
 *
 * @group agency
 */
#[Group('agency')]
final class SeatProvisionerTest extends TestCase {

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
   * Operating seats are not portal roles; unknown seats fail closed.
   */
  public function testOperatingSeatsAreNotPortalRoles(): void {
    foreach (['steward', 'intake', 'delivery', 'renewal'] as $seat) {
      $this->assertFalse(SeatIsolation::isPortalRole($seat));
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown operating seat.');
    SeatIsolation::isPortalRole('administrator');
  }

  /**
   * Unbounded owner scope and unknown seats fail closed.
   */
  public function testUnboundedOwnerScopeFailsClosed(): void {
    $this->assertTrue(SeatIsolation::ownersAllowed('steward', [1]));
    $this->assertFalse(SeatIsolation::ownersAllowed('steward', []));
    $this->assertFalse(SeatIsolation::ownersAllowed('unknown', [1]));
    $this->assertFalse(SeatIsolation::ownersAllowed('finance', [1]));
  }

  /**
   * Machine credentials never inherit human grants.
   */
  public function testMachineDoesNotInheritHumanGrants(): void {
    $this->assertSame(
      [],
      SeatIsolation::inheritedRoles(['administrator', 'employee']),
    );
    $this->assertSame([], SeatIsolation::inheritedRoles([]));
  }

  /**
   * Hatch provision without a tenant claim is missing_tenant_claim.
   */
  public function testProvisionMissingTenantClaim(): void {
    $provisioner = new UnavailableSeatProvisioner();
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
      try {
        $provisioner->provision($principal, 'steward', [1]);
        $this->fail('Provision without tenant must be refused.');
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
   * Provisioning stays not_found without account or secret payload.
   */
  public function testProvisionShareNotFoundWithoutPayload(): void {
    $provisioner = new UnavailableSeatProvisioner();
    $owner = new CommercialPrincipal('tenant-a');
    $foreign = new CommercialPrincipal('tenant-b');
    $first = $this->refuseProvision($provisioner, $owner, 'steward', [1]);
    $this->assertSame(
      $first,
      $this->refuseProvision($provisioner, $foreign, 'steward', [1]),
    );
    $this->assertSame(
      $first,
      $this->refuseProvision($provisioner, $owner, 'unknown', []),
    );
  }

  /**
   * Asserts provision is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableSeatProvisioner $provisioner
   *   Deny-closed seat-provisioner port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $seat
   *   Operating seat name.
   * @param list<int> $owners
   *   Explicit owner identifiers.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseProvision(
    UnavailableSeatProvisioner $provisioner,
    CommercialPrincipal $principal,
    string $seat,
    array $owners,
  ): array {
    try {
      $provisioner->provision($principal, $seat, $owners);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Seat provision must be not_found.');
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
