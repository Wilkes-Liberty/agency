<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Entity\Activity;
use Drupal\agency_commercial\Entity\CommercialRecord;
use Drupal\agency_commercial\Entity\Opportunity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Storage fields and markings match the ontology Opportunity and Activity.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialSchemaTest extends CommercialKernelTestBase {

  /**
   * UUID properties that are not the entity UUID key stay plain strings.
   */
  private const UUID_STRING_FIELDS = [
    'organization_uuid',
    'opportunity_uuid',
  ];

  /**
   * Each ontology property exists with the declared marking and storage type.
   *
   * Accepted vault D4 keeps agency_activity revisionable with revision_id
   * even though the ontology draft sets Activity.revisionable false and
   * identifiers.revision_id false. This test pins storage to TRUE and
   * records that conflict instead of failing because the draft says false.
   */
  public function testSchemaMatchesOntologyPropertiesAndMarkings(): void {
    $objects = $this->ontologyObjects();
    $this->assertSchema($objects['agency_opportunity'], Opportunity::class);
    $this->assertSchema($objects['agency_activity'], Activity::class);

    $manager = $this->container->get('entity_type.manager');
    $opportunity = $manager->getDefinition('agency_opportunity');
    $activity = $manager->getDefinition('agency_activity');
    $this->assertTrue($opportunity->isRevisionable());
    $this->assertTrue($objects['agency_opportunity']['revisionable']);
    $this->assertTrue($objects['agency_opportunity']['identifiers']['revision_id']);

    // Ontology conflict (D4): draft Activity is not revisionable.
    $this->assertFalse($objects['agency_activity']['revisionable']);
    $this->assertFalse($objects['agency_activity']['identifiers']['revision_id']);
    $this->assertTrue($activity->isRevisionable());
    $this->assertSame('agency_activity_revision', $activity->getRevisionTable());
    $fields = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_activity');
    $this->assertArrayHasKey('revision_id', $fields);

    $amount = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_opportunity')['amount'];
    $this->assertSame('decimal', $amount->getType());
    $organization = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_opportunity')['organization_uuid'];
    $this->assertSame('string', $organization->getType());
    $this->assertNotSame('entity_reference', $organization->getType());
    $this->assertNotSame('uuid', $organization->getType());
  }

  /**
   * Creating an opportunity without amount leaves the field empty.
   */
  public function testCreateDoesNotInventAmount(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $created = $this->createOpportunity();
    $this->assertTrue($created->get('amount')->isEmpty());
    $created->save();
    $storage->resetCache([$created->id()]);
    $loaded = $storage->load($created->id());
    $this->assertInstanceOf(Opportunity::class, $loaded);
    $this->assertTrue($loaded->get('amount')->isEmpty());
    $value = $loaded->get('amount')->value;
    $this->assertTrue($value === NULL || $value === '');
    $this->assertNotSame(0, $value);
    $this->assertNotSame(0.0, $value);
    $this->assertNotSame('0', (string) ($value ?? ''));

    $with_amount = $this->createOpportunity([
      'amount' => '123.45',
      'uuid' => '00000000-0000-4000-8000-0000000000a2',
    ]);
    $with_amount->save();
    $storage->resetCache([$with_amount->id()]);
    $stored = $storage->load($with_amount->id());
    $this->assertInstanceOf(Opportunity::class, $stored);
    $this->assertFalse($stored->get('amount')->isEmpty());
    $this->assertSame('123.45', $stored->get('amount')->value);
  }

  /**
   * Compares one ontology object to its entity field map.
   */
  private function assertSchema(array $object, string $class): void {
    $this->assertTrue(is_subclass_of($class, CommercialRecord::class));
    $fields = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions($object['machine_name']);
    $markings = $class::MARKINGS;
    foreach ($object['properties'] as $property) {
      $name = $property['name'];
      $this->assertArrayHasKey($name, $fields, $name);
      $this->assertSame($property['marking'], $markings[$name] ?? NULL, $name);
      $this->assertSame(
        $this->expectedStorageType($name, $property['type']),
        $fields[$name]->getType(),
        $name,
      );
    }
    foreach ($class::IDENTITY_FIELDS as $name => $marking) {
      $this->assertArrayHasKey($name, $fields, $name);
      $this->assertSame($marking, $class::IDENTITY_FIELDS[$name]);
    }
  }

  /**
   * Maps an ontology type onto the installed Drupal field type.
   */
  private function expectedStorageType(string $name, string $ontology_type): string {
    if ($name === 'created' || $name === 'changed') {
      return $name;
    }
    if (in_array($name, self::UUID_STRING_FIELDS, TRUE)) {
      return 'string';
    }
    return $ontology_type;
  }

  /**
   * Loads Opportunity and Activity rows from the published contract.
   */
  private function ontologyObjects(): array {
    $path = $this->getModulePath('agency') . '/ontology/ontology.yml';
    $contract = Yaml::parseFile($path);
    $by_name = [];
    foreach ($contract['objects'] as $object) {
      if (isset($object['machine_name'])) {
        $by_name[$object['machine_name']] = $object;
      }
    }
    $this->assertArrayHasKey('agency_opportunity', $by_name);
    $this->assertArrayHasKey('agency_activity', $by_name);
    return $by_name;
  }

}
