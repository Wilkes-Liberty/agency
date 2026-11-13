<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\agency_workforce\Exception\WorkforceRefusal;

/**
 * Stores issuer-classified staff onboard submissions.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_staff_onboard",
 *   label = @Translation("Staff onboard"),
 *   handlers = {
 *     "access" = "Drupal\agency_workforce\Access\DenyAccess",
 *     "storage" = "Drupal\agency_workforce\Storage\WorkforceStorage"
 *   },
 *   base_table = "agency_staff_onboard",
 *   revision_table = "agency_staff_onboard_revision",
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
  id: 'agency_staff_onboard',
  label: new TranslatableMarkup('Staff onboard'),
  handlers: [
    'access' => 'Drupal\\agency_workforce\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_workforce\\Storage\\WorkforceStorage',
  ],
  base_table: 'agency_staff_onboard',
  revision_table: 'agency_staff_onboard_revision',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'owner' => 'owner_uid',
  ],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class StaffOnboard extends WorkforceRecord {

  public const EMPLOYEE = 'employee';

  public const CONTRACTOR = 'contractor';

  /**
   * Classifications the issuer may supply.
   */
  public const ALLOWED = [
    self::EMPLOYEE,
    self::CONTRACTOR,
  ];

  /**
   * Kinds that must never become workforce classification.
   */
  public const REFUSED = [
    'discovery',
    'client',
    'partner',
    'supplier',
  ];

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'invitation_uuid' => 'string',
    'person_uuid' => 'string',
    'classification' => 'string',
    'status' => 'string',
    'payload' => 'string_long',
  ];

  /**
   * {@inheritdoc}
   */
  public function assertWorkforceConstraints(): void {
    $classification = $this->get('classification')->value;
    if (!is_string($classification) || !in_array($classification, self::ALLOWED, TRUE)) {
      throw new WorkforceRefusal('invalid');
    }
    $status = $this->get('status')->value;
    if ($status !== NULL && $status !== '' && !in_array($status, [
      'received',
      'completed',
      'withdrawn',
    ], TRUE)) {
      throw new WorkforceRefusal('invalid');
    }
    $this->assertJsonPayload($this->get('payload')->value);
  }

}
