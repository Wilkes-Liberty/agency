<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Copies one source entity type into one Agency entity type.
 *
 * Host adapters implement this for each legacy type. Identity, markings and
 * access decisions stay with the plugin; the runner never infers them.
 */
interface CopierInterface extends PluginInspectionInterface {

  /**
   * Allowed property markings. Unlisted values fail a run.
   */
  public const MARKINGS = [
    'public',
    'internal',
    'confidential',
    'personal',
    'financial',
  ];

  /**
   * Source entity type ID.
   */
  public function sourceEntityType(): string;

  /**
   * Target entity type ID.
   */
  public function targetEntityType(): string;

  /**
   * Maps each copied content property to a marking.
   *
   * @return array<string, string>
   *   Property name keyed to a value in ::MARKINGS.
   */
  public function propertyMarkings(): array;

  /**
   * Source content properties that are missing or have an invalid marking.
   *
   * @return list<string>
   *   Property names. Empty when every copied property is marked.
   */
  public function unmappedProperties(): array;

  /**
   * Integer reference properties checked during reconciliation.
   *
   * @return list<string>
   *   Field names whose values are entity identifiers on the same side.
   */
  public function referencePropertyNames(): array;

  /**
   * Loads a bounded source batch after a resume cursor.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   Source entities ordered by integer id.
   */
  public function loadSourceBatch(int $after_id, int $limit): array;

  /**
   * Loads one source entity by integer id.
   */
  public function loadSource(int $id): ?ContentEntityInterface;

  /**
   * Loads one target entity by integer id.
   */
  public function loadTarget(int $id): ?ContentEntityInterface;

  /**
   * Counts source records.
   */
  public function countSource(): int;

  /**
   * Counts target records.
   */
  public function countTarget(): int;

  /**
   * Whether the target id exists with a UUID other than the source UUID.
   */
  public function targetOccupiedByDifferentUuid(int $id, string $uuid): bool;

  /**
   * Inserts the target with the source integer id, UUID and revision identity.
   */
  public function copyToTarget(ContentEntityInterface $source): ContentEntityInterface;

  /**
   * Writes current Agency field values back onto the legacy record.
   */
  public function writeLegacyFromTarget(ContentEntityInterface $target): ContentEntityInterface;

  /**
   * Allow/deny for one principal against one record on one side.
   *
   * The same principal and record id must yield the same boolean on source
   * and target after a faithful copy.
   */
  public function accessDecision(string $principal, string $side, int $id): bool;

  /**
   * Canonical field map used for per-field digests.
   *
   * @return array<string, string>
   *   Sorted field names keyed to stable JSON of all translations and items.
   */
  public function canonicalize(ContentEntityInterface $entity): array;

  /**
   * SHA-256 of the canonical content map.
   */
  public function contentDigest(ContentEntityInterface $entity): string;

}
