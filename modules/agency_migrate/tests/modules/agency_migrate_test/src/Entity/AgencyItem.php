<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Synthetic Agency target used only in tests.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_migrate_agency_item",
 *   label = @Translation("Synthetic Agency item"),
 *   handlers = {
 *     "access" = "Drupal\agency_migrate_test\Access\DenyAccess",
 *     "storage" = "Drupal\agency_migrate_test\InterruptibleStorage"
 *   },
 *   base_table = "agency_migrate_agency_item",
 *   revision_table = "agency_migrate_agency_item_revision",
 *   data_table = "agency_migrate_agency_item_field_data",
 *   revision_data_table = "agency_migrate_agency_item_field_revision",
 *   translatable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id",
 *     "label" = "title",
 *     "langcode" = "langcode"
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message"
 *   },
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_migrate_agency_item',
  label: new TranslatableMarkup('Synthetic Agency item'),
  handlers: [
    'access' => 'Drupal\\agency_migrate_test\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_migrate_test\\InterruptibleStorage',
  ],
  base_table: 'agency_migrate_agency_item',
  revision_table: 'agency_migrate_agency_item_revision',
  data_table: 'agency_migrate_agency_item_field_data',
  revision_data_table: 'agency_migrate_agency_item_field_revision',
  translatable: TRUE,
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'label' => 'title',
    'langcode' => 'langcode',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
  internal: TRUE,
)]
final class AgencyItem extends SyntheticItem {}
