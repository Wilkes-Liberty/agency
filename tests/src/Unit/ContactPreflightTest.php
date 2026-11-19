<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

use Drupal\agency_commercial\ContactMigration\ContactPreflight;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies review evidence is required before reading source history.
 *
 * @group agency_commercial
 */
#[Group('agency_commercial')]
final class ContactPreflightTest extends UnitTestCase {

  /**
   * A duplicate mailbox cannot replace an independently reviewed source UUID.
   */
  public function testRequiresReviewForBothIdentitiesBeforeProjection(): void {
    $source = [
      'host' => 'sample-host',
      'tenant' => 'sample-tenant',
      'contacts' => [
        $this->contact(1, '10000000-0000-4000-8000-000000000001'),
        $this->contact(2, '10000000-0000-4000-8000-000000000002'),
      ],
      'users' => [],
      'claims' => [],
    ];
    [$first, $second] = $source['contacts'];
    $person = $this->review($first, 'person');
    $organization = $this->review($second, 'organization');
    $occupancy = array_fill_keys(['person', 'organization'], ['ids' => [], 'uuids' => [], 'revision_ids' => []]);

    $conflicting = $this->review($first, 'organization');
    $stale = $organization;
    $stale['watermark'] = str_repeat('0', 64);
    $stale['review_digest'] = ContactPreflight::reviewDigest($stale);
    $tampered = $organization;
    $tampered['shareable_alias'] = 'Unreviewed display text';
    $otherTenant = $organization;
    $otherTenant['tenant'] = 'other-tenant';
    $otherTenant['review_digest'] = ContactPreflight::reviewDigest($otherTenant);

    foreach ([
      'missing' => ['contact_crosswalk_missing', [$person]],
      'duplicate' => ['contact_crosswalk_conflicting', [$person, $person, $organization]],
      'conflicting' => ['contact_crosswalk_conflicting', [$person, $conflicting, $organization]],
      'stale' => ['contact_crosswalk_stale', [$person, $stale]],
      'tampered' => ['contact_crosswalk_invalid', [$person, $tampered]],
      'wrong tenant' => ['contact_crosswalk_invalid', [$person, $otherTenant]],
      'fully reviewed' => ['contact_source_layout_unreviewed', [$person, $organization]],
    ] as $case => [$reason, $reviews]) {
      try {
        ContactPreflight::preflight($source, $reviews, $occupancy);
        $this->fail("Expected refusal for {$case}");
      }
      catch (CommercialRefusal $exception) {
        $this->assertSame($reason, $exception->getMessage(), $case);
      }
    }
  }

  /**
   * Uses an unreviewed source layout to catch early field materialization.
   */
  private function contact(int $id, string $uuid): array {
    return [
      'version' => 1,
      'source_type' => 'crm_contact',
      'id' => $id,
      'uuid' => $uuid,
      'layout' => ['fields' => ['unknown_extension' => []]],
      'revision_count' => 1,
      'default_revision_id' => $id,
      'revisions' => [
        [
          'revision_id' => $id,
          'default' => TRUE,
          'langcode' => 'en',
          'languages' => ['en'],
          'fields' => ['email' => ['en' => [['value' => 'same@example.test']]]],
        ],
      ],
    ];
  }

  /**
   * Creates a synthetic reviewed row for one fixed host and tenant.
   */
  private function review(array $snapshot, string $kind): array {
    $row = [
      'host' => 'sample-host',
      'tenant' => 'sample-tenant',
      'source_id' => $snapshot['id'],
      'source_uuid' => $snapshot['uuid'],
      'kind' => $kind,
      'watermark' => ContactPreflight::watermark($snapshot, []),
      'person' => NULL,
      'shareable_alias' => NULL,
    ];
    $row['review_digest'] = ContactPreflight::reviewDigest($row);
    return $row;
  }

}
