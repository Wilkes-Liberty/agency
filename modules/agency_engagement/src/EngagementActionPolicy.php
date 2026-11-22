<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free Slice 4 action policy (deny, replay, timeout).
 *
 * Does not persist, settle, or register record types. Tenant comes
 * from the principal. Discovery, workforce, CRM and payment stay
 * absent on a successful in-scope action.
 */
final class EngagementActionPolicy {

  /**
   * Other-domain authority that engagement writes must not mint.
   */
  public const DISCOVERY_CRM_ROLES = [
    'discovery_relationship',
    'roster_membership',
    'crm_contact',
    'crm_opportunity',
  ];

  /**
   * Engagement records a Discovery, workforce or CRM write must not mint.
   */
  public const ENGAGEMENT_RECORDS = [
    'engagement',
    'assignment',
    'time_entry',
  ];

  /**
   * Staff or extra visitor roles a membership grant must not mint.
   */
  public const STAFF_ROLES = [
    'employee',
    'contractor',
    'content_editor',
  ];

  /**
   * Membership grant must not create these objects.
   */
  public const MEMBERSHIP_CREATES = [
    'user_create',
    'profile_create',
    'engagement_create',
    'discovery_relationship',
  ];

  public const PAYMENT_ENGINE = 'payment_engine';

  public const VISITOR_ROLES = ['client', 'partner'];

  /**
   * Consume-once ledger for submission and statement keys.
   */
  private ConsumeOnceLedger $ledger;

  public function __construct(?ConsumeOnceLedger $ledger = NULL) {
    $this->ledger = $ledger ?? new ConsumeOnceLedger();
  }

  /**
   * Cross-tenant or missing-object identity read.
   */
  public function readObject(
    EngagementPrincipal $principal,
    string $object_tenant,
    string $object_id,
  ): EngagementDecision {
    if ($object_id === '') {
      return EngagementDecision::notFound();
    }
    return $this->requireTenant($principal, $object_tenant)
      ?? EngagementDecision::provisioned();
  }

  /**
   * Person-record identity read of another principal's engagement object.
   */
  public function readPersonRecord(
    EngagementPrincipal $principal,
    string $object_tenant,
    string $subject_id,
  ): EngagementDecision {
    $denied = $this->requireTenant($principal, $object_tenant);
    if ($denied !== NULL) {
      return $denied;
    }
    if ($subject_id !== $principal->id) {
      return EngagementDecision::notFound();
    }
    return EngagementDecision::provisioned();
  }

  /**
   * Offer assignment. Provisioned; Discovery/CRM/workforce stay absent.
   */
  public function offerAssignment(
    EngagementPrincipal $principal,
    string $object_tenant,
  ): EngagementDecision {
    return $this->requireTenant($principal, $object_tenant)
      ?? EngagementDecision::provisioned(self::DISCOVERY_CRM_ROLES);
  }

  /**
   * Accept assignment. Same wall as offer.
   */
  public function acceptAssignment(
    EngagementPrincipal $principal,
    string $object_tenant,
  ): EngagementDecision {
    return $this->requireTenant($principal, $object_tenant)
      ?? EngagementDecision::provisioned(self::DISCOVERY_CRM_ROLES);
  }

  /**
   * Submit time. Foreign entries keep the host time-door string.
   *
   * @param \Drupal\agency_engagement\EngagementPrincipal $principal
   *   Caller whose tenant and id bound the submission.
   * @param string $object_tenant
   *   Tenant that owns the time object.
   * @param list<string> $entry_owner_ids
   *   Owner ids of entries in the submission.
   */
  public function submitTime(
    EngagementPrincipal $principal,
    string $object_tenant,
    array $entry_owner_ids,
  ): EngagementDecision {
    $denied = $this->requireTenant($principal, $object_tenant);
    if ($denied !== NULL) {
      return $denied;
    }
    foreach ($entry_owner_ids as $owner_id) {
      if ($owner_id !== $principal->id) {
        return EngagementDecision::timeDoor();
      }
    }
    return EngagementDecision::provisioned(self::DISCOVERY_CRM_ROLES);
  }

