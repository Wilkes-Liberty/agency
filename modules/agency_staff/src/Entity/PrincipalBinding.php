<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an internal Discovery record with no direct entity access.
 */
#[ContentEntityType(
  id: 'agency_principal',
  label: new TranslatableMarkup('Principal binding'),
  handlers: [
    'access' => 'Drupal\\agency_staff\\Access\\InternalAccess',
    'storage_schema' => 'Drupal\\agency_staff\\Storage\\InternalSchema',
  ],
  base_table: 'agency_principal',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  internal: TRUE,
)]
final class PrincipalBinding extends InternalRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'principal_key' => 'string',
    'issuer' => 'string',
    'subject' => 'string',
    'person_uuid' => 'string',
    'status' => 'string',
  ];

}
