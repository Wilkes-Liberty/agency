<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\agency_workforce\Exception\WorkforceRefusal;

/**
 * Stores a workforce entitlement, never a Discovery grant.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_workforce_entitlement",
 *   label = @Translation("Workforce entitlement"),
 *   handlers = {
 *     "access" = "Drupal\agency_workforce\Access\DenyAccess",
 *     "storage" = "Drupal\agency_workforce\Storage\WorkforceStorage"
 *   },
 *   base_table = "agency_workforce_entitlement",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "owner_uid"
 *   },
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_workforce_entitlement',
  label: new TranslatableMarkup('Workforce entitlement'),
  handlers: [
    'access' => 'Drupal\\agency_workforce\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_workforce\\Storage\\WorkforceStorage',
  ],
  base_table: 'agency_workforce_entitlement',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'owner_uid',
  ],
  internal: TRUE,
)]
final class WorkforceEntitlement extends WorkforceRecord {

  /**
   * Workforce entitlement statuses. Discovery summary_only is not reused.
   */
  public const STATUSES = [
    'pending',
    'active',
    'revoked',
    'suspended',
  ];

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'relationship_uuid' => 'string',
    'principal_uuid' => 'string',
    'status' => 'string',
    'version' => 'integer',
    'readable_until' => 'timestamp',
    'details' => 'string_long',
  ];

  /**
   * {@inheritdoc}
   */
  public function assertWorkforceConstraints(): void {
    $status = $this->get('status')->value;
    if ($status !== NULL && $status !== '' && !in_array($status, self::STATUSES, TRUE)) {
      throw new WorkforceRefusal('invalid');
    }
    $this->assertJsonPayload($this->get('details')->value);
  }

}
