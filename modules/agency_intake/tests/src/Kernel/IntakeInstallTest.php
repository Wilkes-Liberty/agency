<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers the type and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeInstallTest extends IntakeKernelTestBase {

  /**
   * Enabling the module registers the type and creates its tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_intake'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayHasKey('agency_intake_submission', $definitions);
    $this->assertTrue($definitions['agency_intake_submission']->isInternal());
    $this->assertTrue($definitions['agency_intake_submission']->isRevisionable());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_intake_submission'));
    $this->assertTrue($schema->tableExists('agency_intake_submission_revision'));
  }

  /**
   * An empty install can be removed and leaves no intake types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_intake']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_intake'));
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertArrayNotHasKey('agency_intake_submission', $definitions);
  }

}
