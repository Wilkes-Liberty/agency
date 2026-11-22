<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_expense\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers types and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class ExpenseInstallTest extends ExpenseKernelTestBase {

  /**
   * Enabling the module registers the type and creates its tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_expense'));
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayHasKey('agency_expense', $definitions);
    $this->assertTrue($definitions['agency_expense']->isInternal());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_expense'));
    $this->assertTrue($schema->tableExists('agency_expense_revision'));
    $this->assertTrue($schema->tableExists('agency_expense_outbox'));
    $this->assertTrue($schema->tableExists('agency_expense_intent'));
  }

  /**
   * Enablement creates no expense rows, routes or Discovery records.
   */
  public function testEnablementCreatesNoBusinessRecords(): void {
    $count = (int) $this->container->get('entity_type.manager')
      ->getStorage('agency_expense')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, $count);
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider')->getAllRoutes();
    foreach ($routes as $name => $route) {
      $this->assertStringStartsNotWith('agency_expense.', (string) $name);
      $this->assertStringNotContainsString(
        'agency_expense',
        $route->getPath(),
      );
    }
    $this->assertFileDoesNotExist(
      $this->getModulePath('agency_expense') . '/agency_expense.routing.yml',
    );
  }

  /**
   * An empty install can be removed and leaves no expense types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_expense']);
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_expense'),
    );
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayNotHasKey('agency_expense', $definitions);
  }

}
