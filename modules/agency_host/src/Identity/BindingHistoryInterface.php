<?php

declare(strict_types=1);

namespace Drupal\agency_host\Identity;

/**
 * Host authority for qualified binding history and sticky revocation.
 */
interface BindingHistoryInterface {

  /**
   * Returns current authority, or throws when history coverage is unavailable.
   */
  public function authorize(string $subject, callable $currentBindings): bool;

  /**
   * Observes drift after a refused proof without granting subject authority.
   */
  public function checkAuthority(): void;

  /**
   * Authmap provider machine names covered by this history.
   *
   * @return list<string>
   *   Covered authmap provider machine names.
   */
  public function providers(): array;

}
