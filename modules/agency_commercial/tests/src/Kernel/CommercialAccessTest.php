<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

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
final class CommercialAccessTest extends CommercialKernelTestBase {

  /**
   * Anonymous, authenticated and user 1 are all denied.
   */
  public function testDefaultAccessDeniesEverything(): void {
    $opportunity_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_opportunity');
    $activity_storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_activity');
    $opportunity = $opportunity_storage->create($this->opportunityValues());
    $opportunity->save();
    $activity = $activity_storage->create($this->activityValues());
    $activity->save();
    $admin = User::create([
      'uid' => 1,
      'name' => 'commercial-admin',
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
          $opportunity->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
        $this->assertTrue(
          $activity->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
      }
      $this->assertTrue(
        $this->container->get('entity_type.manager')
          ->getAccessControlHandler('agency_opportunity')
          ->createAccess(NULL, $account, [], TRUE)
          ->isForbidden(),
      );
      $this->assertTrue(
        $this->container->get('entity_type.manager')
          ->getAccessControlHandler('agency_activity')
          ->createAccess(NULL, $account, [], TRUE)
          ->isForbidden(),
      );
    }
  }

}
