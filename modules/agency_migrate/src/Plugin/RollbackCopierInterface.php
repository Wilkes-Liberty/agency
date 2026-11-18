<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

/**
 * Opts in to rollback for an explicitly reviewed equal-layout entity pair.
 *
 * Both sides must preserve the same identifiers, field and reference semantics,
 * access decisions and storage protection. Both sides' local storage must
 * share the rollback connection.
 * The host supplies an authority fence for every writer before enabling replay.
 */
interface RollbackCopierInterface extends HistoryCopierInterface, InventoryCopierInterface {

  /**
   * Returns the reviewed opaque principal tokens for full access comparison.
   *
   * @return array<mixed>
   *   The adapter must supply a nonempty list of strings that includes a
   *   denial case. The caller validates the supplied list.
   */
  public function rollbackPrincipals(): array;

}
