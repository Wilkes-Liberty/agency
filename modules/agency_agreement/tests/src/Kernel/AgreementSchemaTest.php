<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_agreement\Kernel;

use Drupal\agency_agreement\Entity\Agreement;
use Drupal\agency_agreement\Entity\AgreementRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Storage fields and markings match the ontology Agreement object.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class AgreementSchemaTest extends AgreementKernelTestBase {

  /**
   * Each ontology property exists with the declared marking and storage type.
   */
  public function testSchemaMatchesOntologyPropertiesAndMarkings(): void {
    $objects = $this->ontologyObjects();
    $this->assertSchema($objects['agency_agreement'], Agreement::class);
  }

  /**
   * Compares one ontology object to its entity field map.
   *
   * @param array<string, mixed> $object
   *   Ontology object row.
   * @param class-string $class
   *   Entity class.
   */
  private function assertSchema(array $object, string $class): void {
    $this->assertTrue(is_subclass_of($class, AgreementRecord::class));
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
  private function expectedStorageType(
    string $name,
    string $ontology_type,
  ): string {
    if ($name === 'created' || $name === 'changed') {
      return $name;
    }
    if ($name !== 'uuid' && $ontology_type === 'uuid') {
      return 'string';
    }
    return $ontology_type;
  }

  /**
   * Loads the Agreement row from the published contract.
   *
   * @return array<string, array<string, mixed>>
   *   Objects keyed by machine name.
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
    $this->assertArrayHasKey('agency_agreement', $by_name);
    $this->assertSame('proposed', $by_name['agency_agreement']['status']);
    $this->assertSame(
      [],
      $by_name['agency_agreement']['lifecycle']['transitions'],
    );
    return $by_name;
  }

}
