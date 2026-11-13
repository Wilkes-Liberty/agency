<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate_test\Access\AuthorityGatedReader;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Slice 2 migration-window proving tests against synthetic entities.
 *
 * Failure cases: unmapped markings writing targets, a legacy-authority
 * reader returning Agency rows, and identity or File Gate UUID drift.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateS2WindowTest extends MigrateKernelTestBase {

  /**
   * An unmapped property stops the run with the framework reason.
   *
   * The operator-visible reason is the existing `unmapped_marking`
   * equivalent of threat-model `unmapped_property`.
   */
  public function testUnmappedPropertyStopsRunWithOperatorVisibleReason(): void {
    agency_migrate_install();
    $this->seedSources();
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.runner')->run('synthetic_item_unmapped', 50, TRUE),
      'unmapped_marking',
    );
    $this->assertNull($this->target(1));
    $this->assertNull($this->target(2));
    $this->assertNull($this->target(3));
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_run', 'r')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_outbox', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * A reader at legacy authority does not return Agency-side rows.
   */
  public function testLegacyAuthorityReaderDoesNotReadAgencyRows(): void {
    agency_migrate_install();
    $authority = $this->container->get('agency_migrate.authority');
    $this->assertSame('legacy', $authority->get('workforce'));
    $this->assertSame('legacy', $authority->get('recruiting'));
    $this->seedSources();
    $receipt = $this->copyAll();
    $this->assertSame('completed', $receipt['status']);
    $target = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $reader = new AuthorityGatedReader(
      $authority,
      $this->container->get('entity_type.manager'),
    );
    $this->assertRefused(fn () => $reader->readAgency('workforce', 1), 'legacy');
    $this->assertRefused(fn () => $reader->readAgency('recruiting', 1), 'legacy');
    $this->assertSame('legacy', $authority->get('workforce'));
    $this->assertSame('legacy', $authority->get('recruiting'));
    $this->assertMinimizedMigrateEvidence('lineage_batch');
  }

  /**
   * Copy keeps id, UUID, revision id and a File Gate-style UUID field.
   */
  public function testCopyPreservesIdentityAndFileGateUuid(): void {
    agency_migrate_install();
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    foreach (['agency_migrate_legacy_item', 'agency_migrate_agency_item'] as $type) {
      FieldStorageConfig::create([
        'field_name' => 'file_uuid',
        'entity_type' => $type,
        'type' => 'string',
        'settings' => ['max_length' => 36],
      ])->save();
      FieldConfig::create([
        'field_name' => 'file_uuid',
        'entity_type' => $type,
        'bundle' => $type,
      ])->save();
    }
    $source = $this->seedSources()[0];
    $source->set('file_uuid', $uuid);
    $source->save();
    $source = $this->source((int) $source->id());
    $receipt = $this->copyAll();
    $this->assertSame('completed', $receipt['status']);
    $target = $this->target((int) $source->id());
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $this->assertSame((int) $source->id(), (int) $target->id());
    $this->assertSame($source->uuid(), $target->uuid());
    $this->assertSame((int) $source->getRevisionId(), (int) $target->getRevisionId());
    $this->assertSame($uuid, (string) $source->get('file_uuid')->value);
    $this->assertSame($uuid, (string) $target->get('file_uuid')->value);
    $this->assertSame(
      $source->get('file_uuid')->value,
      $target->get('file_uuid')->value,
    );
    $this->assertMinimizedMigrateEvidence('lineage_batch');
  }

  /**
   * Asserts one migrate outbox operation exists and carries no secrets.
   *
   * @return list<array<string, mixed>>
   *   Decoded metadata rows.
   */
  private function assertMinimizedMigrateEvidence(string $operation): array {
    $rows = [];
    $result = $this->container->get('database')
      ->select('agency_migrate_outbox', 'o')
      ->fields('o', ['metadata'])
      ->condition('operation', $operation)
      ->execute();
    while ($row = $result->fetchAssoc()) {
      $metadata = json_decode($row['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertIsArray($metadata);
      $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
      $this->assertDoesNotMatchRegularExpression(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\bBearer\b|\.pdf\b|\.docx\b|\.png\b/i',
        $encoded,
      );
      foreach (['email', 'phone', 'token', 'filename', 'sub'] as $key) {
        $this->assertArrayNotHasKey($key, $metadata);
      }
      $rows[] = $metadata;
    }
    $this->assertNotSame([], $rows);
    return $rows;
  }

}
