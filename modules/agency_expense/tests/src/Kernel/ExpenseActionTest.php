<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_expense\Kernel;

use Drupal\agency_expense\Entity\Expense;
use Drupal\agency_expense\Exception\ExpenseRefusal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deny paths, replay, timeout and required audit for recordExpense.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class ExpenseActionTest extends ExpenseKernelTestBase {

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
    'agency_staff',
    'agency_expense',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (DomainStore::TYPES as $type) {
      $this->installEntitySchema($type);
    }
  }

  /**
   * Anonymous callers cannot record an expense.
   */
  public function testAnonymousIsForbidden(): void {
    $this->container->get('current_user')
      ->setAccount(new AnonymousUserSession());
    $this->assertRefusal('forbidden', [
      'amount' => '12.50',
    ], 'record-anon-key');
  }

  /**
   * An authenticated account without the explicit grant is refused.
   */
  public function testAuthenticatedWithoutPermissionIsForbidden(): void {
    $this->container->get('current_user')
      ->setAccount($this->authenticatedUser());
    $this->assertRefusal('forbidden', [
      'amount' => '12.50',
    ], 'record-auth-key');
  }

  /**
   * User 1 is refused without an explicit expense grant.
   */
  public function testUserOneWithoutExplicitGrantIsForbidden(): void {
    $admin = User::load(1);
    $this->assertInstanceOf(User::class, $admin);
    $this->container->get('current_user')->setAccount($admin);
    $this->assertRefusal('forbidden', [
      'amount' => '12.50',
    ], 'record-admin-key');
  }

  /**
   * A caller-supplied tenant argument is never accepted.
   */
  public function testTenantArgumentIsForbidden(): void {
    $this->assertRefusal('forbidden', [
      'amount' => '12.50',
      'tenant' => 'other',
    ], 'record-tenant-key');
  }

  /**
   * A request deadline that has already passed is a timeout.
   */
  public function testExpiredNotAfterIsTimeout(): void {
    $this->assertRefusal('timeout', [
      'amount' => '12.50',
      'not_after' => $this->container->get('datetime.time')
        ->getCurrentTime() - 1,
    ], 'record-timeout-key');
    $this->assertSame(0, $this->expenseCount());
  }

  /**
   * An undersized idempotency key is refused.
   */
  public function testInvalidIdempotencyKeyIsRefused(): void {
    $this->assertRefusal('invalid', [
      'amount' => '12.50',
    ], 'short');
  }

  /**
   * A missing or non-positive amount is refused.
   */
  public function testInvalidAmountIsRefused(): void {
    $this->assertRefusal('invalid', [], 'record-missing-amount');
    $this->assertRefusal('invalid', [
      'amount' => '0',
    ], 'record-zero-amount');
    $this->assertRefusal('invalid', [
      'amount' => '-1.00',
    ], 'record-negative-amount');
    $this->assertSame(0, $this->expenseCount());
  }

  /**
   * The same key with a different payload is refused.
   */
  public function testReplayWithDifferentPayloadIsRefused(): void {
    $record = $this->container->get('agency_expense.record');
    $first = $record->record(['amount' => '12.50'], 'record-replay-key');
    $this->assertSame('committed', $first['outcome']);
    try {
      $record->record(['amount' => '20.00'], 'record-replay-key');
      $this->fail('A drifted payload must be refused.');
    }
    catch (ExpenseRefusal $exception) {
      $this->assertSame('invalid', $exception->reason);
    }
    $this->assertSame(1, $this->expenseCount());
  }

  /**
   * The same key and payload returns the original receipt.
   */
  public function testReplayReturnsOriginalReceipt(): void {
    $record = $this->container->get('agency_expense.record');
    $first = $record->record(['amount' => '12.50'], 'record-same-key');
    $second = $record->record(['amount' => '12.50'], 'record-same-key');
    $this->assertSame($first, $second);
    $this->assertSame(1, $this->expenseCount());
  }

  /**
   * A first record stores a drafted row and required minimized evidence.
   */
  public function testRecordCreatesDraftedWithRequiredEvidence(): void {
    $assignment = '00000000-0000-4000-8000-0000000000ab';
    $result = $this->container->get('agency_expense.record')
      ->record([
        'amount' => '12.50',
        'assignment_uuid' => $assignment,
      ], 'record-create-key');
    $this->assertSame('committed', $result['outcome']);
    $this->assertSame('drafted', $result['receipt']['state']);
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('agency_expense');
    $loaded = $storage->loadByProperties([
      'uuid' => $result['receipt']['expense_id'],
    ]);
    $expense = reset($loaded);
    $this->assertInstanceOf(Expense::class, $expense);
    $this->assertSame(12.5, (float) $expense->get('amount')->value);
    $this->assertSame($assignment, $expense->get('assignment_uuid')->value);
    $this->assertSame('drafted', $expense->get('status')->value);
    $row = $this->container->get('database')
      ->select('agency_expense_outbox', 'o')
      ->fields('o')
      ->execute()
      ->fetchAssoc();
    $this->assertIsArray($row);
    $this->assertSame('recordExpense', $row['operation']);
    $metadata = json_decode($row['metadata'], TRUE, 16, JSON_THROW_ON_ERROR);
    $this->assertSame($expense->uuid(), $metadata['expense_id']);
    $this->assertArrayNotHasKey('amount', $metadata);
    $this->assertArrayNotHasKey('assignment_uuid', $metadata);
    $this->assertArrayNotHasKey('contact', $metadata);
    $this->assertArrayNotHasKey('token', $metadata);
  }

  /**
   * Recording does not create Discovery records when that module is present.
   */
  public function testRecordDoesNotCreateDiscoveryRecords(): void {
    $before = [];
    foreach (DomainStore::TYPES as $type) {
      $before[$type] = (int) $this->container->get('entity_type.manager')
        ->getStorage($type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    $this->container->get('agency_expense.record')
      ->record(['amount' => '12.50'], 'record-isolated-key');
    foreach (DomainStore::TYPES as $type) {
      $after = (int) $this->container->get('entity_type.manager')
        ->getStorage($type)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $this->assertSame($before[$type], $after, $type);
    }
  }

  /**
   * Disabling expense storage leaves Discovery installed.
   */
  public function testDisableLeavesStaffIntact(): void {
    $this->assertTrue(
      $this->container->get('module_installer')->uninstall(['agency_expense']),
    );
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('agency_staff'),
    );
    $this->assertTrue(
      $this->container->get('database')->schema()
        ->tableExists('agency_discovery'),
    );
  }

  /**
   * An outbox failure rolls back the expense write.
   */
  public function testEvidenceFailureRollsBackTheExpense(): void {
    $schema = $this->container->get('database')->schema();
    $schema->renameTable(
      'agency_expense_outbox',
      'agency_expense_outbox_hidden',
    );
    try {
      $this->assertRefusal('failed', [
        'amount' => '12.50',
      ], 'record-outbox-key');
    }
    finally {
      $schema->renameTable(
        'agency_expense_outbox_hidden',
        'agency_expense_outbox',
      );
    }
    $this->assertSame(0, $this->expenseCount());
  }

  /**
   * Asserts recordExpense refuses with an allowlisted reason.
   *
   * @param string $reason
   *   Expected refusal.
   * @param array<string, mixed> $input
   *   Command input.
   * @param string $key
   *   Idempotency key.
   */
  private function assertRefusal(
    string $reason,
    array $input,
    string $key,
  ): void {
    try {
      $this->container->get('agency_expense.record')->record($input, $key);
      $this->fail('recordExpense must refuse.');
    }
    catch (ExpenseRefusal $exception) {
      $this->assertSame($reason, $exception->reason);
    }
  }

  /**
   * Counts stored expenses without using entity access.
   */
  private function expenseCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('agency_expense')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
