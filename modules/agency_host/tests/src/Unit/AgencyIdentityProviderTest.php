<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\Identity\IdentityAuthority;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\agency_host\Identity\BindingHistoryInterface;
use Drupal\agency_host\Identity\IdentityDirectoryInterface;
use Drupal\agency_host\Identity\AccessTokenValidatorInterface;
use Drupal\agency_host\Identity\CoordinatorPolicy;
use Drupal\agency_host\Identity\EmailContactPolicy;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\externalauth\AuthmapInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Proves issuer and changing-account failures at the trusted identity boundary.
 */
#[CoversClass(AgencyIdentityProvider::class)]
#[Group('agency_host')]
final class AgencyIdentityProviderTest extends UnitTestCase {

  private const ISSUER = 'https://identity.example.test/realms/example';

  /**
   * A validated JWT from a different configured issuer cannot bind this realm.
   */
  public function testExactIssuerRequired(): void {
    foreach ([self::ISSUER . '/', 'https://other.example.test/realms/example'] as $issuer) {
      $this->assertNull($this->provider($issuer)->current());
    }
    $this->assertSame(self::ISSUER, $this->provider(self::ISSUER)->current()?->issuer);
  }

  /**
   * Readback is repeated even after a prior allowed call without a Drupal user.
   */
  public function testDeactivationIsFreshAndAccountIsOptional(): void {
    $directory = $this->createMock(IdentityDirectoryInterface::class);
    $directory->expects($this->exactly(2))->method('read')->with('person')->willReturnOnConsecutiveCalls(
      ['subject' => 'person', 'active' => TRUE, 'service' => FALSE],
      ['subject' => 'person', 'active' => FALSE, 'service' => FALSE],
    );
    $provider = $this->provider(self::ISSUER, $directory);
    $this->assertTrue($provider->current()->active);
    $this->assertFalse($provider->current()->active);
  }

  /**
   * A cross-provider binding to another subject cannot retain authority.
   */
  public function testConflictingRebindingDenied(): void {
    $this->assertFalse($this->provider(self::ISSUER, rebound: TRUE)->current()->active);
  }

  /**
   * Constructs the real provider with synthetic identity facts and read ports.
   */
  private function provider(string $issuer, ?IdentityDirectoryInterface $directory = NULL, bool $rebound = FALSE): AgencyIdentityProvider {
    $claims = rtrim(strtr(base64_encode(json_encode(['iss' => $issuer, 'sub' => 'person'])), '+/', '-_'), '=');
    $request = Request::create('/');
    $request->headers->set(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, 'header.' . $claims . '.signature');
    $stack = new RequestStack();
    $stack->push($request);
    $validator = $this->createMock(AccessTokenValidatorInterface::class);
    $validator->method('isConfigured')->willReturn(TRUE);
    $validator->method('validate')->willReturn('person');
    if ($directory === NULL) {
      $directory = $this->createMock(IdentityDirectoryInterface::class);
      $directory->method('read')->willReturn(['subject' => 'person', 'active' => TRUE, 'service' => FALSE]);
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($storage);
    if ($rebound) {
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn(2);
      $user->method('isActive')->willReturn(TRUE);
      $storage->method('loadUnchanged')->willReturn($user);
    }
    $authmap = $this->createMock(AuthmapInterface::class);
    $authmap->method('getUid')->willReturn($rebound ? 2 : FALSE);
    $authmap->method('getAll')->willReturn([
      'openid_connect.sign_in_with_keycloak' => 'person',
      'openid_connect.client_portal' => $rebound ? 'other' : 'person',
    ]);
    $tokens = $this->createMock(ImmutableConfig::class);
    $tokens->method('get')->willReturnMap([
      ['paladin_issuer', self::ISSUER],
      ['paladin_audience', 'synthetic-agency'],
      ['issuer', self::ISSUER],
      ['audience', 'synthetic-agency'],
    ]);
    $host = $this->createMock(ImmutableConfig::class);
    $host->method('get')->willReturn([]);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->willReturnCallback(static fn(string $name) => $name === 'agency_host.settings' ? $host : $tokens);
    $settings = new Settings([
      'agency_host_identity' => [
        'issuer' => self::ISSUER,
        'binding_mode' => 'mapped_or_unbound',
      ],
    ]);
    $history = $this->createMock(BindingHistoryInterface::class);
    $history->method('authorize')->willReturnCallback(static fn(string $subject, callable $current): bool => $current());
    $history->method('providers')->willReturn([
      'openid_connect.sign_in_with_keycloak',
      'openid_connect.client_portal',
    ]);
    return new AgencyIdentityProvider(
      $stack, $validator, $directory, $settings, new EmailContactPolicy(),
      new CoordinatorPolicy($config), $entities, $config, $authmap, $history,
      new IdentityAuthority($settings, $config),
    );
  }

}
