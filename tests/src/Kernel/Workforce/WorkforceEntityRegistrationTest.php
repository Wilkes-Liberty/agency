<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Workforce;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\agency_workforce\Entity\ProfileEvidence;
use Drupal\agency_workforce\Entity\StaffOnboard;
use Drupal\agency_workforce\Entity\WorkforceRecord;
use Drupal\agency_workforce\Entity\WorkforceRelationship;
use Drupal\agency_workforce\Exception\WorkforceRefusal;
use Drupal\agency_workforce\WorkforceTypes;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves workforce entity registration, identity preservation and isolation.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class WorkforceEntityRegistrationTest extends KernelTestBase {

  /**
   * Drupal fields that are not ontology properties.
   */
  private const DRUPAL_METADATA = [
    'langcode',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
  ];

  /**
   * ID-preserving keys that may exist without an ontology row.
   */
  private const IDENTITY_METADATA = [
    'owner_uid',
    'revision_created',
  ];

  /**
   * Stored Relationship properties for the workforce kind.
   */
  private const WORKFORCE_RELATIONSHIP_PROPERTIES = [
    'id',
    'uuid',
    'created',
    'changed',
    'revision_id',
    'revision_created',
    'person_uuid',
    'principal_uuid',
    'owner_uid',
    'state',
    'kind',
    'version',
  ];

  /**
   * Synthetic person UUID used in fixtures.
   */
  private const PERSON = '00000000-0000-4000-8000-0000000000aa';

  /**
   * Synthetic principal UUID used in fixtures.
   */
  private const PRINCIPAL = '00000000-0000-4000-8000-0000000000bb';

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    $this->installSchema('agency_workforce', [
      'agency_workforce_mutex',
      'agency_workforce_receipt',
      'agency_workforce_outbox',
    ]);
    foreach (WorkforceTypes::ALL as $type) {
      $this->installEntitySchema($type);
    }
    foreach (DomainStore::TYPES as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('agency_staff', [
      'agency_staff_mutex',
      'agency_staff_outbox',
      'agency_staff_intent',
    ]);
  }

  /**
   * Fresh enablement registers each workforce type and its tables.
   */
  public function testTypesAreRegisteredOnInstall(): void {
    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    foreach (WorkforceTypes::ALL as $type) {
      $this->assertArrayHasKey($type, $definitions);
      $definition = $definitions[$type];
      $this->assertTrue($definition->isInternal());
      $this->assertSame('Drupal\\agency_workforce\\Access\\DenyAccess', $definition->getHandlerClass('access'));
      $this->assertFalse($definition->hasLinkTemplate('canonical'));
      $this->assertFalse($definition->hasLinkTemplate('edit-form'));
      $this->assertFalse($definition->hasLinkTemplate('delete-form'));
      $this->assertFalse($definition->hasLinkTemplate('collection'));
      $this->assertTrue($this->container->get('database')->schema()->tableExists($type));
    }
    $this->assertArrayNotHasKey('agency_staff_invitation', $definitions);
    $this->assertTrue($this->container->get('database')->schema()->tableExists('agency_profile_evidence_revision'));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('agency_staff_onboard_revision'));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('agency_workforce_revision'));
    $this->assertFalse($this->container->get('database')->schema()->tableExists('agency_workforce_entitlement_revision'));
  }

  /**
   * Empty workforce entity storage can be uninstalled with the module.
   */
  public function testEmptyInstallUninstalls(): void {
    $this->assertSame([], $this->container->get('module_installer')->validateUninstall(['agency_workforce']));
    $this->assertTrue($this->container->get('module_installer')->uninstall(['agency_workforce']));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('agency_workforce'));
    foreach (WorkforceTypes::ALL as $type) {
      $this->assertFalse($this->container->get('database')->schema()->tableExists($type));
    }
  }

  /**
   * Entity fields match ontology properties and markings.
   */
  public function testSchemaMatchesOntologyPropertiesAndMarkings(): void {
    foreach (WorkforceTypes::ALL as $type) {
      $expected = $this->expectedProperties($type);
      $definitions = $this->container->get('entity_field.manager')->getFieldStorageDefinitions($type);
      $actual = [];
      foreach ($definitions as $name => $definition) {
        if (in_array($name, self::DRUPAL_METADATA, TRUE)) {
          continue;
        }
        $actual[] = $name;
        if (in_array($name, self::IDENTITY_METADATA, TRUE) && !isset($expected[$name])) {
          continue;
        }
        $this->assertArrayHasKey(
          $name,
          $expected,
          $type . '.' . $name . ' has no ontology marking',
        );
        $this->assertNotSame('entity_reference', $definition->getType(), $name);
        $this->assertNotSame('file', $definition->getSetting('target_type'), $name);
      }
      foreach ($expected as $name => $property) {
        $this->assertContains($name, $actual, $type . '.' . $name);
        $this->assertArrayHasKey('marking', $property, $type . '.' . $name);
      }
    }
    $file = $this->container->get('entity_field.manager')->getFieldStorageDefinitions('agency_profile_evidence')['file_ref_uuid'];
    $this->assertSame('string', $file->getType());
    $this->assertContains('own', ProfileEvidence::AUDIENCES);
    $this->assertContains('account_summary', ProfileEvidence::AUDIENCES);
  }

  /**
   * Explicit identifiers and timestamps survive create; collisions refuse.
   */
  public function testExplicitIdCreatePreservesIdentityAndRefusesCollision(): void {
    $owner = $this->authenticatedUser([]);
    $created = 1_700_000_000;
    $changed = 1_700_000_100;
    foreach (WorkforceTypes::ALL as $index => $type) {
      $id = 1000 + $index;
      $revision = 5000 + $index;
      $uuid = sprintf('00000000-0000-4000-8000-00000000%04d', $id);
      $values = $this->recordValues($type, [
        'id' => $id,
        'uuid' => $uuid,
        'created' => $created,
        'changed' => $changed,
        'owner_uid' => (int) $owner->id(),
      ]);
      $storage = $this->container->get('entity_type.manager')->getStorage($type);
      if ($storage->getEntityType()->isRevisionable()) {
        $values['revision_id'] = $revision;
        $values['revision_created'] = $created;
      }
      $entity = $storage->create($values);
      $storage->save($entity);
      $storage->resetCache([$id]);
      $reloaded = $storage->load($id);
      $this->assertInstanceOf(WorkforceRecord::class, $reloaded);
      $this->assertSame($id, (int) $reloaded->id());
      $this->assertSame($uuid, $reloaded->uuid());
      $this->assertSame($created, (int) $reloaded->get('created')->value);
      $this->assertSame($changed, (int) $reloaded->get('changed')->value);
      $this->assertSame((int) $owner->id(), (int) $reloaded->get('owner_uid')->value);
      if ($storage->getEntityType()->isRevisionable()) {
        $this->assertSame($revision, (int) $reloaded->getRevisionId());
      }
      try {
        $duplicate = $storage->create($this->recordValues($type, [
          'id' => $id,
          'uuid' => sprintf('00000000-0000-4000-8000-00000000%04d', $id + 50),
          'owner_uid' => (int) $owner->id(),
        ]));
        $storage->save($duplicate);
        $this->fail($type . ' must refuse a colliding id.');
      }
      catch (WorkforceRefusal $exception) {
        $this->assertSame('collision', $exception->reason);
      }
    }
  }

  /**
   * Every agency_workforce permission still denies entity access.
   */
  public function testDefaultAccessDeniesWorkforcePermissions(): void {
    $permissions = array_keys($this->container->get('user.permissions')->getPermissions());
    $workforce = array_values(array_filter(
      $permissions,
      static fn(string $name): bool => str_contains($name, 'agency workforce'),
    ));
    $this->assertNotSame([], $workforce);
    $account = $this->authenticatedUser($workforce);
    $this->assertTrue($account->hasPermission('issue agency workforce invitations'));
    $this->assertTrue($account->hasPermission('reconcile agency workforce invitations'));
    foreach (WorkforceTypes::ALL as $type) {
      $storage = $this->container->get('entity_type.manager')->getStorage($type);
      $entity = $storage->create($this->recordValues($type, [
        'owner_uid' => (int) $account->id(),
      ]));
      $storage->save($entity);
      $this->assertFalse($entity->access('view', $account));
      $this->assertFalse($entity->access('update', $account));
      $this->assertFalse($entity->access('delete', $account));
      $this->assertFalse($this->container->get('entity_type.manager')->getAccessControlHandler($type)->createAccess(NULL, $account));
    }
  }

  /**
   * Workforce writes do not touch Discovery tables.
   */
  public function testWorkforceWritesDoNotTouchDiscoveryTables(): void {
    $counts = [];
    foreach (DomainStore::TYPES as $type) {
      $counts[$type] = $this->countRows($type);
    }
    foreach (WorkforceTypes::ALL as $type) {
      $this->container->get('entity_type.manager')->getStorage($type)->save(
        $this->container->get('entity_type.manager')->getStorage($type)->create(
          $this->recordValues($type, []),
        ),
      );
    }
    foreach (DomainStore::TYPES as $type) {
      $this->assertSame($counts[$type], $this->countRows($type), $type);
    }
  }

  /**
   * Discovery storage cannot load or treat workforce rows as Discovery records.
   */
  public function testDiscoveryStoreCannotLoadWorkforceTypes(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_workforce');
    $record = $storage->create($this->recordValues('agency_workforce', [
      'id' => 42,
      'uuid' => '00000000-0000-4000-8000-000000000042',
    ]));
    $storage->save($record);
    $storage->resetCache([42]);
    $reloaded = $storage->load(42);
    $this->assertInstanceOf(WorkforceRelationship::class, $reloaded);
    $this->assertSame(WorkforceRelationship::class, get_class($reloaded));
    $this->assertSame(1, $this->countRows('agency_workforce'));
    $this->assertSame(0, $this->countRows('agency_discovery'));
    $this->assertSame(0, $this->countRows('agency_invitation'));
    $this->assertSame(0, $this->countRows('agency_entitlement'));
    $store = $this->container->get('agency_staff.store');
    $this->assertNull($store->find('agency_discovery', ['uuid' => $record->uuid()]));
    $this->assertNull($store->find('agency_invitation', ['uuid' => $record->uuid()]));
    $this->assertNull($store->find('agency_entitlement', ['uuid' => $record->uuid()]));
    $this->assertNull(
      $this->container->get('entity_type.manager')->getStorage('agency_discovery')->load(42),
    );
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Discovery');
    $store->all('agency_workforce');
  }

  /**
   * Staff onboard refuses Discovery, client, partner and supplier kinds.
   */
  public function testStaffOnboardClassificationAllowlist(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_staff_onboard');
    foreach (StaffOnboard::ALLOWED as $allowed) {
      $entity = $storage->create($this->recordValues('agency_staff_onboard', [
        'classification' => $allowed,
      ]));
      $storage->save($entity);
      $this->assertInstanceOf(StaffOnboard::class, $entity);
      $this->assertSame($allowed, $entity->scalar('classification'));
    }
    foreach (array_merge(StaffOnboard::REFUSED, ['unknown']) as $refused) {
      try {
        $storage->save($storage->create($this->recordValues('agency_staff_onboard', [
          'classification' => $refused,
        ])));
        $this->fail($refused . ' must be refused.');
      }
      catch (WorkforceRefusal $exception) {
        $this->assertSame('invalid', $exception->reason);
      }
    }
  }

  /**
   * Profile evidence refuses File Gate impersonation via file bytes.
   */
  public function testProfileEvidenceRefusesFileBytes(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_profile_evidence');
    $storage->save($storage->create($this->recordValues('agency_profile_evidence', [
      'file_ref_uuid' => '00000000-0000-4000-8000-0000000000cc',
    ])));
    try {
      $storage->save($storage->create($this->recordValues('agency_profile_evidence', [
        'file_ref_uuid' => 'data:application/pdf;base64,AAAA',
      ])));
      $this->fail('Inline file bytes must be refused.');
    }
    catch (WorkforceRefusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
  }

  /**
   * Returns ontology properties expected on one registered type.
   *
   * @return array<string, array>
   *   Property rows keyed by name.
   */
  private function expectedProperties(string $type): array {
    $objects = $this->ontologyObjects();
    $source = match ($type) {
      WorkforceTypes::PROFILE_EVIDENCE => $objects['agency_profile_evidence'],
      WorkforceTypes::STAFF_ONBOARD => $objects['agency_staff_onboard'],
      WorkforceTypes::ENTITLEMENT => $objects['agency_entitlement'],
      WorkforceTypes::RELATIONSHIP => $objects['agency_discovery'],
      default => throw new \InvalidArgumentException($type),
    };
    $expected = [];
    foreach ($source['properties'] as $property) {
      $name = $property['name'];
      if ($type === WorkforceTypes::RELATIONSHIP && !in_array($name, self::WORKFORCE_RELATIONSHIP_PROPERTIES, TRUE)) {
        continue;
      }
      $expected[$name] = $property;
    }
    if ($type === WorkforceTypes::RELATIONSHIP) {
      foreach (self::WORKFORCE_RELATIONSHIP_PROPERTIES as $name) {
        $this->assertArrayHasKey($name, $expected);
      }
    }
    return $expected;
  }

  /**
   * Indexes ontology objects by machine name.
   *
   * @return array<string, array>
   *   Object rows.
   */
  private function ontologyObjects(): array {
    $path = dirname(__DIR__, 4) . '/ontology/ontology.yml';
    $document = Yaml::parseFile($path);
    $by_name = [];
    foreach ($document['objects'] as $object) {
      $by_name[$object['machine_name']] = $object;
    }
    return $by_name;
  }

  /**
   * Builds a valid synthetic record for one type.
   *
   * @param string $type
   *   Entity type id.
   * @param array<string, mixed> $overrides
   *   Identity or classification overrides.
   *
   * @return array<string, mixed>
   *   Create values.
   */
  private function recordValues(string $type, array $overrides): array {
    $base = match ($type) {
      WorkforceTypes::PROFILE_EVIDENCE => [
        'person_uuid' => self::PERSON,
        'classification' => 'employee',
        'status' => 'recorded',
        'display_name' => 'Synthetic Worker',
        'evidence' => '{}',
      ],
      WorkforceTypes::STAFF_ONBOARD => [
        'invitation_uuid' => '00000000-0000-4000-8000-0000000000dd',
        'person_uuid' => self::PERSON,
        'classification' => 'employee',
        'status' => 'received',
        'payload' => '{}',
      ],
      WorkforceTypes::RELATIONSHIP => [
        'person_uuid' => self::PERSON,
        'principal_uuid' => self::PRINCIPAL,
        'state' => 'invited',
        'kind' => 'workforce',
        'version' => 1,
      ],
      WorkforceTypes::ENTITLEMENT => [
        'relationship_uuid' => '00000000-0000-4000-8000-0000000000ee',
        'principal_uuid' => self::PRINCIPAL,
        'status' => 'pending',
        'version' => 1,
        'details' => '{}',
      ],
      default => [],
    };
    return $overrides + $base;
  }

  /**
   * Creates an authenticated user with the supplied permissions.
   *
   * @param string[] $permissions
   *   Permission names.
   */
  private function authenticatedUser(array $permissions): AccountInterface {
    $rid = 'wf_' . substr(hash('sha256', implode(',', $permissions) . uniqid('', TRUE)), 0, 8);
    Role::create([
      'id' => $rid,
      'label' => 'Workforce fixture',
      'permissions' => $permissions,
    ])->save();
    User::create(['name' => 'reserved-root', 'status' => 1])->save();
    $user = User::create([
      'name' => 'synthetic-staff-' . $rid,
      'status' => 1,
      'roles' => [$rid],
    ]);
    $user->save();
    return $user;
  }

  /**
   * Counts persisted rows for one entity type table.
   */
  private function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table, 't')->countQuery()->execute()->fetchField();
  }

}
