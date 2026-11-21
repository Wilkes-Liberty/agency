<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\EngagementLineageMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lineage names the same identity on both sides and does not persist.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyEngagementLineageMapTest extends TestCase {

  /**
   * Proposed lineage preserves id, UUID and revision on both sides.
   */
  public function testProposePreservesIdentity(): void {
    $map = new EngagementLineageMap();
    $this->assertSame([
      'source_id' => 12,
      'source_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'source_revision_id' => 7,
      'target_id' => 12,
      'target_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'target_revision_id' => 7,
    ], $map->propose([
      'id' => 12,
      'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'revision_id' => 7,
    ]));
  }

  /**
   * Persist is refused with empty lineage while types are unregistered.
   */
  public function testPersistIsUnmapped(): void {
    $map = new EngagementLineageMap();
    $stopped = $map->persist([
      'id' => 12,
      'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'revision_id' => 7,
    ]);
    $this->assertSame('refused', $stopped['outcome']);
    $this->assertSame('unmapped_property', $stopped['reason']);
    $this->assertCount(0, $stopped['lineage']);
    $this->assertNotSame(
      EngagementDecision::REASON_NOT_FOUND,
      $stopped['reason'],
    );
  }

  /**
   * Extra keys, including rate, cannot enter lineage.
   */
  public function testExtraKeysAreUnmapped(): void {
    $map = new EngagementLineageMap();
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('unmapped_property');
    $map->propose([
      'id' => 12,
      'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'revision_id' => 7,
      'hourly_rate_minor' => 15000,
    ]);
  }

}
