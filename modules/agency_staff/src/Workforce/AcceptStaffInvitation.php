<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Input for acceptStaffInvitation. POST-only; GET cannot consume a token.
 */
final class AcceptStaffInvitation {

  /**
   * Hexadecimal bearer presented by the intended principal.
   */
  public readonly string $token;

  /**
   * Normalized POST method after validation.
   */
  public readonly string $method;

  public function __construct(string $token, string $method) {
    $this->token = $token;
    $this->method = $method;
  }

  /**
   * Validates acceptance input, including the request method.
   */
  public static function fromArray(array $input): self {
    return new self(
      WorkforceInput::token($input['token'] ?? NULL),
      WorkforceInput::postMethod($input['method'] ?? NULL),
    );
  }

}
