<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an activity with no direct entity access.
 *
 * Storage is revisionable. Accepted vault D4 wins over the ontology draft,
 * which sets Activity.revisionable false and identifiers.revision_id false.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_activity",
 *   label = @Translation("Activity"),
 *   handlers = {
 *     "access" = "Drupal\agency_commercial\Access\DenyAccess",
 *     "storage" = "Drupal\agency_commercial\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_activity",
 *   revision_table = "agency_activity_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_activity',
  label: new TranslatableMarkup('Activity'),
  handlers: [
    'access' => 'Drupal\\agency_commercial\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_commercial\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_activity',
  revision_table: 'agency_activity_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Activity extends CommercialRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'opportunity_uuid' => 'uuid_string',
    'kind' => 'string',
    'summary' => 'string',
    'occurred_at' => 'timestamp',
  ];

  /**
   * {@inheritdoc}
   */
  public const MARKINGS = [
    'id' => 'internal',
    'uuid' => 'internal',
    'created' => 'internal',
    'changed' => 'internal',
    'revision_id' => 'internal',
    'opportunity_uuid' => 'internal',
    'kind' => 'internal',
    'summary' => 'confidential',
    'occurred_at' => 'internal',
  ];

}
