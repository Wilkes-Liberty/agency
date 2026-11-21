<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Entity\Opportunity;
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
final class CommercialIdentityTest extends CommercialKernelTestBase {

  /**
   * Restore keeps the identifiers and contents of a historical opportunity.
   */
  public function testExplicitIdentifiersArePreserved(): void {
    $uuid = '00000000-0000-4000-8000-000000000017';
    $opportunity = $this->createOpportunity([
      'id' => 17,
      'uuid' => $uuid,
      'revision_id' => 42,
      'created' => 1700000000,
      'changed' => 1700000001,
      'owner_uid' => 9,
      'revision_created' => 1700000002,
      'title' => 'Preserved opportunity',
      'stage' => 'propose',
      'amount' => '99.50',
      'status' => 'open',
    ]);
    $opportunity->enforceIsNew(TRUE);
    $opportunity->isDefaultRevision(TRUE);
    $opportunity->setNewRevision(FALSE);
    $opportunity->set('revision_id', 42);
    $opportunity->setSyncing(TRUE);
    $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity')
      ->restore($opportunity);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $storage->resetCache([17]);
    $loaded = $storage->load(17);
    $this->assertInstanceOf(Opportunity::class, $loaded);
    $this->assertSame(17, (int) $loaded->id());
    $this->assertSame($uuid, $loaded->uuid());
    $this->assertSame(42, (int) $loaded->getRevisionId());
    $this->assertSame(1700000000, (int) $loaded->get('created')->value);
    $this->assertSame(1700000001, (int) $loaded->get('changed')->value);
    $this->assertSame(9, (int) $loaded->get('owner_uid')->value);
    $this->assertSame(1700000002, (int) $loaded->get('revision_created')->value);
    $this->assertSame('Preserved opportunity', $loaded->get('title')->value);
    $this->assertSame('propose', $loaded->get('stage')->value);
    $this->assertSame('99.50', $loaded->get('amount')->value);
    $this->assertSame('open', $loaded->get('status')->value);
  }

  /**
   * An occupied integer id is refused instead of being reused.
   */
  public function testOccupiedIdIsRefused(): void {
    $first = $this->createOpportunity([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000005',
    ]);
    $first->enforceIsNew(TRUE);
    $first->save();
    $this->assertSame(5, (int) $first->id());
    $second = $this->createOpportunity([
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
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $storage->resetCache([5]);
    $this->assertSame(
      '00000000-0000-4000-8000-000000000005',
      $storage->load(5)?->uuid(),
    );
  }

  /**
   * An occupied UUID is refused instead of being reused.
   */
  public function testOccupiedUuidIsRefused(): void {
    $uuid = '00000000-0000-4000-8000-000000000007';
    $first = $this->createActivity(['uuid' => $uuid]);
    $first->save();
    $second = $this->createActivity(['uuid' => $uuid]);
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
   * An occupied revision id is refused on restore, including activity (D4).
   */
  public function testOccupiedRevisionIdIsRefused(): void {
    $opportunity_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $first_opportunity = $this->createOpportunity([
      'id' => 8,
      'uuid' => '00000000-0000-4000-8000-000000000008',
    ]);
    $first_opportunity->enforceIsNew(TRUE);
    $first_opportunity->isDefaultRevision(TRUE);
    $first_opportunity->setNewRevision(FALSE);
    $first_opportunity->set('revision_id', 80);
    $first_opportunity->setSyncing(TRUE);
    $opportunity_storage->restore($first_opportunity);
    $second_opportunity = $this->createOpportunity([
      'id' => 9,
      'uuid' => '00000000-0000-4000-8000-000000000009',
    ]);
    $second_opportunity->enforceIsNew(TRUE);
    $second_opportunity->isDefaultRevision(TRUE);
    $second_opportunity->setNewRevision(FALSE);
    $second_opportunity->set('revision_id', 80);
    $second_opportunity->setSyncing(TRUE);
    try {
      $opportunity_storage->restore($second_opportunity);
      $this->fail('Occupied revision ids must be refused.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('collision', $exception->getMessage());
    }
    $this->assertNull($opportunity_storage->load(9));

    $activity_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_activity');
    $first_activity = $this->createActivity([
      'id' => 18,
      'uuid' => '00000000-0000-4000-8000-000000000018',
    ]);
    $first_activity->enforceIsNew(TRUE);
    $first_activity->isDefaultRevision(TRUE);
    $first_activity->setNewRevision(FALSE);
    $first_activity->set('revision_id', 81);
    $first_activity->setSyncing(TRUE);
    $activity_storage->restore($first_activity);
    $second_activity = $this->createActivity([
      'id' => 19,
      'uuid' => '00000000-0000-4000-8000-000000000019',
    ]);
    $second_activity->enforceIsNew(TRUE);
    $second_activity->isDefaultRevision(TRUE);
    $second_activity->setNewRevision(FALSE);
    $second_activity->set('revision_id', 81);
    $second_activity->setSyncing(TRUE);
    try {
      $activity_storage->restore($second_activity);
      $this->fail('Occupied activity revision ids must be refused.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('collision', $exception->getMessage());
    }
    $this->assertNull($activity_storage->load(19));
  }

}
