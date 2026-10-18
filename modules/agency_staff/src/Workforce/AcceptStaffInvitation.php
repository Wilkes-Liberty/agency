<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Input for acceptStaffInvitation.
 *
 * POST is enforced by the command via the request stack, matching
 * InvitationCommands::accept. This object carries the bearer only.
 */
final class AcceptStaffInvitation {

  /**
   * Hexadecimal bearer presented by the intended principal.
   */
  public readonly string $token;

  public function __construct(string $token) {
    $this->token = WorkforceInput::token($token);
  }

  /**
   * Validates the invitation bearer without a request method field.
   */
  public static function fromArray(array $input): self {
    WorkforceInput::onlyKeys($input, ['token']);
    return new self(WorkforceInput::token($input['token'] ?? NULL));
  }

}
