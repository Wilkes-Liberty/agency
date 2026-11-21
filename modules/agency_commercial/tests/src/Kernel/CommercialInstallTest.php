<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers types and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialInstallTest extends CommercialKernelTestBase {

  /**
   * Enabling the module registers both types and creates their tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_commercial'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayHasKey('agency_opportunity', $definitions);
    $this->assertArrayHasKey('agency_activity', $definitions);
    $this->assertTrue($definitions['agency_opportunity']->isInternal());
    $this->assertTrue($definitions['agency_activity']->isInternal());
    $this->assertTrue($definitions['agency_opportunity']->isRevisionable());
    $this->assertTrue($definitions['agency_activity']->isRevisionable());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_opportunity'));
    $this->assertTrue($schema->tableExists('agency_opportunity_revision'));
    $this->assertTrue($schema->tableExists('agency_activity'));
    $this->assertTrue($schema->tableExists('agency_activity_revision'));
  }

  /**
   * An empty install can be removed and leaves no commercial types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_commercial']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_commercial'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayNotHasKey('agency_opportunity', $definitions);
    $this->assertArrayNotHasKey('agency_activity', $definitions);
  }

}
