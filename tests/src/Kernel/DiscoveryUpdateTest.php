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

  /**
   * Existing intent and delivery history survive a repeatable health upgrade.
   */
  public function testEvidenceHealthUpgrade(): void {
    $this->container->get('module_handler')->loadInclude('agency_staff', 'install');
    $database = $this->container->get('database');
    $definition = agency_staff_schema()['agency_staff_outbox'];
    foreach (['attempts', 'last_attempt_at', 'last_error_at'] as $field) {
      unset($definition['fields'][$field]);
    }
    $database->schema()->createTable('agency_staff_outbox', $definition);
    $row = [
      'id' => '11111111-1111-4111-8111-111111111111',
      'relationship_uuid' => '22222222-2222-4222-8222-222222222222',
      'operation' => 'respond',
      'metadata' => '{}',
      'denial' => 0,
      'recorded' => 100,
      'delivered' => NULL,
    ];
    $delivered = array_replace($row, [
      'id' => '33333333-3333-4333-8333-333333333333',
      'delivered' => 200,
    ]);
    foreach ([$row, $delivered] as $existing) {
      $database->insert('agency_staff_outbox')->fields($existing)->execute();
    }
    agency_staff_update_10002();
    agency_staff_update_10002();
    $this->assertSame(2, (int) $database->select('agency_staff_outbox', 'o')->countQuery()->execute()->fetchField());
    foreach ([$row, $delivered] as $existing) {
      $result = $database->select('agency_staff_outbox', 'o')->fields('o')->condition('id', $existing['id'])->execute()->fetchAssoc();
      foreach ($existing as $field => $value) {
        if ($value === NULL) {
          $this->assertNull($result[$field]);
        }
        else {
          $this->assertSame((string) $value, (string) $result[$field]);
        }
      }
      $this->assertSame(0, (int) $result['attempts']);
      $this->assertNull($result['last_attempt_at']);
      $this->assertNull($result['last_error_at']);
    }
  }

}
