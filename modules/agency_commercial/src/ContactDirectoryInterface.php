<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * Allow, deny or omit a contact route. Never returns an entity.
 *
 * Kind is a contact route, not a person or organization discriminator.
 */
interface ContactDirectoryInterface {

  /**
   * Whether the typed contact route may be reached.
   *
   * @param string $kind
   *   Contact route, such as a mailbox kind.
   * @param string $value
   *   Opaque contact value for that route.
   *
   * @return bool|null
   *   TRUE allow, FALSE deny, NULL missing or ambiguous.
   */
  public function isAllowed(string $kind, string $value): ?bool;

}
