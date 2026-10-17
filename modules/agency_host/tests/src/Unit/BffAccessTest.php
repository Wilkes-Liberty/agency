<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\Access\BffAccess;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\user\RoleInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Verifies the participant channel's client-credentials boundary.
 */
#[CoversClass(BffAccess::class)]
#[Group('agency_host')]
final class BffAccessTest extends UnitTestCase {

  /**
   * Provides Drupal's cache-context validator for access results.
   */
  protected function setUp(): void {
    parent::setUp();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * The configured unbound BFF credential is allowed.
   */
  public function testExactClientCredentialsBffAllowed(): void {
    $this->assertTrue($this->access(TRUE, 'www-live', 'www-live', TRUE, TRUE));
  }

  /**
   * A user-bound token cannot impersonate the BFF channel.
   */
  public function testUserBoundTokenDenied(): void {
    $this->assertFalse($this->access(TRUE, 'www-live', 'www-live', FALSE, TRUE));
  }

  /**
   * Disabled, wrong-client, and unscoped callers all fail closed.
   */
  public function testConfigurationClientAndPermissionAreRequired(): void {
    $this->assertFalse($this->access(FALSE, 'www-live', 'www-live', TRUE, TRUE));
    $this->assertFalse($this->access(TRUE, 'www-live', 'other', TRUE, TRUE));
    $this->assertFalse($this->access(TRUE, 'www-live', 'www-live', TRUE, FALSE));
  }

  /**
   * An administrator permission bypass is not a scoped channel grant.
   */
  public function testImplicitAdministratorPermissionDenied(): void {
    $this->assertFalse($this->access(TRUE, 'www-live', 'www-live', TRUE, TRUE, TRUE));
  }

  /**
   * Builds a synthetic OAuth principal and returns the access decision.
   */
  private function access(bool $enabled, string $configured, string $actual, bool $unbound, bool $permission, bool $admin = FALSE): bool {
    $auth_user = $this->createMock(FieldItemListInterface::class);
    $auth_user->method('isEmpty')->willReturn($unbound);
    $token = $this->createMock(Oauth2TokenInterface::class);
    $token->method('get')->with('auth_user_id')->willReturn($auth_user);
    $token->method('getRoles')->willReturn(['scope_role']);
    $client = $this->createMock(FieldItemListInterface::class);
    $client->method('__get')->with('value')->willReturn($actual);
    $consumer = $this->createMock(Consumer::class);
    $consumer->method('get')->with('client_id')->willReturn($client);
    $principal = $this->createMock(TokenAuthUserInterface::class);
    $principal->method('getToken')->willReturn($token);
    $principal->method('getConsumer')->willReturn($consumer);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('getAccount')->willReturn($principal);
    $account->method('hasPermission')->willReturn($permission);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['enabled', $enabled],
      ['bff_client_id', $configured],
    ]);
    $settings->method('getCacheTags')->willReturn(['config:agency_host.settings']);
    $settings->method('getCacheContexts')->willReturn([]);
    $settings->method('getCacheMaxAge')->willReturn(-1);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->with('agency_host.settings')->willReturn($settings);

    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn($admin);
    $role->method('hasPermission')->willReturn($permission);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->with(['scope_role'])->willReturn([$role]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user_role')->willReturn($storage);
    return (new BffAccess($account, $config, new ExplicitPermissionChecker($entities)))->access()->isAllowed();
  }

}
