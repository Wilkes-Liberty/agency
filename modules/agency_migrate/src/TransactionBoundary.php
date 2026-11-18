<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerBase;

/**
 * Owns compatible commit and rollback boundaries on supported core versions.
 */
final class TransactionBoundary {

  /**
   * Completes the transaction in the caller's try/catch, including Drupal 10.
   *
   * The caller owns the only reference. Drupal 10 commits when that reference
   * is released; newer cores provide the explicit commit/savepoint method.
   *
   * @see https://www.drupal.org/node/3512006
   */
  public static function commit(?Transaction &$transaction): void {
    if ($transaction === NULL) {
      throw new \LogicException('A transaction is required.');
    }
    if (version_compare(\Drupal::VERSION, '11.3.0', '>=')) {
      $transaction->commitOrRelease();
    }
    else {
      $transaction = NULL;
    }
  }

  /**
   * Reads the managed scopes by ID and in transaction order.
   *
   * Core exposes scope names but keeps the actual stack accessor protected.
   * A name can be reused, so retain the IDs from the supported core manager.
   *
   * @return array<string, \Drupal\Core\Database\Transaction\StackItem>
   *   The root followed by any active savepoints.
   */
  public static function stack(TransactionManagerBase $manager): array {
    return (new \ReflectionMethod(TransactionManagerBase::class, 'stack'))->invoke($manager);
  }

  /**
   * Checks whether the original scope still belongs to the active stack.
   *
   * @param list<string> $expected
   *   The original stack through the owned scope.
   * @param list<string> $active
   *   Current managed stack IDs.
   */
  public static function containsScope(array $expected, array $active): bool {
    return $expected !== [] && array_slice($active, 0, count($expected)) === $expected;
  }

  /**
   * Rolls back an owned boundary after unwinding retained descendants.
   *
   * @param \Drupal\Core\Database\Transaction\TransactionManagerBase $manager
   *   The still-active original manager.
   * @param list<string> $expected
   *   The original stack through the owned scope.
   * @param \Drupal\Core\Database\Transaction $boundary
   *   The exact transaction or savepoint owned by this operation.
   */
  public static function rollbackScope(TransactionManagerBase $manager, array $expected, Transaction $boundary): void {
    $active = self::stack($manager);
    if (!self::containsScope($expected, array_keys($active))) {
      throw new \LogicException('The original transaction scope is unavailable.');
    }
    $descendants = array_slice($active, count($expected), preserve_keys: TRUE);
    foreach (array_reverse($descendants, preserve_keys: TRUE) as $id => $item) {
      $manager->rollback($item->name, $id);
    }
    $boundary->rollBack();
  }

}
