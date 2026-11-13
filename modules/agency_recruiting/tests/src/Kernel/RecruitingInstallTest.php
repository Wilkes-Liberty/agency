<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers types and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class RecruitingInstallTest extends RecruitingKernelTestBase {

  /**
   * Enabling the module registers both types and creates their tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_recruiting'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayHasKey('agency_career_posting', $definitions);
    $this->assertArrayHasKey('agency_application', $definitions);
    $this->assertTrue($definitions['agency_career_posting']->isInternal());
    $this->assertTrue($definitions['agency_application']->isInternal());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_career_posting'));
    $this->assertTrue($schema->tableExists('agency_career_posting_revision'));
    $this->assertTrue($schema->tableExists('agency_application'));
    $this->assertTrue($schema->tableExists('agency_application_revision'));
  }

  /**
   * An empty install can be removed and leaves no recruiting types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_recruiting']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_recruiting'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayNotHasKey('agency_career_posting', $definitions);
    $this->assertArrayNotHasKey('agency_application', $definitions);
  }

}
