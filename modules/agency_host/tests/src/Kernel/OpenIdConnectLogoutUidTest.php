<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

require_once __DIR__ . '/IntTypedAuthmap.php';

use Drupal\KernelTests\KernelTestBase;
use Drupal\openid_connect\Controller\OpenIDConnectRedirectController;
use Drupal\openid_connect\Service\LogoutService;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Logout finishes when authmap types the user id as an integer.
 *
 * Failure mode, written before the fix: openid_connect 3.0.0-alpha9
 * LogoutService declares strict_types and passes AccountInterface::id(), a
 * numeric string, to AuthmapInterface::getAll(int). The stock externalauth
 * Authmap::getAll() leaves that argument untyped, so the string does not
 * throw there. Production decorates the service with an int-typed getAll(),
 * and this test installs the same signature. getLogoutRedirectResponse()
 * calls getAll() before user_logout() whenever end-session logout is on,
 * which is the module default. Recorded before the cast, both the unmapped
 * editor and the mapped account throw TypeError:
 * IntTypedAuthmap::getAll(): Argument #1 ($uid) must be of type int, string
 * given. The throw is LogoutService.php line 216, reached from
 * hasMappedUsers() and getLogoutRedirectResponse(), inside redirectLogout()
 * and before user_logout(), so the editor is still authenticated.
 *
 * Set OPENID_LOGOUT_DRUPAL_ROOT to a private Drupal root (the web directory)
 * to run this against a copy of openid_connect. CI leaves it unset and uses
 * the checkout, where composer-patches has already applied the cast.
 *
 * @see \Drupal\openid_connect\Service\LogoutService::getMappedUsers()
 */
#[CoversClass(LogoutService::class)]
#[CoversClass(OpenIDConnectRedirectController::class)]
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class OpenIdConnectLogoutUidTest extends KernelTestBase {

  /**
   * Uses a private Drupal root when the runner prepared one.
   *
   * Core computes the root from its own file path, which is the primary
   * checkout. Overriding after that computation keeps the red and green runs
   * off that checkout's contrib tree.
   */
  #[Before(50)]
  protected function usePrivateDrupalRoot(): void {
    $root = getenv('OPENID_LOGOUT_DRUPAL_ROOT');
    if (is_string($root) && $root !== '' && is_dir($root)) {
      $this->root = $root;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'externalauth',
    'openid_connect',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['openid_connect']);
    // End-session logout is what forces the authmap read before sign-out.
    $this->assertTrue($this->config('openid_connect.settings')->get('end_session_enabled'));
    Role::create([
      'id' => 'content_editor',
      'label' => 'Content editor',
    ])->save();
    // Occupy uid 1 so the editor is not the superuser.
    User::create([
      'name' => 'uid1-placeholder',
      'mail' => 'uid1@example.com',
      'status' => 1,
    ])->save();
    $inner = $this->container->get('externalauth.authmap');
    $this->container->set('externalauth.authmap', new IntTypedAuthmap($inner));
  }

  /**
   * A core-login editor with no OpenID mapping can sign out.
   */
  public function testUnmappedEditorLogoutEndsAnonymous(): void {
    $editor = $this->createEditor('core-editor', 'core-editor@example.com');
    $response = $this->logout($editor);
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * An account that already has an OpenID Connect mapping can sign out.
   */
  public function testMappedAccountLogoutEndsAnonymous(): void {
    $editor = $this->createEditor('mapped-editor', 'mapped-editor@example.com');
    $this->container->get('externalauth.authmap')->save(
      $editor,
      'openid_connect.sign_in_with_keycloak',
      'subject-mapped-editor',
    );
    $response = $this->logout($editor);
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());
  }

  /**
   * Creates a non-admin editor and checks the id is a numeric string.
   */
  private function createEditor(string $name, string $mail): UserInterface {
    $editor = User::create([
      'name' => $name,
      'mail' => $mail,
      'status' => 1,
      'roles' => ['content_editor'],
    ]);
    $editor->save();
    $this->assertNotSame('1', (string) $editor->id());
    $this->assertIsString($editor->id());
    $this->assertTrue(ctype_digit($editor->id()));
    $this->assertFalse($editor->hasPermission('administer site configuration'));
    return $editor;
  }

  /**
   * Signs in and runs the OpenID Connect logout controller.
   */
  private function logout(UserInterface $account): RedirectResponse {
    $this->container->get('current_user')->setAccount($account);
    $current_id = $this->container->get('current_user')->id();
    $this->assertIsString($current_id);
    $this->assertTrue(ctype_digit($current_id));
    $request = Request::create('/user/logout');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $controller = OpenIDConnectRedirectController::create($this->container);
    return $controller->redirectLogout();
  }

}
