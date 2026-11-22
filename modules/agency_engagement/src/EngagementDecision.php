<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free result of one engagement, time or membership action.
 *
 * Identity denials use the Discovery body. Hatch missing-tenant stays a
 * distinct reason. Time-door refusals keep the host `{error: denied}`
 * string and do not invent a time-specific not_found variant.
 */
final class EngagementDecision {

  public const OUTCOME_REFUSED = 'refused';
  public const OUTCOME_PROVISIONED = 'provisioned';
  public const REASON_NOT_FOUND = 'not_found';
  public const REASON_MISSING_TENANT = 'missing_tenant_claim';
  public const REASON_UNMAPPED = 'unmapped_property';

  /**
   * Host contributor/reviewer time-door string (vault D9).
   */
  public const TIME_DOOR = ['error' => 'denied'];

  /**
   * Constructs one decision.
   *
   * @param string $outcome
   *   Caller-facing outcome.
   * @param string|null $reason
   *   Refusal reason, or NULL when the action is provisioned.
   * @param array<string, mixed> $payload
   *   Extra body. Empty on identity denials.
   * @param list<string> $absentRoles
   *   Other-domain roles or records that must stay absent.
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   * @param array<string, string> $timeDoor
   *   Host time-door body when a foreign submit is refused.
   */
  public function __construct(
    public readonly string $outcome,
    public readonly ?string $reason,
    public readonly array $payload = [],
    public readonly array $absentRoles = [],
    public readonly array $evidence = [],
    public readonly array $timeDoor = [],
  ) {}

  /**
   * Discovery identity-deny body.
   *
   * @return array{outcome: string, reason?: string}
   *   Outcome, plus reason when set.
   */
  public function body(): array {
    $body = ['outcome' => $this->outcome];
    if ($this->reason !== NULL) {
      $body['reason'] = $this->reason;
    }
    return $body;
  }

  /**
   * Cross-tenant or missing-object identity deny.
   *
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   */
  public static function notFound(array $evidence = []): self {
    return new self(
      self::OUTCOME_REFUSED,
      self::REASON_NOT_FOUND,
      [],
      [],
      $evidence,
    );
  }

  /**
   * Hatch or token with no tenant. Not renamed to not_found.
   *
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   */
  public static function missingTenant(array $evidence = []): self {
    return new self(
      self::OUTCOME_REFUSED,
      self::REASON_MISSING_TENANT,
      [],
      [],
      $evidence,
    );
  }

  /**
   * Foreign-entry submit. Host time-door string, not a new not_found.
   *
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   */
  public static function timeDoor(array $evidence = []): self {
    return new self(
      self::OUTCOME_REFUSED,
      NULL,
      [],
      [],
      $evidence,
      self::TIME_DOOR,
    );
  }

  /**
   * In-scope action succeeded; named other-domain roles stay absent.
   *
   * @param list<string> $absent_roles
   *   Roles or records the action must not mint.
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   */
  public static function provisioned(
    array $absent_roles = [],
    array $evidence = [],
  ): self {
    return new self(
      self::OUTCOME_PROVISIONED,
      NULL,
      [],
      $absent_roles,
      $evidence,
    );
  }

  /**
   * Membership reconcile succeeded with visitor roles only.
   *
   * @param list<string> $roles
   *   Visitor roles the attempt would add or keep.
   * @param list<string> $absent_roles
   *   Staff roles and created objects that stay absent.
   */
  public static function granted(array $roles, array $absent_roles = []): self {
    return new self(
      self::OUTCOME_PROVISIONED,
      NULL,
      ['roles' => $roles],
      $absent_roles,
    );
  }

  /**
   * Operator-visible migrate or authority refuse. Not not_found.
   *
   * @param list<array<string, mixed>> $evidence
   *   Minimized evidence rows (operation plus metadata).
   */
  public static function unmapped(array $evidence = []): self {
    return new self(
      self::OUTCOME_REFUSED,
      self::REASON_UNMAPPED,
      [],
      [],
      $evidence,
    );
  }

  /**
   * Review-path refuse (self-approval, missing assignment, admin flag).
   */
  public static function reviewRefused(string $reason): self {
    return new self(self::OUTCOME_REFUSED, $reason);
  }

}
