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
 *   id = "agency_person",
 *   label = @Translation("Thin person resolver"),
 *   handlers = {
 *     "access" = "Drupal\agency_staff\Access\InternalAccess",
 *     "storage_schema" = "Drupal\agency_staff\Storage\InternalSchema"
 *   },
 *   base_table = "agency_person",
 *   entity_keys = {"id" = "id", "uuid" = "uuid"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_person',
  label: new TranslatableMarkup('Thin person resolver'),
  handlers: [
    'access' => 'Drupal\\agency_staff\\Access\\InternalAccess',
    'storage_schema' => 'Drupal\\agency_staff\\Storage\\InternalSchema',
  ],
  base_table: 'agency_person',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  internal: TRUE,
)]
final class PersonResolver extends InternalRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'display_name' => 'string',
    'contact_kind' => 'string',
    'contact_value' => 'string',
  ];

}
