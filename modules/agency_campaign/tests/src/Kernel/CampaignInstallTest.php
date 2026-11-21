<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_campaign\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the submodule installs, registers types and uninstalls empty.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CampaignInstallTest extends CampaignKernelTestBase {

  /**
   * Enabling the module registers the type and creates its tables.
   */
  public function testInstallRegistersEntityTypes(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_campaign'));
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayHasKey('agency_campaign', $definitions);
    $this->assertTrue($definitions['agency_campaign']->isInternal());
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('agency_campaign'));
    $this->assertTrue($schema->tableExists('agency_campaign_revision'));
    $this->assertTrue($schema->tableExists('agency_campaign_outbox'));
    $this->assertTrue($schema->tableExists('agency_campaign_intent'));
  }

  /**
   * Enablement creates no campaign rows, routes or Discovery records.
   */
  public function testEnablementCreatesNoBusinessRecords(): void {
    $count = (int) $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(0, $count);
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider')->getAllRoutes();
    foreach ($routes as $name => $route) {
      $this->assertStringStartsNotWith('agency_campaign.', (string) $name);
      $this->assertStringNotContainsString(
        'agency_campaign',
        $route->getPath(),
      );
    }
    $this->assertFileDoesNotExist(
      $this->getModulePath('agency_campaign') . '/agency_campaign.routing.yml',
    );
  }

  /**
   * An empty install can be removed and leaves no campaign types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_campaign']);
    $this->assertFalse(
      $this->container->get('module_handler')->moduleExists('agency_campaign'),
    );
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayNotHasKey('agency_campaign', $definitions);
  }

}
