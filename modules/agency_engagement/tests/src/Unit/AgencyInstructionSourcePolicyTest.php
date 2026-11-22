<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\InstructionSourcePolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dedicated source consumer; mixed grants and missing audit fail closed.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyInstructionSourcePolicyTest extends TestCase {

  /**
   * Enrolled dedicated consumer qualifies.
   */
  public function testDedicatedConsumerQualifies(): void {
    $policy = new InstructionSourcePolicy();
    $this->assertSame(
      [
        'enabled' => TRUE,
        'consumer_uuid' => '11111111-1111-4111-8111-111111111111',
        'source_id' => '22222222-2222-4222-8222-222222222222',
        'merchant_id' => 'merchant-1',
        'environment' => 'test',
        'profile_uuids' => ['33333333-3333-4333-8333-333333333333'],
      ],
      $policy->capability($this->attempt()),
    );
  }

  /**
   * Mixed grants, another consumer, or a human principal do not qualify.
   */
  public function testMixedGrantsAndForeignConsumerAreDenied(): void {
    $policy = new InstructionSourcePolicy();
    $this->assertNull($policy->capability($this->attempt([
      'grant_types' => ['client_credentials', 'authorization_code'],
    ])));
    $this->assertNull($policy->capability(
      $this->attempt(['consumer_uuid_match' => FALSE]),
    ));
    $this->assertNull($policy->capability(
      $this->attempt(['token_uid' => 12]),
    ));
    $this->assertSame(
      ['decision' => 'deny'],
      $policy->evaluate($this->attempt(['token_revoked' => TRUE])),
    );
  }

  /**
   * Missing instruction or audit is unavailable, not deny.
   */
  public function testMissingAuditIsUnavailable(): void {
    $policy = new InstructionSourcePolicy();
    $this->assertSame(
      ['decision' => 'unavailable'],
      $policy->evaluate($this->attempt()),
    );
    $this->assertSame(
      ['decision' => 'allow'],
      $policy->evaluate($this->attempt([
        'audit' => TRUE,
        'instruction' => ['decision' => 'allow'],
      ])),
    );
  }

  /**
   * Scope permission must be the restricted source permission.
   */
  public function testScopePermissionIsExact(): void {
    $policy = new InstructionSourcePolicy();
    $this->assertNotNull($policy->capability($this->attempt()));
    $this->assertNull($policy->capability(
      $this->attempt(['scope_permission' => 'access content']),
    ));
    $this->assertNull($policy->capability(
      $this->attempt([
        'scope_permission' => InstructionSourcePolicy::PERMISSION . ' extra',
      ]),
    ));
    $this->assertNull($policy->capability(
      $this->attempt(['token_scopes' => ['commerce_instruction']]),
    ));
  }

  /**
   * Qualified enrollment defaults.
   *
   * @param array<string, mixed> $overrides
   *   Fields that differ from a dedicated source.
   *
   * @return array<string, mixed>
   *   Attempt facts.
   */
  private function attempt(array $overrides = []): array {
    return $overrides + [
      'enabled' => TRUE,
      'consumer_uuid' => '11111111-1111-4111-8111-111111111111',
      'source_id' => '22222222-2222-4222-8222-222222222222',
      'merchant_id' => 'merchant-1',
      'environment' => 'test',
      'profile_uuids' => ['33333333-3333-4333-8333-333333333333'],
      'token_uid' => 0,
      'token_revoked' => FALSE,
      'token_expired' => FALSE,
      'token_has_user' => FALSE,
      'consumer_published' => TRUE,
      'consumer_confidential' => TRUE,
      'consumer_uuid_match' => TRUE,
      'grant_types' => ['client_credentials'],
      'token_scopes' => [InstructionSourcePolicy::SCOPE],
      'consumer_scopes' => [InstructionSourcePolicy::SCOPE],
      'scope_permission' => InstructionSourcePolicy::PERMISSION,
      'audit' => FALSE,
      'flood' => FALSE,
    ];
  }

}
