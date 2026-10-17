<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Identity-preserving copy and lineage, including the occupied-id failure.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateCopyTest extends MigrateKernelTestBase {

  /**
   * Copied records keep integer ids, UUIDs, revision ids and authorship.
   */
  public function testCopyPreservesIdentityAndLineage(): void {
    $sources = $this->seedSources();
    $first = $sources[0];
    $first->setNewRevision(TRUE);
    $first->set('payload', 'body-a-revision');
    $first->set('revision_log_message', 'second-alpha');
    $first->set('revision_user', (int) $this->author->id());
    $first->save();

    $receipt = $this->copyAll();
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(3, $receipt['copied']);
    $this->assertSame(0, $receipt['skipped']);

    foreach ([1, 2, 3] as $id) {
      $source = $this->source($id);
      $target = $this->target($id);
      $this->assertInstanceOf(ContentEntityInterface::class, $target);
      $this->assertSame((int) $source->id(), (int) $target->id());
      $this->assertSame($source->uuid(), $target->uuid());
      $this->assertSame((int) $source->getRevisionId(), (int) $target->getRevisionId());
      $this->assertSame((int) $source->get('created')->value, (int) $target->get('created')->value);
      $this->assertSame((int) $source->get('changed')->value, (int) $target->get('changed')->value);
      $this->assertSame((int) $source->get('revision_user')->target_id, (int) $target->get('revision_user')->target_id);
      $this->assertSame((string) $source->get('revision_log_message')->value, (string) $target->get('revision_log_message')->value);
      $this->assertSame((string) $source->get('title')->value, (string) $target->get('title')->value);
      $this->assertSame((string) $source->get('payload')->value, (string) $target->get('payload')->value);
      $lineage = $this->container->get('agency_migrate.lineage')->bySource('agency_migrate_legacy_item', $id);
      $this->assertNotNull($lineage);
      $this->assertSame($source->uuid(), $lineage['source_uuid']);
      $this->assertSame($target->uuid(), $lineage['target_uuid']);
      $this->assertSame((int) $source->getRevisionId(), (int) $lineage['source_revision_id']);
      $this->assertSame((int) $target->getRevisionId(), (int) $lineage['target_revision_id']);
      $this->assertSame($receipt['run_id'], $lineage['run_id']);
      $reverse = $this->container->get('agency_migrate.lineage')->byTarget('agency_migrate_agency_item', $id);
      $this->assertSame($lineage['lid'], $reverse['lid']);
    }
    $this->assertSame('body-a-revision', (string) $this->target(1)->get('payload')->value);
    $this->assertSame('second-alpha', (string) $this->target(1)->get('revision_log_message')->value);

    $events = (int) $this->container->get('database')
      ->select('agency_migrate_outbox', 'o')
      ->condition('operation', 'lineage_batch')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $events);
  }

  /**
   * A target id already used by a different UUID stops that copy.
   */
  public function testOccupiedIdWithDifferentUuidRefuses(): void {
    $this->seedSources();
    $storage = $this->container->get('entity_type.manager')->getStorage('agency_migrate_agency_item');
    $blocker = $storage->create([
      'title' => 'Taken',
      'payload' => 'other',
      'related_id' => 0,
      'access_class' => 'open',
    ]);
    $blocker->save();
    $this->assertSame(1, (int) $blocker->id());
    $this->assertNotSame($this->source(1)->uuid(), $blocker->uuid());
    $this->assertRefused(fn () => $this->copyAll(), 'identity_conflict');
    $this->assertSame(0, (int) $this->container->get('database')->select('agency_migrate_lineage', 'l')->countQuery()->execute()->fetchField());
    $this->assertSame('Taken', (string) $this->target(1)->get('title')->value);
    $this->assertNull($this->target(2));
  }

  /**
   * A caller without the run permission is refused before any write.
   */
  public function testUnauthorizedRunRefuses(): void {
    $this->seedSources();
    $stranger = User::create(['name' => 'stranger', 'status' => 1]);
    $stranger->save();
    $this->container->get('current_user')->setAccount($stranger);
    $this->assertRefused(fn () => $this->copyAll(), 'forbidden');
    $this->assertNull($this->target(1));
  }

}
