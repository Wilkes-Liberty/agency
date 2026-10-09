<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an internal Discovery record with no direct entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_discovery",
 *   label = @Translation("Discovery relationship"),
 *   handlers = {
 *     "access" = "Drupal\agency_staff\Access\InternalAccess",
 *     "storage_schema" = "Drupal\agency_staff\Storage\InternalSchema"
 *   },
 *   base_table = "agency_discovery",
 *   revision_table = "agency_discovery_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_discovery',
  label: new TranslatableMarkup('Discovery relationship'),
  handlers: [
    'access' => 'Drupal\\agency_staff\\Access\\InternalAccess',
    'storage_schema' => 'Drupal\\agency_staff\\Storage\\InternalSchema',
  ],
  base_table: 'agency_discovery',
  revision_table: 'agency_discovery_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class DiscoveryRelationship extends InternalRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'person_uuid' => 'string',
    'principal_uuid' => 'string',
    'owner_uid' => 'integer',
    'state' => 'string',
    'waiting_on' => 'string',
    'version' => 'integer',
    'details' => 'string_long',
  ];

}
