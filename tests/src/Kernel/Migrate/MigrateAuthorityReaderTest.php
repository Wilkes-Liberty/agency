<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\Core\Entity\ContentEntityInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Production reader refuses Agency-side loads until authority is agency.
 *
 * Failure cases: a legacy or shadow reader returning Agency rows, a
 * refused load leaving no outbox trace, and an allow path emitting a
 * refusal event.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateAuthorityReaderTest extends MigrateKernelTestBase {

  /**
   * At legacy the reader refuses with `legacy` and records outbox evidence.
   *
   * Entity API can still load the planted Agency row.
   */
  public function testLegacyRefusesAgencyLoadAndRecordsEvidence(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $this->seedSources();
    $receipt = $this->copyAll();
    $this->assertSame('completed', $receipt['status']);
    $this->assertInstanceOf(ContentEntityInterface::class, $this->target(1));
    $reader = $this->container->get('agency_migrate.authority_reader');
    $this->assertRefused(
      fn () => $reader->readAgency('synthetic', 'agency_migrate_agency_item', 1),
      'legacy',
    );
    $this->assertInstanceOf(ContentEntityInterface::class, $this->target(1));
    $this->assertSame('legacy', $authority->get('synthetic'));
    $events = $this->assertMinimizedMigrateEvidence('agency_read_refused');
    $this->assertCount(1, $events);
    $this->assertSame('legacy', $events[0]['reason']);
    $this->assertSame('synthetic', $events[0]['domain']);
    $this->assertSame('agency_migrate_agency_item', $events[0]['entity_type']);
    $this->assertSame(1, $events[0]['entity_id']);
    $this->assertArrayNotHasKey('payload', $events[0]);
    $this->assertArrayNotHasKey('uuid', $events[0]);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $events[0]['actor_hash']);
  }

  /**
   * Agency authority returns the planted row and writes no refusal event.
   */
  public function testAgencyAuthorityAllowsLoadWithoutRefusalEvidence(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $authority->transition('synthetic', 'agency', 'cutover');
    $this->seedSources();
    $receipt = $this->copyAll();
    $this->assertSame('completed', $receipt['status']);
    $target = $this->target(1);
    $this->assertInstanceOf(ContentEntityInterface::class, $target);
    $reader = $this->container->get('agency_migrate.authority_reader');
    $loaded = $reader->readAgency('synthetic', 'agency_migrate_agency_item', 1);
    $this->assertSame((int) $target->id(), (int) $loaded->id());
    $this->assertSame($target->uuid(), $loaded->uuid());
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertSame(0, (int) $this->container->get('database')
      ->select('agency_migrate_outbox', 'o')
      ->condition('operation', 'agency_read_refused')
      ->countQuery()
      ->execute()
      ->fetchField());
  }

  /**
   * Shadow and unregistered domains refuse with a trace, never `not_found`.
   */
  public function testShadowAndUnregisteredRefuseWithTrace(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initialize fixture');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $this->seedSources();
    $this->copyAll();
    $reader = $this->container->get('agency_migrate.authority_reader');
    $this->assertRefused(
      fn () => $reader->readAgency('synthetic', 'agency_migrate_agency_item', 1),
      'shadow',
    );
    $this->assertRefused(
      fn () => $reader->readAgency('unregistered', 'agency_migrate_agency_item', 1),
      'invalid_transition',
    );
    $this->assertInstanceOf(ContentEntityInterface::class, $this->target(1));
    $events = $this->assertMinimizedMigrateEvidence('agency_read_refused');
    $this->assertCount(2, $events);
    $this->assertSame('shadow', $events[0]['reason']);
    $this->assertSame('invalid_transition', $events[1]['reason']);
    $this->assertSame('unregistered', $events[1]['domain']);
    foreach ($events as $event) {
      $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event['actor_hash']);
    }
  }

}
