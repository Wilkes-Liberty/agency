<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

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
final class RecruitingAccessTest extends RecruitingKernelTestBase {

  /**
   * Anonymous, authenticated and user 1 are all denied.
   */
  public function testDefaultAccessDeniesEverything(): void {
    $posting_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_career_posting');
    $application_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_application');
    $posting = $posting_storage->create($this->postingValues());
    $posting->save();
    $application = $application_storage->create($this->applicationValues());
    $application->save();
    $admin = User::create([
      'uid' => 1,
      'name' => 'recruiting-admin',
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
          $posting->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
        $this->assertTrue(
          $application->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
      }
      $this->assertTrue(
        $this->container->get('entity_type.manager')
          ->getAccessControlHandler('agency_career_posting')
          ->createAccess(NULL, $account, [], TRUE)
          ->isForbidden(),
      );
      $this->assertTrue(
        $this->container->get('entity_type.manager')
          ->getAccessControlHandler('agency_application')
          ->createAccess(NULL, $account, [], TRUE)
          ->isForbidden(),
      );
    }
  }

}
