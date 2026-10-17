<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Workforce;

/**
 * Host port for authority, account minting and invitation delivery.
 *
 * The installing host implements this. The package supplies no adapter
 * and does not register a service for it in this change.
 */
interface WorkforceHostAdapterInterface {

  /**
   * Returns the operator-approved workforce authority state.
   */
  public function authority(): AuthorityState;

  /**
   * Mints the host uid, fail-closed role and profile bundle after accept.
   *
   * Agency never mints the account. Call only after the workforce
   * relationship commit. Do not grant Discovery privileges.
   */
  public function provisionAcceptedStaff(StaffClassification $classification, string $personUuid): HostAccountBinding;

  /**
   * Hands a captured invitation to host delivery. Capture is not sending.
   */
  public function deliverCapturedInvitation(string $invitationUuid): void;

}
