<?php

declare(strict_types=1);

namespace Drupal\agency_commercial;

/**
 * In-memory locator for tests. Not a container service.
 */
final class MemoryCommercialRecordLocator implements
  CommercialRecordLocatorInterface {

  /**
   * Remembered tenant scope, keyed by record id.
   *
   * @var array<string, array{tenant_id: string}>
   */
  private array $records = [];

  /**
   * Remembers one record's tenant scope without storing a payload.
   *
   * @param string $id
   *   Opaque commercial record identifier.
   * @param string $tenant_id
   *   Owning tenant claim.
   */
  public function remember(string $id, string $tenant_id): void {
    $this->records[$id] = ['tenant_id' => $tenant_id];
  }

  /**
   * {@inheritdoc}
   */
  public function locate(string $id): ?array {
    return $this->records[$id] ?? NULL;
  }

}
