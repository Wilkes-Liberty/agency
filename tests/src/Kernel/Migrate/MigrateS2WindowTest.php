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
   * equivalent of threat-model `unmapped_property`. A minimized outbox
   * intent is the Audit Chain trace for that refused write.
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
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_outbox', 'o')->condition('operation', 'lineage_batch')->countQuery()->execute()->fetchField());
    $events = $this->assertMinimizedMigrateEvidence('unmapped_marking');
    $this->assertCount(1, $events);
    $this->assertSame('unmapped_marking', $events[0]['reason']);
    $this->assertSame('synthetic_item_unmapped', $events[0]['plugin_id']);
    $this->assertSame('agency_migrate_legacy_item', $events[0]['source_type']);
    $this->assertSame('agency_migrate_agency_item', $events[0]['target_type']);
    $this->assertIsInt($events[0]['unmapped_count']);
    $this->assertGreaterThan(0, $events[0]['unmapped_count']);
    $this->assertArrayNotHasKey('properties', $events[0]);
    $this->assertArrayNotHasKey('payload', $events[0]);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $events[0]['actor_hash']);
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

}
