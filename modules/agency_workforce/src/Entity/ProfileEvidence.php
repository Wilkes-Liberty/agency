<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\agency_workforce\Exception\WorkforceRefusal;

/**
 * Stores marked profile evidence and File Gate UUID references.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_profile_evidence",
 *   label = @Translation("Profile evidence"),
 *   handlers = {
 *     "access" = "Drupal\agency_workforce\Access\DenyAccess",
 *     "storage" = "Drupal\agency_workforce\Storage\WorkforceStorage"
 *   },
 *   base_table = "agency_profile_evidence",
 *   revision_table = "agency_profile_evidence_revision",
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
  id: 'agency_profile_evidence',
  label: new TranslatableMarkup('Profile evidence'),
  handlers: [
    'access' => 'Drupal\\agency_workforce\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_workforce\\Storage\\WorkforceStorage',
  ],
  base_table: 'agency_profile_evidence',
  revision_table: 'agency_profile_evidence_revision',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'owner' => 'owner_uid',
  ],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class ProfileEvidence extends WorkforceRecord {

  /**
   * Slice 2 projection audiences. Account-summary is read-only.
   */
  public const AUDIENCES = [
    'own',
    'account_summary',
  ];

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'person_uuid' => 'string',
    'classification' => 'string',
    'status' => 'string',
    'display_name' => 'string',
    'evidence' => 'string_long',
    'file_ref_uuid' => 'string',
  ];

  /**
   * {@inheritdoc}
   */
  public function assertWorkforceConstraints(): void {
    $status = $this->get('status')->value;
    if ($status !== NULL && $status !== '' && $status !== 'recorded') {
      throw new WorkforceRefusal('invalid');
    }
    $this->assertFileGateUuid($this->get('file_ref_uuid')->value);
    $this->assertJsonPayload($this->get('evidence')->value);
  }

}
