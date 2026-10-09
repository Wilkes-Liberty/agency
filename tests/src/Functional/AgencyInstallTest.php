<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Functional;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
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
    $expected_severity = class_exists(RequirementSeverity::class)
      ? RequirementSeverity::Error
      : REQUIREMENT_ERROR;
    $this->assertSame($expected_severity, $requirements['agency_display_name']['severity']);
    $this->assertSame('Agency name is not configured', (string) $requirements['agency_display_name']['value']);

    $admin = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Agency name is not configured');

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
   * The declared policy boundary rejects malformed or unknown values.
   */
  public function testPolicyConfigurationSchema(): void {
    $settings = [
      'langcode' => 'en',
      'display_name' => 'Example agency',
      'policy' => [
        'invitation_validity' => 'P7D',
        'acknowledgement_period' => 'P2D',
        'follow_up_period' => 'P5D',
        'retention_after_close' => 'P1Y',
        'retained_information_statement' => 'Contact details and conversation history.',
        'fallback_contact_route' => 'Use the agency contact page.',
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

    $invalid_duration = $settings;
    $invalid_duration['policy']['invitation_validity'] = 'seven days';
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $invalid_duration)
      ->validate();
    $this->assertStringContainsString(
      'invitation validity period must be an iso 8601 duration',
      strtolower((string) $violations),
    );

    $mixed_week_duration = $settings;
    $mixed_week_duration['policy']['invitation_validity'] = 'P1W1D';
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $mixed_week_duration)
      ->validate();
    $this->assertStringContainsString(
      'invitation validity period must be an iso 8601 duration',
      strtolower((string) $violations),
    );

    $bad_identifier = $settings;
    $bad_identifier['policy']['close_reasons'][0]['id'] = 'Not a machine name';
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $bad_identifier)
      ->validate();
    $this->assertNotCount(0, $violations);

    $missing_identifier = $settings;
    unset($missing_identifier['policy']['close_reasons'][0]['id']);
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $missing_identifier)
      ->validate();
    $this->assertStringContainsString("'id' is a required key", (string) $violations);

    $unknown_key = $settings;
    $unknown_key['policy']['unknown_policy'] = 'P1D';
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('agency.settings', $unknown_key)
      ->validate();
    $this->assertStringContainsString("'unknown_policy' is not a supported key", (string) $violations);
  }

}
