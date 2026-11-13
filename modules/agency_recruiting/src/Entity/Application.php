<?php

declare(strict_types=1);

namespace Drupal\agency_recruiting\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an application with a File Gate résumé UUID and no entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_application",
 *   label = @Translation("Application"),
 *   handlers = {
 *     "access" = "Drupal\agency_recruiting\Access\DenyAccess",
 *     "storage" = "Drupal\agency_recruiting\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_application",
 *   revision_table = "agency_application_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_application',
  label: new TranslatableMarkup('Application'),
  handlers: [
    'access' => 'Drupal\\agency_recruiting\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_recruiting\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_application',
  revision_table: 'agency_application_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Application extends RecruitingRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'posting_uuid' => 'uuid_string',
    'person_uuid' => 'uuid_string',
    'status' => 'string',
    'payload' => 'string_long',
    'resume_file_uuid' => 'uuid_string',
    'geo' => 'string_long',
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
    'posting_uuid' => 'internal',
    'person_uuid' => 'internal',
    'status' => 'internal',
    'payload' => 'personal',
    'resume_file_uuid' => 'personal',
    'geo' => 'personal',
  ];

}
