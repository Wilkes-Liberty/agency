<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an opportunity with no direct entity access.
 *
 * Amount is stored when supplied and is never given a default.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_opportunity",
 *   label = @Translation("Opportunity"),
 *   handlers = {
 *     "access" = "Drupal\agency_commercial\Access\DenyAccess",
 *     "storage" = "Drupal\agency_commercial\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_opportunity",
 *   revision_table = "agency_opportunity_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_opportunity',
  label: new TranslatableMarkup('Opportunity'),
  handlers: [
    'access' => 'Drupal\\agency_commercial\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_commercial\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_opportunity',
  revision_table: 'agency_opportunity_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Opportunity extends CommercialRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'organization_uuid' => 'uuid_string',
    'title' => 'string',
    'stage' => 'string',
    'amount' => 'decimal',
    'status' => 'string',
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
    'organization_uuid' => 'internal',
    'title' => 'internal',
    'stage' => 'internal',
    'amount' => 'financial',
    'status' => 'internal',
  ];

}
