<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * Storage-free intake receive. Entity types stay unregistered.
 *
 * Constructed in tests. The default container has no command service.
 */
final class IntakeCommands {

  /**
   * Commercial and Discovery identifiers this receive must not accept.
   */
  private const REFUSED_KEYS = [
    'opportunity_id',
    'activity_id',
    'relationship_id',
    'invitation_id',
  ];

  /**
   * Receives one intake submission without commercial authority.
   *
   * @param \Drupal\agency_intake\IntakePrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $input
   *   Command input. Commercial identifiers are refused.
   *
   * @return array{outcome: string, commercial: string}
   *   Received outcome with commercial authority absent.
   *
   * @throws \Drupal\agency_intake\Exception\IntakeRefusal
   *   Missing tenant claim or a commercial identifier.
   */
  public function submit(
    IntakePrincipal $principal,
    array $input = [],
  ): array {
    $principal->requireTenant();
    foreach (self::REFUSED_KEYS as $key) {
      if (isset($input[$key])) {
        throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
      }
    }
    return [
      'outcome' => 'received',
      'commercial' => IntakeRefusal::ROLE_ABSENT,
    ];
  }

}
