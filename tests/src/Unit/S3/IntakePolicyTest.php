<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_intake\IntakePolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests fail-closed intake policy catalogs and explicit declarations.
 *
 * @group agency
 */
#[Group('agency')]
final class IntakePolicyTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_intake\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_intake/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Injected catalogs keep vendor fields empty and unknown ids fail closed.
   */
  public function testCatalogDefaultsOmitVendorFields(): void {
    $catalog = $this->catalog();
    $form_a = IntakePolicy::resolve('form_a', NULL, $catalog);
    $this->assertSame('contact_activity_opportunity', $form_a['crm_mode']);
    $this->assertSame('', $form_a['jira_request_type']);
    $this->assertSame('', $form_a['notify_route']);
    $form_b = IntakePolicy::resolve('form_b', NULL, $catalog);
    $this->assertSame('contact_activity', $form_b['crm_mode']);
    $this->assertSame('', $form_b['jira_request_type']);
    $this->assertSame('', $form_b['notify_route']);
    $form_none = IntakePolicy::resolve('form_none', NULL, $catalog);
    $this->assertSame('none', $form_none['crm_mode']);
    $this->assertSame('', $form_none['jira_request_type']);
    $this->assertSame('', $form_none['notify_route']);
    $unknown = IntakePolicy::resolve('form_z', NULL, $catalog);
    $this->assertSame(IntakePolicy::DEFAULT, $unknown);
    $this->assertSame('', IntakePolicy::DEFAULT['jira_request_type']);
    $this->assertSame('', IntakePolicy::DEFAULT['notify_route']);
  }

  /**
   * Explicit declarations fail closed and never fall back to the catalog.
   */
  public function testExplicitPolicyAndDenials(): void {
    $catalog = $this->catalog();
    $policy = IntakePolicy::resolve('form_a', NULL, $catalog);
    $this->assertSame(
      $policy,
      IntakePolicy::resolve('form_z', $policy, $catalog),
    );
    $this->assertSame(
      $policy,
      IntakePolicy::resolve('form_a', NULL, $catalog),
    );
    $this->assertTrue(IntakePolicy::allows('form_a', 'jira', NULL, $catalog));
    $this->assertFalse(IntakePolicy::allows('form_z', 'jira', NULL, $catalog));
    foreach ([
      [],
      ['crm_mode' => 'contact_activity_opportunity'],
      $policy + ['typo' => TRUE],
    ] as $invalid) {
      $resolved = IntakePolicy::resolve('form_z', $invalid, $catalog);
      $this->assertSame(['store', 'notify'], $resolved['actions']);
      $this->assertSame('none', $resolved['crm_mode']);
      $this->assertFalse(
        IntakePolicy::allows('form_z', 'jira', $invalid, $catalog),
      );
    }
    // An explicit invalid declaration must not fall back to a
    // permissive catalog entry.
    $this->assertFalse(IntakePolicy::allows('form_a', 'jira', [], $catalog));
    $policy['crm_mode'] = 'unexpected';
    $this->assertSame(
      'none',
      IntakePolicy::resolve('form_a', $policy, $catalog)['crm_mode'],
    );
    $this->assertFalse(
      IntakePolicy::allows('form_a', 'jira', $policy, $catalog),
    );
  }

  /**
   * Generic catalog used only by these proofs. Overlay catalogs stay host.
   *
   * @return array<string, array<string, mixed>>
   *   Valid policies keyed by synthetic form ids.
   */
  private function catalog(): array {
    return [
      'form_a' => $this->policy([
        'purpose' => 'commercial',
        'actions' => [
          'store',
          'notify',
          'acknowledge',
          'jira',
          'crm',
          'audit',
        ],
        'crm_mode' => 'contact_activity_opportunity',
        'analytics_mode' => 'provenance',
        'quarantine_eligible' => TRUE,
      ]),
      'form_b' => $this->policy([
        'purpose' => 'commercial',
        'actions' => ['store', 'notify', 'acknowledge', 'crm', 'audit'],
        'crm_mode' => 'contact_activity',
        'analytics_mode' => 'provenance',
        'quarantine_eligible' => TRUE,
      ]),
      'form_none' => $this->policy([
        'purpose' => 'recruiting',
        'actions' => ['store', 'notify', 'audit'],
      ]),
    ];
  }

  /**
   * Completes a valid policy from DEFAULT with caller overrides.
   *
   * @param array<string, mixed> $overrides
   *   Fields that differ from DEFAULT.
   *
   * @return array<string, mixed>
   *   A complete policy declaration.
   */
  private function policy(array $overrides): array {
    return $overrides + IntakePolicy::DEFAULT;
  }

}
