<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Entity\IntakeRecord;
use Drupal\agency_intake\Entity\IntakeSubmission;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Storage fields and markings match the ontology IntakeSubmission.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeSchemaTest extends IntakeKernelTestBase {

  /**
   * Ontology JSON properties are stored as long strings, not structured types.
   */
  private const JSON_FIELDS = [
    'payload',
  ];

  /**
   * UUID properties that are not the entity UUID key stay plain strings.
   */
  private const UUID_STRING_FIELDS = [
    'organization_uuid',
  ];

  /**
   * Each ontology property exists with the declared marking and storage type.
   */
  public function testSchemaMatchesOntologyPropertiesAndMarkings(): void {
    $objects = $this->ontologyObjects();
    $this->assertSchema($objects['agency_intake_submission'], IntakeSubmission::class);

    $definition = $this->container->get('entity_type.manager')
      ->getDefinition('agency_intake_submission');
    $this->assertTrue($definition->isRevisionable());
    $this->assertTrue($objects['agency_intake_submission']['revisionable']);
    $this->assertSame(
      'agency_intake_submission_revision',
      $definition->getRevisionTable(),
    );

    $payload = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_intake_submission')['payload'];
    $this->assertSame('string_long', $payload->getType());
    $this->assertNotSame('map', $payload->getType());
    $this->assertNotSame('json', $payload->getType());
    $organization = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_intake_submission')['organization_uuid'];
    $this->assertSame('string', $organization->getType());
    $this->assertNotSame('entity_reference', $organization->getType());
    $this->assertNotSame('uuid', $organization->getType());
  }

  /**
   * Compares one ontology object to its entity field map.
   */
  private function assertSchema(array $object, string $class): void {
    $this->assertTrue(is_subclass_of($class, IntakeRecord::class));
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
    if (in_array($name, self::JSON_FIELDS, TRUE)) {
      return 'string_long';
    }
    if (in_array($name, self::UUID_STRING_FIELDS, TRUE)) {
      return 'string';
    }
    return $ontology_type;
  }

  /**
   * Loads IntakeSubmission from the published contract.
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
    $this->assertArrayHasKey('agency_intake_submission', $by_name);
    return $by_name;
  }

}
