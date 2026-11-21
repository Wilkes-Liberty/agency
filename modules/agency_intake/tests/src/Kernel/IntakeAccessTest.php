<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Default access denies view, update, delete and create for every account.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeAccessTest extends IntakeKernelTestBase {

  /**
   * Anonymous, authenticated and user 1 are all denied.
   */
  public function testDefaultAccessDeniesEverything(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $submission = $storage->create($this->submissionValues());
    $submission->save();
    $admin = User::create([
      'uid' => 1,
      'name' => 'intake-admin',
      'status' => 1,
    ]);
    $admin->enforceIsNew();
    $admin->save();
    $accounts = [
      new AnonymousUserSession(),
      $this->authenticatedUser(),
      $admin,
    ];
    foreach ($accounts as $account) {
      foreach (['view', 'update', 'delete'] as $operation) {
        $this->assertTrue(
          $submission->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
      }
      $this->assertTrue(
        $this->container->get('entity_type.manager')
          ->getAccessControlHandler('agency_intake_submission')
          ->createAccess(NULL, $account, [], TRUE)
          ->isForbidden(),
      );
    }
  }

  /**
   * Access-checked intake queries stay empty while rows exist.
   */
  public function testIntakeQueryRemainsDenied(): void {
    $submission = $this->createSubmission();
    $submission->save();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_intake_submission');
    $this->assertSame([], $storage->getQuery()->accessCheck(TRUE)->execute());
  }

}
