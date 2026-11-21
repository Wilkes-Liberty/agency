<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_campaign\Kernel;

use Drupal\agency_campaign\Entity\Campaign;
use Drupal\Core\Entity\EntityStorageException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Explicit identifiers are preserved and occupied values are refused.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CampaignIdentityTest extends CampaignKernelTestBase {

  /**
   * Restore keeps the identifiers and contents of a historical campaign.
   */
  public function testExplicitIdentifiersArePreserved(): void {
    $uuid = '00000000-0000-4000-8000-000000000017';
    $campaign = $this->createCampaign([
      'id' => 17,
      'uuid' => $uuid,
      'revision_id' => 42,
      'created' => 1700000000,
      'changed' => 1700000001,
      'owner_uid' => 9,
      'revision_created' => 1700000002,
      'status' => 'draft',
      'title' => 'Historical campaign',
    ]);
    $campaign->enforceIsNew(TRUE);
    $campaign->isDefaultRevision(TRUE);
    $campaign->setNewRevision(FALSE);
    $campaign->set('revision_id', 42);
    $campaign->setSyncing(TRUE);
    $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign')
      ->restore($campaign);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign');
    $storage->resetCache([17]);
    $loaded = $storage->load(17);
    $this->assertInstanceOf(Campaign::class, $loaded);
    $this->assertSame(17, (int) $loaded->id());
    $this->assertSame($uuid, $loaded->uuid());
    $this->assertSame(42, (int) $loaded->getRevisionId());
    $this->assertSame(1700000000, (int) $loaded->get('created')->value);
    $this->assertSame(1700000001, (int) $loaded->get('changed')->value);
    $this->assertSame(9, (int) $loaded->get('owner_uid')->value);
    $this->assertSame(
      1700000002,
      (int) $loaded->get('revision_created')->value,
    );
    $this->assertSame('draft', $loaded->get('status')->value);
    $this->assertSame('Historical campaign', $loaded->get('title')->value);
  }

  /**
   * An occupied integer id is refused instead of being reused.
   */
  public function testOccupiedIdIsRefused(): void {
    $first = $this->createCampaign([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000005',
    ]);
    $first->enforceIsNew(TRUE);
    $first->save();
    $this->assertSame(5, (int) $first->id());
    $second = $this->createCampaign([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000006',
    ]);
    $second->enforceIsNew(TRUE);
    try {
      $second->save();
      $this->fail('Occupied integer ids must be refused.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('collision', $exception->getMessage());
    }
  }

  /**
   * An occupied UUID is refused instead of being reused.
   */
  public function testOccupiedUuidIsRefused(): void {
    $uuid = '00000000-0000-4000-8000-000000000007';
    $first = $this->createCampaign(['uuid' => $uuid]);
    $first->save();
    $second = $this->createCampaign(['uuid' => $uuid]);
    $second->enforceIsNew(TRUE);
    try {
      $second->save();
      $this->fail('Occupied UUIDs must be refused.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('collision', $exception->getMessage());
    }
  }

  /**
   * An occupied revision id is refused on restore.
   */
  public function testOccupiedRevisionIdIsRefused(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_campaign');
    $first = $this->createCampaign([
      'id' => 8,
      'uuid' => '00000000-0000-4000-8000-000000000008',
    ]);
    $first->enforceIsNew(TRUE);
    $first->isDefaultRevision(TRUE);
    $first->setNewRevision(FALSE);
    $first->set('revision_id', 80);
    $first->setSyncing(TRUE);
    $storage->restore($first);
    $second = $this->createCampaign([
      'id' => 9,
      'uuid' => '00000000-0000-4000-8000-000000000009',
    ]);
    $second->enforceIsNew(TRUE);
    $second->isDefaultRevision(TRUE);
    $second->setNewRevision(FALSE);
    $second->set('revision_id', 80);
    $second->setSyncing(TRUE);
    try {
      $storage->restore($second);
      $this->fail('Occupied revision ids must be refused.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('collision', $exception->getMessage());
    }
    $this->assertNull($storage->load(9));
  }

}
