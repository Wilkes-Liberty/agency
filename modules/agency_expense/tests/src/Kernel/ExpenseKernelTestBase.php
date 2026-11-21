<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_expense\Kernel;

use Drupal\agency_expense\Entity\Expense;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Shared kernel fixture for expense storage and record.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
abstract class ExpenseKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'key',
    'encrypt',
    'audit_chain',
    'field_guard',
    'agency',
    'agency_expense',
  ];

  /**
   * Explicitly authorized coordinator, distinct from uid 1.
   */
  protected User $coordinator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('agency_expense');
    $this->installSchema('agency_expense', [
      'agency_expense_outbox',
      'agency_expense_intent',
    ]);
    $this->installConfig(['system', 'user', 'audit_chain']);
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'expense_coordinator',
      'label' => 'Expense coordinator',
      'permissions' => ['coordinate agency expenses'],
    ])->save();
    $this->coordinator = User::create([
      'name' => 'example-expense-coordinator',
      'status' => 1,
      'roles' => ['expense_coordinator'],
    ]);
    $this->coordinator->save();
    $this->container->get('current_user')->setAccount($this->coordinator);
  }

  /**
   * Builds a synthetic expense that is not yet recorded.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return array<string, mixed>
   *   Create values.
   */
  protected function expenseValues(array $overrides = []): array {
    return $overrides + [
      'amount' => '12.50',
      'assignment_uuid' => '',
      'status' => 'drafted',
      'owner_uid' => (int) $this->coordinator->id(),
    ];
  }

  /**
   * Creates a synthetic expense record without saving it.
   *
   * @param array<string, mixed> $overrides
   *   Field values.
   *
   * @return \Drupal\agency_expense\Entity\Expense
   *   Unsaved expense.
   */
  protected function createExpense(array $overrides = []): Expense {
    $expense = $this->container->get('entity_type.manager')
      ->getStorage('agency_expense')
      ->create($this->expenseValues($overrides));
    $this->assertInstanceOf(Expense::class, $expense);
    return $expense;
  }

  /**
   * Whether a service id is registered.
   *
   * PHPStan treats ContainerInterface::has() as false for unknown ids.
   */
  protected function hasService(string $id): bool {
    $container = $this->container;
    if (!method_exists($container, 'getServiceIds')) {
      return FALSE;
    }
    return in_array($id, $container->getServiceIds(), TRUE);
  }

  /**
   * Creates an authenticated account that is not user 1.
   */
  protected function authenticatedUser(): User {
    $account = User::create([
      'name' => 'expense-reader',
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

}
