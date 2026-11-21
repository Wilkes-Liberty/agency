<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\CommercialPrincipal;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\UnavailablePrivateSearch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the deny-closed private-search hatch omits query and account text.
 *
 * @group agency
 */
#[Group('agency')]
final class PrivateSearchTest extends TestCase {

  /**
   * Query text that must never appear on a public refusal.
   */
  private const QUERY = 'field_client_account_number:secret-account';

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
   * Hatch lookup and filter without a tenant claim are missing_tenant_claim.
   */
  public function testSearchMissingTenantClaim(): void {
    $search = new UnavailablePrivateSearch();
    foreach ([NULL, '', '   '] as $claim) {
      $principal = new CommercialPrincipal($claim);
      try {
        $search->lookup($principal, self::QUERY);
        $this->fail('Search lookup without tenant must be refused.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertRefusal(
          $exception,
          CommercialRefusal::MISSING_TENANT_CLAIM,
        );
      }
      try {
        $search->filter($principal, [
          'field_client_account_number' => 'secret-account',
        ]);
        $this->fail('Search filter without tenant must be refused.');
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
   * Missing hits share not_found without query or account-number text.
   */
  public function testSearchShareNotFoundWithoutPayload(): void {
    $search = new UnavailablePrivateSearch();
    $owner = new CommercialPrincipal('tenant-a');
    $foreign = new CommercialPrincipal('tenant-b');
    $bodies = [
      $this->refuseLookup($search, $owner, self::QUERY),
      $this->refuseLookup($search, $foreign, self::QUERY),
      $this->refuseFilter($search, $owner, [
        'field_client_account_number' => 'secret-account',
      ]),
      $this->refuseFilter($search, $foreign, [
        'query' => self::QUERY,
      ]),
    ];
    foreach ($bodies as $body) {
      $this->assertSame($bodies[0], $body);
    }
  }

  /**
   * Asserts lookup is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailablePrivateSearch $search
   *   Deny-closed private-search port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $query
   *   Opaque search text.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseLookup(
    UnavailablePrivateSearch $search,
    CommercialPrincipal $principal,
    string $query,
  ): array {
    try {
      $search->lookup($principal, $query);
    }
    catch (CommercialRefusal $exception) {
      $this->assertStringNotContainsString(
        $query,
        $exception->getMessage(),
      );
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Private search lookup must be not_found.');
  }

  /**
   * Asserts filter is not_found without payload.
   *
   * @param \Drupal\agency_commercial\UnavailablePrivateSearch $search
   *   Deny-closed private-search port.
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $criteria
   *   Opaque filter criteria.
   *
   * @return array{outcome: string, reason: string}
   *   Public refusal body.
   */
  private function refuseFilter(
    UnavailablePrivateSearch $search,
    CommercialPrincipal $principal,
    array $criteria,
  ): array {
    try {
      $search->filter($principal, $criteria);
    }
    catch (CommercialRefusal $exception) {
      $this->assertStringNotContainsString(
        'field_client_account_number',
        $exception->getMessage(),
      );
      $this->assertStringNotContainsString(
        'secret-account',
        $exception->getMessage(),
      );
      return $this->assertRefusal($exception, CommercialRefusal::NOT_FOUND);
    }
    $this->fail('Private search filter must be not_found.');
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
    $this->assertStringNotContainsString(
      'field_client_account_number',
      $encoded,
    );
    $this->assertStringNotContainsString(self::QUERY, $encoded);
    $this->assertStringNotContainsString('secret-account', $encoded);
    S3DenyAssertions::assertPayloadAbsent($this, $body);
    return $body;
  }

}
