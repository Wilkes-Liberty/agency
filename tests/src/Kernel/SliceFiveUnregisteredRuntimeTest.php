<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\agency_workforce\WorkforceTypes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runtime proof that credential, availability and roster stay unregistered.
 *
 * YAML still names Credential and Availability on agency_workforce. Enabling
 * that module with registered Slice 5 storage must not park those types.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class SliceFiveUnregisteredRuntimeTest extends KernelTestBase {

  /**
   * Module ids that Slice 5 must not register until the operator picks a home.
   */
  private const UNREGISTERED_MODULES = [
    'agency_credential',
    'agency_availability',
    'agency_roster',
  ];

  /**
   * Entity type ids that must stay absent.
   */
  private const UNREGISTERED_TYPES = [
    'agency_credential',
    'agency_availability',
    'agency_roster',
  ];

  /**
   * Service-id fragments that must not appear on an enabled container.
   */
  private const UNREGISTERED_SERVICE_FRAGMENTS = [
    'agency_credential',
    'agency_availability',
    'agency_roster',
    'verifyCredential',
    'offerAvailability',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'key',
    'encrypt',
    'audit_chain',
    'field_guard',
    'agency',
    'agency_staff',
    'agency_workforce',
    'agency_campaign',
    'agency_expense',
    'agency_agreement',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_campaign');
    $this->installSchema('agency_campaign', [
      'agency_campaign_outbox',
      'agency_campaign_intent',
    ]);
    $this->installEntitySchema('agency_expense');
    $this->installSchema('agency_expense', [
      'agency_expense_outbox',
      'agency_expense_intent',
    ]);
    $this->installEntitySchema('agency_agreement');
    foreach (WorkforceTypes::ALL as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('agency_workforce', [
      'agency_workforce_mutex',
      'agency_workforce_receipt',
      'agency_workforce_outbox',
    ]);
    $this->installConfig(['system', 'user', 'audit_chain']);
  }

  /**
   * Campaign, expense, agreement and workforce stay enabled together.
   */
  public function testRegisteredSliceFiveAndWorkforceStayEnabled(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency_campaign'));
    $this->assertTrue($handler->moduleExists('agency_expense'));
    $this->assertTrue($handler->moduleExists('agency_agreement'));
    $this->assertTrue($handler->moduleExists('agency_workforce'));
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    $this->assertArrayHasKey('agency_campaign', $definitions);
    $this->assertArrayHasKey('agency_expense', $definitions);
    $this->assertArrayHasKey('agency_agreement', $definitions);
    foreach (WorkforceTypes::ALL as $type) {
      $this->assertArrayHasKey($type, $definitions);
    }
    $ids = $this->container->getServiceIds();
    $this->assertContains('agency_campaign.launch', $ids);
    $this->assertContains('agency_expense.record', $ids);
    $this->assertContains('agency_workforce.invitations', $ids);
  }

  /**
   * Credential, availability and roster stay off the enabled container.
   */
  public function testCredentialAvailabilityRosterStayUnregisteredAtRuntime(): void {
    $handler = $this->container->get('module_handler');
    foreach (self::UNREGISTERED_MODULES as $module) {
      $this->assertFalse($handler->moduleExists($module), $module);
    }
    $definitions = $this->container->get('entity_type.manager')
      ->getDefinitions();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertArrayNotHasKey($type, $definitions, $type);
    }
    $schema = $this->container->get('database')->schema();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertFalse($schema->tableExists($type), $type);
      $this->assertFalse($schema->tableExists($type . '_revision'), $type);
    }
    foreach ($this->container->getServiceIds() as $id) {
      foreach (self::UNREGISTERED_SERVICE_FRAGMENTS as $needle) {
        $this->assertStringNotContainsString($needle, $id, $id);
      }
    }
  }

}
