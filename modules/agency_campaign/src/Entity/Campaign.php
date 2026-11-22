<?php

declare(strict_types=1);

namespace Drupal\agency_campaign\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a marketing campaign with no direct entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_campaign",
 *   label = @Translation("Campaign"),
 *   handlers = {
 *     "access" = "Drupal\agency_campaign\Access\DenyAccess",
 *     "storage" = "Drupal\agency_campaign\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_campaign",
 *   revision_table = "agency_campaign_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_campaign',
  label: new TranslatableMarkup('Campaign'),
  handlers: [
    'access' => 'Drupal\\agency_campaign\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_campaign\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_campaign',
  revision_table: 'agency_campaign_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Campaign extends CampaignRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'title' => 'string',
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
    'title' => 'internal',
    'status' => 'internal',
  ];

}
