<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_agreement\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers types and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class AgreementInstallTest extends AgreementKernelTestBase {

  /**
   * Enabling the module registers the type and creates its tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_agreement'));
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayHasKey('agency_agreement', $definitions);
    $this->assertTrue($definitions['agency_agreement']->isInternal());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_agreement'));
    $this->assertTrue($schema->tableExists('agency_agreement_revision'));
  }

  /**
   * Enablement creates no agreement rows or routes.
   */
  public function testEnablementCreatesNoBusinessRecords(): void {
    $count = (int) $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, $count);
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider')->getAllRoutes();
    foreach ($routes as $name => $route) {
      $this->assertStringStartsNotWith('agency_agreement.', (string) $name);
      $this->assertStringNotContainsString(
        'agency_agreement',
        $route->getPath(),
      );
    }
    $this->assertFileDoesNotExist(
      $this->getModulePath('agency_agreement') . '/agency_agreement.routing.yml',
    );
  }

  /**
   * An empty install can be removed and leaves no agreement types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_agreement']);
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_agreement'),
    );
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayNotHasKey('agency_agreement', $definitions);
  }

}
