<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_agreement\Kernel;

use Drupal\agency_agreement\Entity\Agreement;
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
final class AgreementIdentityTest extends AgreementKernelTestBase {

  /**
   * Restore keeps the identifiers and contents of a historical agreement.
   */
  public function testExplicitIdentifiersArePreserved(): void {
    $uuid = '00000000-0000-4000-8000-000000000017';
    $engagement = '00000000-0000-4000-8000-0000000000ae';
    $agreement = $this->createAgreement([
      'id' => 17,
      'uuid' => $uuid,
      'revision_id' => 42,
      'created' => 1700000000,
      'changed' => 1700000001,
      'owner_uid' => 9,
      'revision_created' => 1700000002,
      'status' => 'draft',
      'engagement_uuid' => $engagement,
      'terms' => 'Historical terms.',
    ]);
    $agreement->enforceIsNew(TRUE);
    $agreement->isDefaultRevision(TRUE);
    $agreement->setNewRevision(FALSE);
    $agreement->set('revision_id', 42);
    $agreement->setSyncing(TRUE);
    $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement')
      ->restore($agreement);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement');
    $storage->resetCache([17]);
    $loaded = $storage->load(17);
    $this->assertInstanceOf(Agreement::class, $loaded);
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
    $this->assertSame($engagement, $loaded->get('engagement_uuid')->value);
    $this->assertSame('Historical terms.', $loaded->get('terms')->value);
  }

  /**
   * An occupied integer id is refused instead of being reused.
   */
  public function testOccupiedIdIsRefused(): void {
    $first = $this->createAgreement([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000005',
    ]);
    $first->enforceIsNew(TRUE);
    $first->save();
    $this->assertSame(5, (int) $first->id());
    $second = $this->createAgreement([
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
    $first = $this->createAgreement(['uuid' => $uuid]);
    $first->save();
    $second = $this->createAgreement(['uuid' => $uuid]);
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
      ->getStorage('agency_agreement');
    $first = $this->createAgreement([
      'id' => 8,
      'uuid' => '00000000-0000-4000-8000-000000000008',
    ]);
    $first->enforceIsNew(TRUE);
    $first->isDefaultRevision(TRUE);
    $first->setNewRevision(FALSE);
    $first->set('revision_id', 80);
    $first->setSyncing(TRUE);
    $storage->restore($first);
    $second = $this->createAgreement([
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
