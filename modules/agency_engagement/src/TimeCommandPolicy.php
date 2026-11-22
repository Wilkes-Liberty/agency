<?php

declare(strict_types=1);

namespace Drupal\agency_engagement;

/**
 * Storage-free private-time command boundaries.
 *
 * Host Drupal commands, revisions and outbox stay on the adapter.
 * Visitor or machine mixing cannot inherit staff authority. A client
 * profile reference does not expand buyer identity.
 */
final class TimeCommandPolicy {

  /**
   * Private note and rate keys that commands must not echo.
   */
  public const PRIVATE_KEYS = [
    'note',
    'hourly_rate_minor',
    'rate_reference',
  ];

  /**
   * Read or write of assigned private time.
   *
   * @param array<string, mixed> $actor
   *   Host adapter facts for the caller.
   *
   * @return \Drupal\agency_engagement\EngagementDecision
   *   Provisioned for assigned staff; otherwise not_found.
   */
  public function privateAccess(array $actor): EngagementDecision {
    if (($actor['permission'] ?? FALSE) !== TRUE
      || ($actor['assigned'] ?? FALSE) !== TRUE
      || ($actor['blocked'] ?? FALSE) === TRUE
      || ($actor['visitor_role'] ?? FALSE) === TRUE
      || ($actor['machine_role'] ?? FALSE) === TRUE) {
      return EngagementDecision::notFound();
    }
    if (($actor['reviewer_only'] ?? FALSE) === TRUE
      && ($actor['contributor'] ?? FALSE) !== TRUE) {
      return EngagementDecision::notFound();
    }
    return EngagementDecision::provisioned();
  }

  /**
   * Engagement create with a client profile reference.
   *
   * @param array<string, mixed> $attempt
   *   Host adapter facts for the profile link.
   *
   * @return \Drupal\agency_engagement\EngagementDecision
   *   Provisioned when the profile belongs to the named client.
   */
  public function clientReference(array $attempt): EngagementDecision {
    if (($attempt['may_view_profile'] ?? FALSE) !== TRUE) {
      return EngagementDecision::reviewRefused('access_denied');
    }
    $client_uid = $attempt['client_uid'] ?? NULL;
    $profile_uid = $attempt['profile_uid'] ?? NULL;
    if (!is_int($client_uid) || !is_int($profile_uid)
      || $client_uid < 1 || $profile_uid < 1
      || $client_uid !== $profile_uid) {
      throw new \InvalidArgumentException('invalid');
    }
    return EngagementDecision::provisioned();
  }

  /**
   * Assignment conflict: stale revision cannot reactivate.
   *
   * @param int $submitted_revision
   *   Revision the caller presented.
   * @param int $current_revision
   *   Current stored revision.
   * @param bool $active
   *   Whether the assignment is currently active.
   *
   * @return \Drupal\agency_engagement\EngagementDecision
   *   Provisioned only for the current active revision.
   */
  public function assignmentConflict(
    int $submitted_revision,
    int $current_revision,
    bool $active,
  ): EngagementDecision {
    if ($submitted_revision !== $current_revision || !$active) {
      return EngagementDecision::reviewRefused('conflict');
    }
    return EngagementDecision::provisioned();
  }

}
