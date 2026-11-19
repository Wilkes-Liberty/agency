<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Matches host assurances against the complete stored target field layout.
 */
final class OperationJournalQualification {

  public function __construct(private readonly OperationJournalPolicyInterface $policy) {}

  /**
   * Qualifies the candidate scope and stored properties before persistence.
   */
  public function assertEntity(string $domain, ContentEntityInterface $entity): string {
    try {
      return $this->fingerprint($domain, $entity->getEntityTypeId(), $entity->bundle(), RevisionHistorySnapshot::layout($entity));
    }
    catch (\Throwable) {
      throw new MigrateRefusal('operation_unqualified');
    }
  }

  /**
   * Qualifies every property in a structurally validated persisted snapshot.
   */
  public function assertSnapshot(string $domain, string $entityType, array $snapshot): string {
    try {
      if ($snapshot['source_type'] !== $entityType) {
        throw new \UnexpectedValueException();
      }
      $bundle = RevisionHistorySnapshot::bundle($snapshot) ?? $entityType;
      return $this->fingerprint($domain, $entityType, $bundle, $snapshot['layout']);
    }
    catch (\Throwable) {
      throw new MigrateRefusal('operation_unqualified');
    }
  }

  /**
   * Binds the canonical layout and markings to a host contract revision.
   */
  private function fingerprint(string $domain, string $entityType, string $bundle, array $layout): string {
    $declaration = $this->policy->declaration($domain, $entityType, $bundle);
    $contractId = $declaration['contract_id'] ?? NULL;
    $markings = $declaration['markings'] ?? NULL;
    if (count($declaration ?? []) !== 5
      || !is_string($contractId) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $contractId)
      || ($declaration['access_equivalent'] ?? NULL) !== TRUE
      || ($declaration['recovery_equivalent'] ?? NULL) !== TRUE
      || ($declaration['retention_equivalent'] ?? NULL) !== TRUE
      || !is_array($markings) || count($markings) !== count($layout['fields'])) {
      throw new \UnexpectedValueException();
    }
    foreach ($layout['fields'] as $name => $field) {
      $properties = $markings[$name] ?? NULL;
      // An empty static property list establishes no coverage for map keys.
      if ($field['type'] === 'map' || !is_array($properties) || count($properties) !== count($field['properties'])) {
        throw new \UnexpectedValueException();
      }
      foreach (array_keys($field['properties']) as $property) {
        if (!in_array($properties[$property] ?? NULL, CopierInterface::MARKINGS, TRUE)) {
          throw new \UnexpectedValueException();
        }
      }
      ksort($markings[$name], SORT_STRING);
    }
    ksort($markings, SORT_STRING);
    return hash('sha256', json_encode([
      'version' => 1,
      'domain' => $domain,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'contract_id' => $contractId,
      'layout' => $layout,
      'markings' => $markings,
    ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
  }

}
