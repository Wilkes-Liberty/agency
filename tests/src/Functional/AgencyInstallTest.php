<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the package installation and required tenant configuration.
 *
 * Failure modes covered first: the modules cannot be discovered or enabled,
 * or a missing/blank agency name is allowed to pass without an error.
 *
 * @group agency
 *
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class AgencyInstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['agency_staff'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The package enables cleanly and reports missing required configuration.
   */
  public function testInstallAndDisplayNameRequirement(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('agency'));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('agency_staff'));

    $this->container->get('module_handler')->loadInclude('agency', 'install');

    $requirements = agency_requirements('runtime');
    $this->assertSame(REQUIREMENT_ERROR, $requirements['agency_display_name']['severity']);
    $this->assertSame('Agency name is not configured', (string) $requirements['agency_display_name']['value']);

    $this->config('agency.settings')->set('display_name', '  ')->save();
    $this->assertArrayHasKey('agency_display_name', agency_requirements('runtime'));

    try {
      agency_require_display_name();
      $this->fail('A blank agency name must refuse the operation.');
    }
    catch (\LogicException $exception) {
      $this->assertSame(
        'Set the agency name before inviting anyone or rendering a participant workspace.',
        $exception->getMessage(),
      );
    }

    $this->config('agency.settings')->set('display_name', 'Example agency')->save();
    $this->assertSame([], agency_requirements('runtime'));
    $this->assertSame('Example agency', agency_require_display_name());
  }

  /**
   * The declared policy boundary validates without package-owned values.
   */
  public function testPolicyConfigurationSchema(): void {
    $settings = [
      'display_name' => 'Example agency',
      'policy' => [
        'invitation_validity' => 'tenant-defined',
        'acknowledgement_period' => 'tenant-defined',
        'follow_up_period' => 'tenant-defined',
        'retention_after_close' => 'tenant-defined',
        'close_reasons' => [
          [
            'id' => 'tenant_reason',
            'label' => 'Tenant reason',
            'message' => 'Tenant-defined participant message.',
          ],
        ],
      ],
    ];

    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $settings)
      ->validate();
    $this->assertCount(0, $violations, (string) $violations);

    $this->config('agency.settings')->setData($settings)->save();
    $this->assertSame('tenant-defined', $this->config('agency.settings')->get('policy.follow_up_period'));
  }

}
