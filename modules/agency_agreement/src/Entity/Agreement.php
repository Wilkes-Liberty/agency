<?php

declare(strict_types=1);

namespace Drupal\agency_agreement\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an agreement with no direct entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_agreement",
 *   label = @Translation("Agreement"),
 *   handlers = {
 *     "access" = "Drupal\agency_agreement\Access\DenyAccess",
 *     "storage" = "Drupal\agency_agreement\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_agreement",
 *   revision_table = "agency_agreement_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_agreement',
  label: new TranslatableMarkup('Agreement'),
  handlers: [
    'access' => 'Drupal\\agency_agreement\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_agreement\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_agreement',
  revision_table: 'agency_agreement_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Agreement extends AgreementRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'engagement_uuid' => 'uuid_string',
    'status' => 'string',
    'terms' => 'string_long',
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
    'engagement_uuid' => 'internal',
    'status' => 'internal',
    'terms' => 'confidential',
  ];

}
