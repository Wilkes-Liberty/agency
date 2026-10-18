<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Slice 2 workforce writes. Expand implements this after G1.
 *
 * Implementations must not call Discovery commands or write Discovery
 * invitation or entitlement rows.
 */
interface WorkforceCommandsInterface {

  /**
   * Captures a staff invitation without sending mail or minting a uid.
   */
  public function inviteStaff(InviteStaff $input, IdempotencyKey $key): array;

  /**
   * Consumes one staff invitation for the current intended principal.
   */
  public function acceptStaffInvitation(AcceptStaffInvitation $input, IdempotencyKey $key): array;

  /**
   * Revokes staff generations and workforce entitlement only.
   */
  public function revokeStaffInvitation(RevokeStaffInvitation $input, IdempotencyKey $key): array;

  /**
   * Writes marked profile evidence and File Gate references.
   */
  public function updateProfileEvidence(UpdateProfileEvidence $input, IdempotencyKey $key): array;

}
