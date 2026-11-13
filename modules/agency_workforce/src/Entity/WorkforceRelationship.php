<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\agency_workforce\Exception\WorkforceRefusal;

/**
 * Stores a workforce relationship, never a Discovery record.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_workforce",
 *   label = @Translation("Workforce relationship"),
 *   handlers = {
 *     "access" = "Drupal\agency_workforce\Access\DenyAccess",
 *     "storage" = "Drupal\agency_workforce\Storage\WorkforceStorage"
 *   },
 *   base_table = "agency_workforce",
 *   revision_table = "agency_workforce_revision",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id",
 *     "owner" = "owner_uid"
 *   },
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_workforce',
  label: new TranslatableMarkup('Workforce relationship'),
  handlers: [
    'access' => 'Drupal\\agency_workforce\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_workforce\\Storage\\WorkforceStorage',
  ],
  base_table: 'agency_workforce',
  revision_table: 'agency_workforce_revision',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'owner' => 'owner_uid',
  ],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class WorkforceRelationship extends WorkforceRecord {

  public const KIND = 'workforce';

  /**
   * Workforce relationship lifecycle; these are not Discovery states.
   */
  public const STATES = [
    'invited',
    'active',
    'revoked',
    'suspended',
    'ended',
  ];

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'person_uuid' => 'string',
    'principal_uuid' => 'string',
    'state' => 'string',
    'kind' => 'string',
    'version' => 'integer',
  ];

  /**
   * {@inheritdoc}
   */
  public function assertWorkforceConstraints(): void {
    if ($this->scalar('kind') !== self::KIND) {
      throw new WorkforceRefusal('invalid');
    }
    if (!in_array($this->scalar('state'), self::STATES, TRUE)) {
      throw new WorkforceRefusal('invalid');
    }
  }

}
