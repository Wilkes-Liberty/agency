<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * In-memory contact-route directory for tests. Not a container service.
 */
final class MemoryContactDirectory implements ContactDirectoryInterface {

  /**
   * Remembered allow/deny answers, keyed by kind and value.
   *
   * Multiple answers for the same route are ambiguous.
   *
   * @var array<string, list<bool>>
   */
  private array $entries = [];

  /**
   * Remembers one allow or deny answer for a contact route.
   *
   * @param string $kind
   *   Contact route, not a person or organization discriminator.
   * @param string $value
   *   Opaque contact value for that route.
   * @param bool $allowed
   *   TRUE allow, FALSE deny.
   */
  public function remember(string $kind, string $value, bool $allowed): void {
    $this->entries[$this->key($kind, $value)][] = $allowed;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed(string $kind, string $value): ?bool {
    $hits = $this->entries[$this->key($kind, $value)] ?? [];
    if ($hits === [] || count($hits) > 1) {
      return NULL;
    }
    return $hits[0];
  }

  /**
   * Builds a directory key that cannot collide across kinds.
   *
   * @param string $kind
   *   Contact route.
   * @param string $value
   *   Opaque contact value.
   *
   * @return string
   *   Stable map key.
   */
  private function key(string $kind, string $value): string {
    return $kind . "\0" . $value;
  }

}
