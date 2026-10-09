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
 *   id = "agency_invitation",
 *   label = @Translation("Invitation generation"),
 *   handlers = {
 *     "access" = "Drupal\agency_staff\Access\InternalAccess",
 *     "storage_schema" = "Drupal\agency_staff\Storage\InternalSchema"
 *   },
 *   base_table = "agency_invitation",
 *   entity_keys = {"id" = "id", "uuid" = "uuid"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_invitation',
  label: new TranslatableMarkup('Invitation generation'),
  handlers: [
    'access' => 'Drupal\\agency_staff\\Access\\InternalAccess',
    'storage_schema' => 'Drupal\\agency_staff\\Storage\\InternalSchema',
  ],
  base_table: 'agency_invitation',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  internal: TRUE,
)]
final class InvitationGeneration extends InternalRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'relationship_uuid' => 'string',
    'principal_uuid' => 'string',
    'token_hash' => 'string',
    'status' => 'string',
    'expires' => 'timestamp',
    'delivery_state' => 'string',
  ];

}
