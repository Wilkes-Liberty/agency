<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use Drupal\agency_recruiting\Entity\Application;
use Drupal\agency_recruiting\Entity\CareerPosting;
use Drupal\agency_recruiting\Entity\RecruitingRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Storage fields and markings match the ontology CareerPosting and Application.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class RecruitingSchemaTest extends RecruitingKernelTestBase {

  /**
   * Ontology JSON properties are stored as long strings, not structured types.
   */
  private const JSON_FIELDS = [
    'eligibility',
    'payload',
    'geo',
  ];

  /**
   * UUID properties that are not the entity UUID key stay plain strings.
   */
  private const UUID_STRING_FIELDS = [
    'posting_uuid',
    'person_uuid',
    'resume_file_uuid',
  ];

  /**
   * Each ontology property exists with the declared marking and storage type.
   */
  public function testSchemaMatchesOntologyPropertiesAndMarkings(): void {
    $objects = $this->ontologyObjects();
    $this->assertSchema($objects['agency_career_posting'], CareerPosting::class);
    $this->assertSchema($objects['agency_application'], Application::class);
    $resume = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_application')['resume_file_uuid'];
    $this->assertSame('string', $resume->getType());
    $this->assertNotSame('file', $resume->getType());
    $this->assertNotSame('image', $resume->getType());
    $this->assertNotSame('entity_reference', $resume->getType());
    $body = $this->container->get('entity_field.manager')
      ->getFieldStorageDefinitions('agency_career_posting')['body'];
    $this->assertSame('string_long', $body->getType());
    $this->assertNotSame('text_long', $body->getType());
    $this->assertNotSame('text_with_summary', $body->getType());
  }

  /**
   * Compares one ontology object to its entity field map.
   */
  private function assertSchema(array $object, string $class): void {
    $this->assertTrue(is_subclass_of($class, RecruitingRecord::class));
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
   * Loads CareerPosting and Application rows from the published contract.
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
    $this->assertArrayHasKey('agency_career_posting', $by_name);
    $this->assertArrayHasKey('agency_application', $by_name);
    return $by_name;
  }

}
