<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Entity\IntakeSubmission;
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
final class IntakeIdentityTest extends IntakeKernelTestBase {

  /**
   * Restore keeps the identifiers and contents of a historical submission.
   */
  public function testExplicitIdentifiersArePreserved(): void {
    $uuid = '00000000-0000-4000-8000-000000000027';
    $payload = '{"source":"example.test","topic":"intake"}';
    $submission = $this->createSubmission([
      'id' => 27,
      'uuid' => $uuid,
      'revision_id' => 52,
      'created' => 1700000000,
      'changed' => 1700000001,
      'owner_uid' => 9,
      'revision_created' => 1700000002,
      'status' => 'received',
      'payload' => $payload,
      'dispatch_key' => 'intake.dispatch.preserved',
    ]);
    $submission->enforceIsNew(TRUE);
    $submission->isDefaultRevision(TRUE);
    $submission->setNewRevision(FALSE);
    $submission->set('revision_id', 52);
    $submission->setSyncing(TRUE);
    $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission')
      ->restore($submission);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $storage->resetCache([27]);
    $loaded = $storage->load(27);
    $this->assertInstanceOf(IntakeSubmission::class, $loaded);
    $this->assertSame(27, (int) $loaded->id());
    $this->assertSame($uuid, $loaded->uuid());
    $this->assertSame(52, (int) $loaded->getRevisionId());
    $this->assertSame(1700000000, (int) $loaded->get('created')->value);
    $this->assertSame(1700000001, (int) $loaded->get('changed')->value);
    $this->assertSame(9, (int) $loaded->get('owner_uid')->value);
    $this->assertSame(1700000002, (int) $loaded->get('revision_created')->value);
    $this->assertSame('received', $loaded->get('status')->value);
    $this->assertSame($payload, $loaded->get('payload')->value);
    $this->assertSame(
      'intake.dispatch.preserved',
      $loaded->get('dispatch_key')->value,
    );
  }

  /**
   * An occupied integer id is refused instead of being reused.
   */
  public function testOccupiedIdIsRefused(): void {
    $first = $this->createSubmission([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000015',
    ]);
    $first->enforceIsNew(TRUE);
    $first->save();
    $this->assertSame(5, (int) $first->id());
    $second = $this->createSubmission([
      'id' => 5,
      'uuid' => '00000000-0000-4000-8000-000000000016',
      'dispatch_key' => 'intake.dispatch.second',
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
      ->getStorage('agency_intake_submission');
    $storage->resetCache([5]);
    $this->assertSame(
      '00000000-0000-4000-8000-000000000015',
      $storage->load(5)?->uuid(),
    );
  }

  /**
   * An occupied UUID is refused instead of being reused.
   */
  public function testOccupiedUuidIsRefused(): void {
    $uuid = '00000000-0000-4000-8000-000000000017';
    $first = $this->createSubmission(['uuid' => $uuid]);
    $first->save();
    $second = $this->createSubmission([
      'uuid' => $uuid,
      'dispatch_key' => 'intake.dispatch.other',
    ]);
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
      ->getStorage('agency_intake_submission');
    $first = $this->createSubmission([
      'id' => 8,
      'uuid' => '00000000-0000-4000-8000-000000000018',
    ]);
    $first->enforceIsNew(TRUE);
    $first->isDefaultRevision(TRUE);
    $first->setNewRevision(FALSE);
    $first->set('revision_id', 80);
    $first->setSyncing(TRUE);
    $storage->restore($first);
    $second = $this->createSubmission([
      'id' => 9,
      'uuid' => '00000000-0000-4000-8000-000000000019',
      'dispatch_key' => 'intake.dispatch.other',
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
