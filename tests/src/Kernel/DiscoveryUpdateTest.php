<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\agency_staff\Storage\DomainStore;
use Drupal\agency_staff\Entity\PersonResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves a foundation upgrade installs missing storage without losing data.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class DiscoveryUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'key', 'encrypt',
    'audit_chain', 'field_guard', 'agency', 'agency_staff',
  ];

  /**
   * An enabled shell upgrades, and a repeated update preserves domain records.
   */
  public function testFoundationUpgrade(): void {
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'agency']);
    $this->config('agency.settings')->set('display_name', 'Existing example agency')->save();
    $before = $this->config('agency.settings')->getRawData();
    $user = User::create(['name' => 'existing-example-user', 'status' => 1]);
    $user->save();
    $database = $this->container->get('database');
    $updates = $this->container->get('entity.definition_update_manager');
    // Kernel module enablement gives the prior shell's enabled-module state
    // without running new install hooks or installing Discovery entity storage.
    $this->assertTrue($this->container->get('module_handler')->moduleExists('agency_staff'));
    foreach (DomainStore::TYPES as $type) {
      $this->assertNull($updates->getEntityType($type));
      $this->assertFalse($database->schema()->tableExists($type));
    }
    $this->container->get('module_handler')->loadInclude('agency_staff', 'install');
    foreach (array_keys(agency_staff_schema()) as $table) {
      $this->assertFalse($database->schema()->tableExists($table));
    }
    agency_staff_update_10001();
    foreach (DomainStore::TYPES as $type) {
      $this->assertNotNull($updates->getEntityType($type));
      $this->assertTrue($database->schema()->tableExists($type));
    }
    $this->assertTrue($database->schema()->tableExists('agency_discovery_revision'));
    foreach (array_keys(agency_staff_schema()) as $table) {
      $this->assertTrue($database->schema()->tableExists($table));
    }
    $this->assertSame('0', (string) $database->select('agency_staff_mutex', 'm')->fields('m', ['value'])->condition('id', 1)->execute()->fetchField());
    $this->assertTrue($database->schema()->indexExists('agency_discovery', 'principal_lookup'));
    $this->assertTrue($database->schema()->indexExists('agency_discovery', 'owner_lookup'));
    $this->assertTrue($database->schema()->indexExists('agency_invitation', 'relationship_lookup'));
    $person = $this->container->get('entity_type.manager')->getStorage('agency_person')->create(['display_name' => 'Existing person']);
    $person->save();
    agency_staff_update_10001();
    $this->assertSame('1', (string) $database->select('agency_staff_mutex', 'm')->countQuery()->execute()->fetchField());
    $this->assertSame($before, $this->config('agency.settings')->getRawData());
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
    $this->assertSame('existing-example-user', User::load($user->id())?->getAccountName());
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_person');
    $storage->resetCache();
    $this->assertSame('Existing person', PersonResolver::load($person->id())?->scalar('display_name'));
  }

}
