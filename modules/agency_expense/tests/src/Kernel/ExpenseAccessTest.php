<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_expense\Kernel;

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
final class ExpenseAccessTest extends ExpenseKernelTestBase {

  /**
   * Anonymous, authenticated and user 1 are all denied.
   */
  public function testDefaultAccessDeniesEverything(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_expense');
    $expense = $storage->create($this->expenseValues());
    $expense->save();
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $accounts = [
      new AnonymousUserSession(),
      $this->authenticatedUser(),
      $admin,
      $this->coordinator,
    ];
    $handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('agency_expense');
    foreach ($accounts as $account) {
      foreach (['view', 'update', 'delete'] as $operation) {
        $this->assertTrue(
          $expense->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
      }
      $this->assertTrue(
        $handler->createAccess(NULL, $account, [], TRUE)->isForbidden(),
      );
    }
  }

  /**
   * Access-checked queries do not list expenses, including for user 1.
   */
  public function testAccessCheckedQueryReturnsNothing(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_expense');
    $storage->create($this->expenseValues())->save();
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $this->container->get('current_user')->setAccount($admin);
    $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    $this->assertSame([], $ids);
  }

}
