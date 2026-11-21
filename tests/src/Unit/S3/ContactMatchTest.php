<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\UnavailableContactMatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the deny-closed contact-match hatch omits payload.
 *
 * @group agency
 */
#[Group('agency')]
final class ContactMatchTest extends TestCase {

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
   * Hatch lookup without a tenant claim is missing_tenant_claim.
   */
  public function testLookupMissingTenantClaim(): void {
    $match = new UnavailableContactMatch();
    $contact = [
      'kind' => 'mailbox',
      'value' => 'claim@example.test',
    ];
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
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
   * Missing and foreign matches share not_found without a contact payload.
   */
  public function testLookupShareNotFoundWithoutPayload(): void {
    $match = new UnavailableContactMatch();
    $contact = [
      'kind' => 'mailbox',
      'value' => 'claim@example.test',
    ];
    $owner = $this->refuseLookup(
      $match,
      new CommercialPrincipal('tenant-a'),
      $contact,
    );
    $foreign = $this->refuseLookup(
      $match,
      new CommercialPrincipal('tenant-b'),
      $contact,
    );
    $this->assertSame($owner, $foreign);
  }

  /**
   * A claim-only hatch never returns an opportunity identifier.
   */
  public function testClaimNeverCreatesOpportunity(): void {
    $body = $this->refuseLookup(
      new UnavailableContactMatch(),
      new CommercialPrincipal('tenant-a'),
      [
        'kind' => 'mailbox',
        'value' => 'claim@example.test',
      ],
    );
    $decoded = json_decode(
      json_encode($body, JSON_THROW_ON_ERROR),
      TRUE,
    );
    $this->assertIsArray($decoded);
    foreach (['opportunity_id', 'contact_id', 'activity_id'] as $key) {
      $this->assertArrayNotHasKey($key, $decoded);
    }
  }

  /**
   * Asserts lookup is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailableContactMatch $match
   *   Deny-closed contact-match port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $contact
   *   Opaque contact of kind/value.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseLookup(
    UnavailableContactMatch $match,
    CommercialPrincipal $principal,
    array $contact,
  ): array {
    try {
      $match->lookup($principal, $contact);
    }
    catch (CommercialRefusal $exception) {
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Contact match must be not_found.');
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
