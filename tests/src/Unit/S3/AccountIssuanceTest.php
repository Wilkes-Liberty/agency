<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\UnavailableAccountIssuance;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves deny-closed account issuance omits numbers and secrets.
 *
 * @group agency
 */
#[Group('agency')]
final class AccountIssuanceTest extends TestCase {

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
   * Hatch issue without a tenant claim is missing_tenant_claim.
   */
  public function testIssueMissingTenantClaim(): void {
    $issuance = new UnavailableAccountIssuance();
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
      try {
        $issuance->issue($principal, 'steward');
        $this->fail('Issue without tenant must be refused.');
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
   * Issuance stays not_found without an account number payload.
   */
  public function testIssueShareNotFoundWithoutPayload(): void {
    $issuance = new UnavailableAccountIssuance();
    $owner = new CommercialPrincipal('tenant-a');
    $foreign = new CommercialPrincipal('tenant-b');
    $first = $this->refuseIssue($issuance, $owner, 'steward');
    $this->assertSame(
      $first,
      $this->refuseIssue($issuance, $foreign, 'steward'),
    );
    $this->assertSame($first, $this->refuseIssue($issuance, $owner, 'unknown'));
  }

  /**
   * Asserts issue is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableAccountIssuance $issuance
   *   Deny-closed account-issuance port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $seat
   *   Operating seat name.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseIssue(
    UnavailableAccountIssuance $issuance,
    CommercialPrincipal $principal,
    string $seat,
  ): array {
    try {
      $issuance->issue($principal, $seat);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Account issuance must be not_found.');
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
    $encoded = json_encode($body, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('account_number', $encoded);
    $this->assertStringNotContainsString('client_id', $encoded);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    return $body;
  }

}
