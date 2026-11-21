<?php

declare(strict_types=1);

namespace Drupal\agency_intake\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a business intake submission with no direct entity access.
 *
 * Payload JSON is stored as a long string. Generic form mechanics stay
 * with the host.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_intake_submission",
 *   label = @Translation("Intake submission"),
 *   handlers = {
 *     "access" = "Drupal\agency_intake\Access\DenyAccess",
 *     "storage" = "Drupal\agency_intake\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_intake_submission",
 *   revision_table = "agency_intake_submission_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_intake_submission',
  label: new TranslatableMarkup('Intake submission'),
  handlers: [
    'access' => 'Drupal\\agency_intake\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_intake\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_intake_submission',
  revision_table: 'agency_intake_submission_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class IntakeSubmission extends IntakeRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'organization_uuid' => 'uuid_string',
    'status' => 'string',
    'payload' => 'string_long',
    'dispatch_key' => 'string',
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
    'status' => 'internal',
    'payload' => 'personal',
    'dispatch_key' => 'internal',
  ];

}
