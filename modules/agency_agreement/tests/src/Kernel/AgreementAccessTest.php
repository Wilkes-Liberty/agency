<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_agreement\Kernel;

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
final class AgreementAccessTest extends AgreementKernelTestBase {

  /**
   * Anonymous, authenticated and user 1 are all denied.
   */
  public function testDefaultAccessDeniesEverything(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement');
    $agreement = $storage->create($this->agreementValues());
    $agreement->save();
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $accounts = [
      new AnonymousUserSession(),
      $this->authenticatedUser(),
      $admin,
    ];
    $handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('agency_agreement');
    foreach ($accounts as $account) {
      foreach (['view', 'update', 'delete'] as $operation) {
        $this->assertTrue(
          $agreement->access($operation, $account, TRUE)->isForbidden(),
          $operation,
        );
      }
      $this->assertTrue(
        $handler->createAccess(NULL, $account, [], TRUE)->isForbidden(),
      );
    }
  }

  /**
   * No write action is registered. Later agreement states have no service.
   */
  public function testNoWriteActionIsRegistered(): void {
    foreach ([
      'agency_agreement.draft',
      'agency_agreement.sign',
      'agency_agreement.activate',
      'agency_agreement.terminate',
      'agency_agreement.write',
    ] as $id) {
      $this->assertFalse($this->container->has($id), $id);
    }
    $this->assertFileDoesNotExist(
      dirname(__DIR__, 3) . '/agency_agreement.services.yml',
    );
  }

  /**
   * Access-checked queries do not list agreements, including for user 1.
   */
  public function testAccessCheckedQueryReturnsNothing(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_agreement');
    $storage->create($this->agreementValues())->save();
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $this->container->get('current_user')->setAccount($admin);
    $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    $this->assertSame([], $ids);
  }

}
