<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\UnavailableContactPolicyReader;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves commercial scaffolding installs, refuses and leaves no types.
 *
 * @group agency
 *
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialScaffoldingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_commercial',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
  }

  /**
   * Enabling the module creates no business surface.
   */
  public function testEnablementCreatesNoBusinessSurface(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_commercial'));
    $this->assertFalse(function_exists('agency_commercial_schema'));

    $types = array_keys(
      $this->container->get('entity_type.manager')->getDefinitions(),
    );
    foreach ([
      'agency_opportunity',
      'agency_activity',
      'agency_intake_submission',
    ] as $type) {
      $this->assertNotContains($type, $types);
    }

    $schema = $this->container->get('database')->schema();
    foreach ([
      'agency_opportunity',
      'agency_opportunity_revision',
      'agency_activity',
      'agency_activity_revision',
    ] as $table) {
      $this->assertFalse($schema->tableExists($table), $table);
    }

    $this->container->get('router.builder')->rebuild();
    $route_names = [];
    $routes = $this->container->get('router.route_provider')->getAllRoutes();
    foreach ($routes as $name => $route) {
      if (str_starts_with((string) $name, 'agency_commercial.')) {
        $route_names[] = $name;
      }
      $this->assertStringNotContainsString(
        'agency_commercial',
        $route->getPath(),
      );
    }
    $this->assertSame([], $route_names);

    $permissions = $this->container->get('user.permissions')->getPermissions();
    foreach (array_keys($permissions) as $id) {
      $this->assertStringNotContainsString('agency_commercial', $id);
    }

    $roles = $this->container->get('entity_type.manager')
      ->getStorage('user_role')
      ->loadMultiple();
    foreach ($roles as $role) {
      $this->assertStringNotContainsString('agency_commercial', $role->id());
      foreach ($role->getPermissions() as $permission) {
        $this->assertStringNotContainsString(
          'agency_commercial',
          $permission,
        );
      }
    }

    $service_ids = [];
    foreach ($this->container->getDefinitions() as $id => $definition) {
      if (str_starts_with((string) $id, 'agency_commercial.')) {
        $service_ids[] = $id;
      }
    }
    $this->assertSame(['agency_commercial.contact_policy'], $service_ids);
  }

  /**
   * The contact-policy port resolves to the refusing implementation.
   */
  public function testContactPolicyPortRefuses(): void {
    $definition = $this->container->getDefinition('agency_commercial.contact_policy');
    $this->assertSame(
      UnavailableContactPolicyReader::class,
      $definition->getClass(),
    );
    $port = $this->container->get('agency_commercial.contact_policy');
    try {
      $port->mayContact([
        'kind' => 'email',
        'value' => 'contact@example.test',
      ]);
      $this->fail('Contact policy must refuse until a host adapter exists.');
    }
    catch (CommercialRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }
  }

  /**
   * An empty install can be removed.
   */
  public function testEmptyModuleUninstalls(): void {
    $this->assertTrue(
      $this->container->get('module_installer')->uninstall(['agency_commercial']),
    );
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_commercial'),
    );
  }

}
