<?php

declare(strict_types=1);

namespace Drupal\agency_expense;

use Drupal\agency_expense\Entity\Expense;
use Drupal\agency_expense\Exception\ExpenseRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\user\UserInterface;

/**
 * Named recordExpense action: deny paths, replay and required audit first.
 */
final class RecordExpense {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountProxyInterface $currentUser,
    private readonly ExplicitPermissionChecker $permissions,
    private readonly ExpenseOutbox $outbox,
    private readonly TimeInterface $time,
    private readonly TransactionCommitter $committer,
  ) {}

  /**
   * Creates a drafted expense. Later states have no ontology action yet.
   *
   * Recording does not grant relationship, assignment or roster authority.
   *
   * @param array<string, mixed> $input
   *   Amount required. Optional assignment_uuid and not_after.
   * @param string $key
   *   One user-intent idempotency key.
   *
   * @return array<string, mixed>
   *   Minimal committed receipt.
   */
  public function record(array $input, string $key): array {
    $this->assertRequest($input, $key);
    $account = $this->coordinator();
    $actor = hash('sha256', 'coordinator:' . $account->id());
    $semantic = $this->semantic($input);
    $intent = hash('sha256', json_encode(
      [$actor, 'recordExpense', '', $key],
      JSON_THROW_ON_ERROR,
    ));
    $hash = $this->fingerprint($semantic);
    $transaction = $this->database->startTransaction();
    try {
      $replay = $this->replay($intent, $hash, $account);
      if ($replay !== NULL) {
        $this->committer->commit($transaction);
        unset($transaction);
        return ['outcome' => 'committed', 'receipt' => $replay];
      }
      $expense = $this->write($semantic, $account);
      $event = $this->outbox->recordRequired(
        $expense->uuid(),
        $actor,
        (string) $expense->get('status')->value,
      );
      $receipt = [
        'id' => $event,
        'expense_id' => $expense->uuid(),
        'effective_at' => $this->time->getCurrentTime(),
        'state' => $expense->get('status')->value,
      ];
      $this->database->insert('agency_expense_intent')->fields([
        'id' => $intent,
        'payload_hash' => $hash,
        'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR),
      ])->execute();
      $this->committer->commit($transaction);
      unset($transaction);
      return ['outcome' => 'committed', 'receipt' => $receipt];
    }
    catch (\Throwable $exception) {
      try {
        $transaction?->rollBack();
      }
      finally {
        unset($transaction);
      }
      if ($exception instanceof ExpenseRefusal) {
        throw $exception;
      }
      throw new ExpenseRefusal('failed');
    }
  }

  /**
   * Refuses tenant arguments, stale deadlines and malformed keys first.
   *
   * @param array<string, mixed> $input
   *   Caller payload.
   * @param string $key
   *   Idempotency key.
   */
  private function assertRequest(array $input, string $key): void {
    if (array_key_exists('tenant', $input)) {
      throw new ExpenseRefusal('forbidden');
    }
    $allowed = ['amount', 'assignment_uuid', 'not_after'];
    foreach (array_keys($input) as $field) {
      if (!in_array($field, $allowed, TRUE)) {
        throw new ExpenseRefusal('invalid');
      }
    }
    if (isset($input['not_after'])) {
      if (!is_int($input['not_after'])
        || $input['not_after'] <= $this->time->getCurrentTime()) {
        throw new ExpenseRefusal('timeout');
      }
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $key)) {
      throw new ExpenseRefusal('invalid');
    }
  }

  /**
   * Requires an explicit coordinator grant on a fresh, non-anonymous account.
   */
  private function coordinator(): UserInterface {
    $user = $this->entities->getStorage('user')
      ->loadUnchanged($this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()
      || $user->isAnonymous()
      || !$this->permissions->hasExplicitPermission(
        $this->currentUser,
        'coordinate agency expenses',
      )
      || !$this->permissions->hasExplicitPermission(
        $user,
        'coordinate agency expenses',
      )) {
      throw new ExpenseRefusal('forbidden');
    }
    return $user;
  }

  /**
   * Returns the drafted write payload after validation.
   *
   * @param array<string, mixed> $input
   *   Caller payload.
   *
   * @return array{amount: string, assignment_uuid?: string}
   *   Canonical write input.
   */
  private function semantic(array $input): array {
    if (!array_key_exists('amount', $input)) {
      throw new ExpenseRefusal('invalid');
    }
    $amount = $this->amount($input['amount']);
    $semantic = ['amount' => $amount];
    if (array_key_exists('assignment_uuid', $input)) {
      if (!is_string($input['assignment_uuid'])
        || !Uuid::isValid($input['assignment_uuid'])) {
        throw new ExpenseRefusal('invalid');
      }
      $semantic['assignment_uuid'] = $input['assignment_uuid'];
    }
    return $semantic;
  }

  /**
   * Canonicalizes a required positive decimal amount.
   *
   * @param mixed $value
   *   Caller amount.
   *
   * @return string
   *   Digit string with at most four fractional places.
   */
  private function amount(mixed $value): string {
    if (is_int($value)) {
      $raw = (string) $value;
    }
    elseif (is_string($value)) {
      $raw = $value;
    }
    else {
      throw new ExpenseRefusal('invalid');
    }
    if (!preg_match('/^\d{1,15}(\.\d{1,4})?$/', $raw)
      || preg_match('/^0+(\.0+)?$/', $raw)) {
      throw new ExpenseRefusal('invalid');
    }
    return $raw;
  }

  /**
   * Replays a matching receipt after current authority, or refuses drift.
   *
   * @param string $intent
   *   Intent row id.
   * @param string $hash
   *   Payload fingerprint.
   * @param \Drupal\user\UserInterface $account
   *   Fresh coordinator.
   *
   * @return array<string, mixed>|null
   *   Stored receipt, or NULL when this is a new intent.
   */
  private function replay(
    string $intent,
    string $hash,
    UserInterface $account,
  ): ?array {
    $row = $this->database->select('agency_expense_intent', 'i')
      ->fields('i')
      ->condition('id', $intent)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    if (!hash_equals($row['payload_hash'], $hash)) {
      throw new ExpenseRefusal('invalid');
    }
    $receipt = json_decode($row['receipt'], TRUE, 16, JSON_THROW_ON_ERROR);
    $storage = $this->entities->getStorage('agency_expense');
    $loaded = $storage->loadByProperties(['uuid' => $receipt['expense_id']]);
    $expense = $loaded ? reset($loaded) : NULL;
    if (!$expense instanceof Expense
      || (int) $expense->get('owner_uid')->value !== (int) $account->id()) {
      throw new ExpenseRefusal('not_found');
    }
    return $receipt;
  }

  /**
   * Writes a new drafted expense.
   *
   * @param array{amount: string, assignment_uuid?: string} $semantic
   *   Canonical write input.
   * @param \Drupal\user\UserInterface $account
   *   Fresh coordinator.
   *
   * @return \Drupal\agency_expense\Entity\Expense
   *   The saved record.
   */
  private function write(array $semantic, UserInterface $account): Expense {
    $storage = $this->entities->getStorage('agency_expense');
    $expense = $storage->create([
      'amount' => $semantic['amount'],
      'assignment_uuid' => $semantic['assignment_uuid'] ?? '',
      'status' => 'drafted',
      'owner_uid' => $account->id(),
    ]);
    $expense->save();
    if (!$expense instanceof Expense) {
      throw new ExpenseRefusal('failed');
    }
    return $expense;
  }

  /**
   * Hashes the semantic payload for replay comparison.
   *
   * @param array<string, string> $semantic
   *   Canonical write input.
   *
   * @return string
   *   Hex SHA-256 digest.
   */
  private function fingerprint(array $semantic): string {
    ksort($semantic);
    return hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR));
  }

}
