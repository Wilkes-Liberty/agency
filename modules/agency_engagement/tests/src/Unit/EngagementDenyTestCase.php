<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementActionPolicy;
use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\EngagementPrincipal;
use PHPUnit\Framework\TestCase;

/**
 * Shared identity fixture and policy helpers for Slice 4 deny tests.
 */
abstract class EngagementDenyTestCase extends TestCase {

  /**
   * Policy under test.
   */
  protected function policy(): EngagementActionPolicy {
    return new EngagementActionPolicy();
  }

  /**
   * Token principal for tenant A.
   */
  protected function actorA(string $id = 'actor-a'): EngagementPrincipal {
    return new EngagementPrincipal($id, 'tenant-a');
  }

  /**
   * Token principal for tenant B.
   */
  protected function actorB(string $id = 'actor-b'): EngagementPrincipal {
    return new EngagementPrincipal($id, 'tenant-b');
  }

  /**
   * Hatch principal with no tenant.
   */
  protected function hatch(): EngagementPrincipal {
    return new EngagementPrincipal('hatch-1', '', TRUE);
  }

  /**
   * Discovery identity-deny body from the committed S2 fixture.
   *
   * @return array{outcome: string, reason: string}
   *   Shared refuse body.
   */
  protected function identityDeny(): array {
    $path = dirname(__DIR__, 5) . '/tests/fixtures/s2-identity-deny.json';
    $this->assertFileExists($path);
    $data = json_decode(
      (string) file_get_contents($path),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $this->assertIsArray($data);
    $this->assertArrayHasKey('outcome', $data);
    $this->assertArrayHasKey('reason', $data);
    $this->assertSame('refused', $data['outcome']);
    $this->assertSame('not_found', $data['reason']);
    return [
      'outcome' => (string) $data['outcome'],
      'reason' => (string) $data['reason'],
    ];
  }

  /**
   * Identity denials carry no payload and omit leak probes.
   */
  protected function assertIdentityDeny(EngagementDecision $decision): void {
    $expected = $this->identityDeny();
    $this->assertSame($expected, $decision->body());
    $this->assertSame([], $decision->payload);
    $this->assertSame([], $decision->timeDoor);
    $encoded = json_encode(
      [$decision->body(), $decision->payload, $decision->evidence],
      JSON_THROW_ON_ERROR,
    );
    foreach ([
      'token',
      'email',
      'phone',
      'sub',
      'filename',
      'jwt',
      'hourly_rate_minor',
      'rate_reference',
    ] as $probe) {
      $this->assertArrayNotHasKey($probe, $decision->payload);
      $this->assertStringNotContainsString('"' . $probe . '"', $encoded);
    }
  }

  /**
   * In-scope success keeps named other-domain roles absent.
   *
   * @param \Drupal\agency_engagement\EngagementDecision $decision
   *   Provisioned decision under test.
   * @param list<string> $absent
   *   Roles or records that must stay absent.
   */
  protected function assertProvisionedAbsent(
    EngagementDecision $decision,
    array $absent,
  ): void {
    $this->assertSame(
      EngagementDecision::OUTCOME_PROVISIONED,
      $decision->outcome,
    );
    $this->assertNull($decision->reason);
    $this->assertSame(['outcome' => 'provisioned'], $decision->body());
    foreach ($absent as $role) {
      $this->assertContains($role, $decision->absentRoles);
    }
  }

}
