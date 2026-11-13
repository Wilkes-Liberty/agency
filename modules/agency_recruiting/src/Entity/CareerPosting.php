<?php

declare(strict_types=1);

namespace Drupal\agency_recruiting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a career posting with no direct entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_career_posting",
 *   label = @Translation("Career posting"),
 *   handlers = {
 *     "access" = "Drupal\agency_recruiting\Access\DenyAccess",
 *     "storage" = "Drupal\agency_recruiting\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_career_posting",
 *   revision_table = "agency_career_posting_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   constraints = {
 *     "AgencyPublishedCloseDate" = {}
 *   },
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_career_posting',
  label: new TranslatableMarkup('Career posting'),
  handlers: [
    'access' => 'Drupal\\agency_recruiting\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_recruiting\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_career_posting',
  revision_table: 'agency_career_posting_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  constraints: ['AgencyPublishedCloseDate' => []],
  internal: TRUE,
)]
final class CareerPosting extends RecruitingRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'title' => 'string',
    'hiring_status' => 'string',
    'valid_through' => 'timestamp',
    'body' => 'string_long',
    'eligibility' => 'string_long',
    'status' => 'string',
  ];

  /**
   * {@inheritdoc}
   */
  public const MARKINGS = [
    'id' => 'internal',
    'uuid' => 'public',
    'created' => 'internal',
    'changed' => 'internal',
    'revision_id' => 'internal',
    'title' => 'public',
    'hiring_status' => 'public',
    'valid_through' => 'public',
    'body' => 'public',
    'eligibility' => 'internal',
    'status' => 'public',
  ];

}
