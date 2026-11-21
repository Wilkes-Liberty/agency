<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Allow or not_found. Never returns an entity or contact payload.
 *
 * Constructed in tests. The default container stays unavailable.
 */
final class AllowDenyContactPolicy implements ContactPolicyReaderInterface {

  /**
   * Constructs an allow/deny policy over a contact-route directory.
   *
   * @param \Drupal\agency_commercial\ContactDirectoryInterface $directory
   *   Memory or host directory. Default container has no policy adapter.
   */
  public function __construct(
    private readonly ContactDirectoryInterface $directory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function mayContact(array $contact): bool {
    $kind = $contact['kind'] ?? '';
    $value = $contact['value'] ?? '';
    if (!is_string($kind) || !is_string($value) || $kind === ''
      || $value === '') {
      throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
    }
    if ($this->directory->isAllowed($kind, $value) !== TRUE) {
      throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
    }
    return TRUE;
  }

}
