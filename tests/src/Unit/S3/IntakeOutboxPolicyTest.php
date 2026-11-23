<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\IntakePrincipal;
use Drupal\agency_intake\UnavailableIntakeOutbox;
use Drupal\agency_intake\UnavailableIntakePolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves deny-closed intake outbox and policy ports omit payload.
 *
 * @group agency
 */
#[Group('agency')]
final class IntakeOutboxPolicyTest extends TestCase {

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
   * Hatch outbox and policy without a tenant claim are missing_tenant_claim.
   */
  public function testOutboxAndPolicyMissingTenantClaim(): void {
    $outbox = new UnavailableIntakeOutbox();
    $policy = new UnavailableIntakePolicy();
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
   * Missing and foreign intents share not_found without a policy payload.
   */
  public function testOutboxAndPolicyShareNotFoundWithoutPayload(): void {
    $outbox = new UnavailableIntakeOutbox();
    $policy = new UnavailableIntakePolicy();
    $owner = new IntakePrincipal('tenant-a');
    $foreign = new IntakePrincipal('tenant-b');
    $bodies = [
      $this->refuseEnqueue($outbox, $owner, 'missing'),
      $this->refuseEnqueue($outbox, $foreign, '1'),
      $this->refuseProcess($outbox, $owner, '1', 'store'),
      $this->refuseAllows($policy, $owner, 'form-a', 'store'),
      $this->refuseResolve($policy, $owner, 'form-a'),
      $this->refuseResolve($policy, $foreign, 'form-a'),
    ];
    foreach ($bodies as $body) {
      $this->assertSame($bodies[0], $body);
    }
  }

  /**
   * Asserts outbox.enqueue is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeOutbox $outbox
   *   Deny-closed outbox port.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque intake submission identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseEnqueue(
    UnavailableIntakeOutbox $outbox,
    IntakePrincipal $principal,
    string $id,
  ): array {
    try {
      $outbox->enqueue($principal, $id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Outbox enqueue must be not_found.');
  }

  /**
   * Asserts outbox.process is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakeOutbox $outbox
   *   Deny-closed outbox port.
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
    UnavailableIntakeOutbox $outbox,
    IntakePrincipal $principal,
    string $id,
    string $action,
  ): array {
    try {
      $outbox->process($principal, $id, $action);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Outbox process must be not_found.');
  }

  /**
   * Asserts policy.allows is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakePolicy $policy
   *   Deny-closed policy port.
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
    UnavailableIntakePolicy $policy,
    IntakePrincipal $principal,
    string $form_id,
    string $action,
  ): array {
    try {
      $policy->allows($principal, $form_id, $action);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
    }
    $this->fail('Policy allow must be not_found.');
  }

  /**
   * Asserts policy.resolve is not_found without payload.
   *
   * @param \Drupal\agency_intake\UnavailableIntakePolicy $policy
   *   Deny-closed policy port.
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $form_id
   *   Opaque form identifier.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseResolve(
    UnavailableIntakePolicy $policy,
    IntakePrincipal $principal,
    string $form_id,
  ): array {
    try {
      $policy->resolve($principal, $form_id);
    }
    catch (IntakeRefusal $exception) {
      return $this->assertRefusal($exception, IntakeRefusal::NOT_FOUND);
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
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    return $body;
  }

}
