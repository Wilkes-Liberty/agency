<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Exception\IntakeRefusal;
use Drupal\agency_intake\UnavailableDispatchKeyStore;
use Drupal\agency_intake\UnavailableIntakeDispatchGateway;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves intake scaffolding installs, refuses and leaves no types.
 *
 * @group agency
 *
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeScaffoldingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'agency',
    'agency_intake',
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
    $this->assertTrue($handler->moduleExists('agency_intake'));
    $this->assertFalse(function_exists('agency_intake_schema'));

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
      'agency_intake_submission',
      'agency_intake_submission_revision',
    ] as $table) {
      $this->assertFalse($schema->tableExists($table), $table);
    }

    $this->container->get('router.builder')->rebuild();
    $route_names = [];
    $routes = $this->container->get('router.route_provider')->getAllRoutes();
    foreach ($routes as $name => $route) {
      if (str_starts_with((string) $name, 'agency_intake.')) {
        $route_names[] = $name;
      }
      $this->assertStringNotContainsString(
        'agency_intake',
        $route->getPath(),
      );
    }
    $this->assertSame([], $route_names);

    $permissions = $this->container->get('user.permissions')->getPermissions();
    foreach (array_keys($permissions) as $id) {
      $this->assertStringNotContainsString('agency_intake', $id);
    }

    $roles = $this->container->get('entity_type.manager')
      ->getStorage('user_role')
      ->loadMultiple();
    foreach ($roles as $role) {
      $this->assertStringNotContainsString('agency_intake', $role->id());
      foreach ($role->getPermissions() as $permission) {
        $this->assertStringNotContainsString('agency_intake', $permission);
      }
    }

    $service_ids = [];
    foreach ($this->container->getDefinitions() as $id => $definition) {
      if (str_starts_with((string) $id, 'agency_intake.')) {
        $service_ids[] = $id;
      }
    }
    sort($service_ids);
    $this->assertSame([
      'agency_intake.dispatch_gateway',
      'agency_intake.dispatch_keys',
    ], $service_ids);
  }

  /**
   * Intake ports resolve to the refusing implementations.
   */
  public function testPortsRefuse(): void {
    $gateway_definition = $this->container->getDefinition('agency_intake.dispatch_gateway');
    $this->assertSame(
      UnavailableIntakeDispatchGateway::class,
      $gateway_definition->getClass(),
    );
    $gateway = $this->container->get('agency_intake.dispatch_gateway');
    try {
      $gateway->dispatch('synthetic.dispatch.1', NULL);
      $this->fail('Dispatch gateway must refuse until a host adapter exists.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }

    $store_definition = $this->container->getDefinition('agency_intake.dispatch_keys');
    $this->assertSame(
      UnavailableDispatchKeyStore::class,
      $store_definition->getClass(),
    );
    $store = $this->container->get('agency_intake.dispatch_keys');
    try {
      $store->reserve('synthetic.dispatch.1');
      $this->fail('Dispatch-key store must refuse until a host adapter exists.');
    }
    catch (IntakeRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }
  }

  /**
   * An empty install can be removed.
   */
  public function testEmptyModuleUninstalls(): void {
    $this->assertTrue(
      $this->container->get('module_installer')->uninstall(['agency_intake']),
    );
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_intake'),
    );
  }

}
