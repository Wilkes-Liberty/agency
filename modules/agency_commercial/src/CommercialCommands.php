<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Storage-free commercial writes. Entity types stay unregistered.
 *
 * Constructed in tests. The default container has no command service.
 */
final class CommercialCommands {

  /**
   * Discovery identifiers that a commercial write must not accept.
   */
  private const REFUSED_KEYS = [
    'relationship_id',
    'invitation_id',
    'entitlement_id',
    'workspace_id',
  ];

  /**
   * Constructs commercial commands over a tenant-scoped locator.
   *
   * @param \Drupal\agency_commercial\CommercialRecordLocatorInterface $locator
   *   Memory or host locator. Default container has no command service.
   */
  public function __construct(
    private readonly CommercialRecordLocatorInterface $locator,
  ) {}

  /**
   * Opens an opportunity without granting Discovery authority.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $input
   *   Command input. Discovery identifiers are refused.
   *
   * @return array{outcome: string, discovery: string}
   *   Provisioned outcome with Discovery authority absent.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim or a Discovery identifier.
   */
  public function createOpportunity(
    CommercialPrincipal $principal,
    array $input = [],
  ): array {
    $principal->requireTenant();
    $this->refuseForeignKeys($input);
    return [
      'outcome' => 'provisioned',
      'discovery' => CommercialRefusal::ROLE_ABSENT,
    ];
  }

  /**
   * Records an activity without granting Discovery authority.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param array<string, mixed> $input
   *   Command input. Discovery identifiers are refused.
   *
   * @return array{outcome: string, discovery: string}
   *   Provisioned outcome with Discovery authority absent.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim or a Discovery identifier.
   */
  public function recordActivity(
    CommercialPrincipal $principal,
    array $input = [],
  ): array {
    $principal->requireTenant();
    $this->refuseForeignKeys($input);
    return [
      'outcome' => 'provisioned',
      'discovery' => CommercialRefusal::ROLE_ABSENT,
    ];
  }

  /**
   * Loads one commercial record for the hatch tenant.
   *
   * Missing and foreign tenants share not_found. The body never includes
   * tenant_id.
   *
   * @param \Drupal\agency_commercial\CommercialPrincipal $principal
   *   Caller whose tenant comes from the hatch.
   * @param string $id
   *   Opaque commercial record identifier.
   *
   * @return array{outcome: string, id: string}
   *   Found outcome without tenant scope.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   Missing tenant claim, missing id, or foreign tenant.
   */
  public function loadRecord(
    CommercialPrincipal $principal,
    string $id,
  ): array {
    $tenant = $principal->requireTenant();
    $record = $this->locator->locate($id);
    if ($record === NULL || $record['tenant_id'] !== $tenant) {
      throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
    }
    return [
      'outcome' => 'found',
      'id' => $id,
    ];
  }

  /**
   * Refuses Discovery identifiers on a commercial write.
   *
   * @param array<string, mixed> $input
   *   Command input.
   *
   * @throws \Drupal\agency_commercial\Exception\CommercialRefusal
   *   When a Discovery identifier key is present.
   */
  private function refuseForeignKeys(array $input): void {
    foreach (self::REFUSED_KEYS as $key) {
      if (isset($input[$key])) {
        throw new CommercialRefusal(CommercialRefusal::NOT_FOUND);
      }
    }
  }

}
