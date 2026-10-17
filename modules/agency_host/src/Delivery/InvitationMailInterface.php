<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

/**
 * Separates exact envelope preparation from a single transport attempt.
 */
interface InvitationMailInterface {

  /**
   * Runs normal formatting and suppression, without calling a transport.
   */
  public function prepare(array $content): array;

  /**
   * Returns captured, accepted or unknown; never claims recipient delivery.
   */
  public function send(array $envelope): string;

}
