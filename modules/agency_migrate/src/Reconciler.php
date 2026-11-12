<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Compares legacy and Agency through shadow reads.
 *
 * Reports counts, per-field digests, reference integrity and access-decision
 * parity for a sample. Historical Audit Chain rows are never rewritten.
 */
final class Reconciler {

  /**
   * Identity fields excluded from content drift.
   */
  private const IDENTITY_FIELDS = [
    'id',
    'uuid',
    'revision_id',
    'langcode',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'created',
    'changed',
    'revision_uid',
    'revision_timestamp',
    'revision_log',
    'revision_user',
    'revision_created',
    'revision_log_message',
  ];

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly LineageStore $lineage,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Compares one copier's source and target for a sample set.
   *
   * @param string $plugin_id
   *   Copier plugin id.
   * @param list<int> $sample_ids
   *   Source integer ids to inspect. Empty uses every source id in lineage.
   * @param list<string> $principals
   *   Opaque principal tokens used only for access-decision parity.
   */
  public function compare(string $plugin_id, array $sample_ids = [], array $principals = []): ReconciliationReport {
    if (!$this->currentUser->hasPermission('run agency migrate')) {
      throw new MigrateRefusal('forbidden');
    }
    if (!$this->copiers->hasDefinition($plugin_id)) {
      throw new MigrateRefusal('plugin_missing');
    }
    $copier = $this->copiers->createInstance($plugin_id);
    $source_count = $copier->countSource();
    $target_count = $copier->countTarget();
    $matched = $this->lineage->countPair($copier->sourceEntityType(), $copier->targetEntityType());
    $ids = $sample_ids !== [] ? $sample_ids : $this->allSourceIds($copier);
    $exceptions = [];
    $digests = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      $source = $copier->loadSource($id);
      $target = $copier->loadTarget($id);
      if ($source === NULL) {
        $exceptions[] = [
          'type' => 'missing_source',
          'id' => $id,
        ];
        continue;
      }
      if ($target === NULL) {
        $exceptions[] = [
          'type' => 'missing_on_target',
          'id' => $id,
        ];
        continue;
      }
      $source_map = $copier->canonicalize($source);
      $target_map = $copier->canonicalize($target);
      $digests[$id] = [];
      foreach ($source_map as $field => $encoded) {
        $target_encoded = $target_map[$field] ?? '';
        $digests[$id][$field] = [
          'source' => hash('sha256', $encoded),
          'target' => hash('sha256', $target_encoded),
        ];
        if (in_array($field, self::IDENTITY_FIELDS, TRUE)) {
          continue;
        }
        if ($encoded !== $target_encoded) {
          $exceptions[] = [
            'type' => 'field_drift',
            'id' => $id,
            'field' => $field,
            'source_digest' => hash('sha256', $encoded),
            'target_digest' => hash('sha256', $target_encoded),
          ];
        }
      }
      $exceptions = [
        ...$exceptions,
        ...$this->referenceExceptions($copier, $source, 'source'),
        ...$this->referenceExceptions($copier, $target, 'target'),
      ];
      foreach ($principals as $principal) {
        $source_allow = $copier->accessDecision($principal, 'source', $id);
        $target_allow = $copier->accessDecision($principal, 'target', $id);
        if ($source_allow !== $target_allow) {
          $exceptions[] = [
            'type' => 'access_mismatch',
            'id' => $id,
            'principal' => $principal,
            'source' => $source_allow,
            'target' => $target_allow,
          ];
        }
      }
    }
    return new ReconciliationReport(
      [
        'source' => $source_count,
        'target' => $target_count,
        'matched' => $matched,
        'sampled' => count($ids),
      ],
      $digests,
      $exceptions,
    );
  }

  /**
   * Broken integer references on one side of a record.
   *
   * @return list<array<string, mixed>>
   *   Exception records.
   */
  private function referenceExceptions(CopierInterface $copier, ContentEntityInterface $entity, string $side): array {
    $exceptions = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      foreach ($copier->referencePropertyNames() as $field) {
        if (!$translation->hasField($field)) {
          throw new MigrateRefusal('incompatible_field_layout');
        }
        foreach ($translation->get($field) as $delta => $item) {
          $main = $item->mainPropertyName();
          if ($main === NULL) {
            throw new MigrateRefusal('incompatible_field_layout');
          }
          $referenced = (int) $item->get($main)->getValue();
          if ($referenced <= 0) {
            continue;
          }
          $loaded = $side === 'source'
            ? $copier->loadSource($referenced)
            : $copier->loadTarget($referenced);
          if ($loaded instanceof ContentEntityInterface) {
            continue;
          }
          $exceptions[] = [
            'type' => 'broken_reference',
            'side' => $side,
            'id' => (int) $entity->id(),
            'field' => $field,
            'langcode' => $langcode,
            'delta' => $delta,
            'referenced_id' => $referenced,
          ];
        }
      }
    }
    return $exceptions;
  }

  /**
   * Source ids from a full scan when the caller omits a sample.
   *
   * @return list<int>
   *   Integer ids.
   */
  private function allSourceIds(CopierInterface $copier): array {
    $ids = [];
    $cursor = 0;
    while (TRUE) {
      $batch = $copier->loadSourceBatch($cursor, 100);
      if ($batch === []) {
        break;
      }
      foreach ($batch as $entity) {
        $cursor = (int) $entity->id();
        $ids[] = $cursor;
      }
    }
    return $ids;
  }

}