  /**
   * Accept time submission. Admin flag is not a bypass. Self-approval refused.
   */
  public function acceptTimeSubmission(
    EngagementPrincipal $principal,
    string $object_tenant,
    bool $self_approval,
    bool $has_accepted_assignment,
    bool $administrator_flag,
  ): EngagementDecision {
    $denied = $this->requireTenant($principal, $object_tenant);
    if ($denied !== NULL) {
      return $denied;
    }
    if ($self_approval) {
      return EngagementDecision::reviewRefused(ReviewOutcome::SELF_APPROVAL);
    }
    if ($administrator_flag && !$has_accepted_assignment) {
      return EngagementDecision::reviewRefused(
        ReviewOutcome::MISSING_ASSIGNMENT,
      );
    }
    if (!$has_accepted_assignment) {
      return EngagementDecision::reviewRefused(
        ReviewOutcome::MISSING_ASSIGNMENT,
      );
    }
    return EngagementDecision::provisioned([self::PAYMENT_ENGINE]);
  }

  /**
   * Issue statement. Provisioned; payment engine stays uncalled.
   */
  public function issueStatement(
    EngagementPrincipal $principal,
    string $object_tenant,
  ): EngagementDecision {
    return $this->requireTenant($principal, $object_tenant)
      ?? EngagementDecision::provisioned([
        self::PAYMENT_ENGINE,
        ...self::DISCOVERY_CRM_ROLES,
      ]);
  }

  /**
   * Discovery, workforce or CRM write. Engagement records stay absent.
   */
  public function provisionFromDiscoveryOrCrm(
    EngagementPrincipal $principal,
  ): EngagementDecision {
    return $this->requireTenant($principal, $principal->tenant)
      ?? EngagementDecision::provisioned(self::ENGAGEMENT_RECORDS);
  }

  /**
   * Client-membership grant. Visitor roles only; no staff objects.
   *
   * @param \Drupal\agency_engagement\EngagementPrincipal $principal
   *   Caller whose tenant bounds the grant.
   * @param string $object_tenant
   *   Tenant that owns the membership object.
   * @param list<string> $requested_roles
   *   Roles the caller asked to mint.
   */
  public function grantMembership(
    EngagementPrincipal $principal,
    string $object_tenant,
    array $requested_roles,
  ): EngagementDecision {
    $denied = $this->requireTenant($principal, $object_tenant);
    if ($denied !== NULL) {
      return $denied;
    }
    foreach ($requested_roles as $role) {
      if (!in_array($role, self::VISITOR_ROLES, TRUE)) {
        return EngagementDecision::provisioned([
          ...self::STAFF_ROLES,
          ...self::MEMBERSHIP_CREATES,
        ]);
      }
    }
    return EngagementDecision::provisioned([
      ...self::STAFF_ROLES,
      ...self::MEMBERSHIP_CREATES,
    ]);
  }

  /**
   * Consume-once replay of a submission or statement key.
   */
  public function consume(
    string $key,
    string $digest,
    int $now,
    int $expires_at,
  ): EngagementDecision {
    return $this->ledger->consume($key, $digest, $now, $expires_at);
  }

  /**
   * Holds a key that has not yet committed a financial claim.
   */
  public function holdConsumeKey(
    string $key,
    string $digest,
    int $expires_at,
  ): void {
    $this->ledger->hold($key, $digest, $expires_at);
  }

  /**
   * Agency row reads while authority is not agency. Operator-visible.
   */
  public function readAgencyRowsAt(string $authority): EngagementDecision {
    if ($authority !== 'agency') {
      return EngagementDecision::unmapped();
    }
    return EngagementDecision::unmapped();
  }

  /**
   * Hatch missing-tenant, then foreign-tenant object deny.
   */
  private function requireTenant(
    EngagementPrincipal $principal,
    string $object_tenant,
  ): ?EngagementDecision {
    if ($principal->hatch || $principal->tenant === '') {
      return EngagementDecision::missingTenant([
        [
          'operation' => 'denied_access',
          'metadata' => ['reason' => EngagementDecision::REASON_MISSING_TENANT],
        ],
      ]);
    }
    if ($object_tenant !== $principal->tenant) {
      return EngagementDecision::notFound();
    }
    return NULL;
  }

}
