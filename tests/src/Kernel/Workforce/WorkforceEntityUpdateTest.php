<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Workforce;

use Drupal\KernelTests\KernelTestBase;
use Drupal\agency_workforce\Entity\ProfileEvidence;
use Drupal\agency_workforce\WorkforceTypes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves an enabled shell upgrades to workforce entity storage.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class WorkforceEntityUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'encrypt',
    'audit_chain',
    'agency',
    'agency_workforce',
  ];

  /**
   * An enabled module without entity tables installs them once, then no-ops.
   */
  public function testUpdatePathInstallsTypes(): void {
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('agency_workforce', [
      'agency_workforce_mutex',
      'agency_workforce_receipt',
      'agency_workforce_outbox',
    ]);
    $database = $this->container->get('database');
    $updates = $this->container->get('entity.definition_update_manager');
    $this->assertTrue($this->container->get('module_handler')->moduleExists('agency_workforce'));
    foreach (WorkforceTypes::ALL as $type) {
      $this->assertNull($updates->getEntityType($type));
      $this->assertFalse($database->schema()->tableExists($type));
    }
    $this->container->get('module_handler')->loadInclude('agency_workforce', 'install');
    agency_workforce_update_10001();
    foreach (WorkforceTypes::ALL as $type) {
      $this->assertNotNull($updates->getEntityType($type));
      $this->assertTrue($database->schema()->tableExists($type));
    }
    $this->assertTrue($database->schema()->tableExists('agency_profile_evidence_revision'));
    $this->assertTrue($database->schema()->tableExists('agency_staff_onboard_revision'));
    $this->assertTrue($database->schema()->tableExists('agency_workforce_revision'));
    agency_workforce_update_10001();
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_profile_evidence');
    $record = $storage->create([
      'person_uuid' => '00000000-0000-4000-8000-0000000000aa',
      'classification' => 'employee',
      'status' => 'recorded',
      'display_name' => 'Synthetic Worker',
      'evidence' => '{}',
    ]);
    $storage->save($record);
    agency_workforce_update_10001();
    $storage->resetCache();
    $loaded = $storage->load($record->id());
    $this->assertInstanceOf(ProfileEvidence::class, $loaded);
    $this->assertSame('Synthetic Worker', $loaded->get('display_name')->value);
  }

}
