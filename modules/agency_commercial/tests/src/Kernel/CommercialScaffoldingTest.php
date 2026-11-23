<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\agency_commercial\UnavailableContactPolicyReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves commercial types install internally without a granting surface.
 *
 * @group agency
 *
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialScaffoldingTest extends CommercialKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user']);
  }

  /**
   * Enabling the module registers internal types without granting access.
   */
  public function testEnablementRegistersInternalTypesWithoutRoutes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_commercial'));
    $this->assertFalse(function_exists('agency_commercial_schema'));

    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayHasKey('agency_opportunity', $definitions);
    $this->assertArrayHasKey('agency_activity', $definitions);
    $this->assertTrue($definitions['agency_opportunity']->isInternal());
    $this->assertTrue($definitions['agency_activity']->isInternal());
    $this->assertArrayNotHasKey('agency_intake_submission', $definitions);

    $schema = $this->container->get('database')->schema();
    foreach ([
      'agency_opportunity',
      'agency_opportunity_revision',
      'agency_activity',
      'agency_activity_revision',
    ] as $table) {
      $this->assertTrue($schema->tableExists($table), $table);
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
    sort($service_ids);
    $this->assertSame([
      'agency_commercial.contact_match',
      'agency_commercial.contact_policy',
      'agency_commercial.operating_runs',
      'agency_commercial.records',
    ], $service_ids);
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
   * An empty install can be removed and drops the registered types.
   */
  public function testEmptyModuleUninstalls(): void {
    $this->assertTrue(
      $this->container->get('module_installer')->uninstall(['agency_commercial']),
    );
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_commercial'),
    );
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayNotHasKey('agency_opportunity', $definitions);
    $this->assertArrayNotHasKey('agency_activity', $definitions);
  }

}
