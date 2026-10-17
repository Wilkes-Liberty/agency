<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Synthetic legacy source used only in tests.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_migrate_legacy_item",
 *   label = @Translation("Synthetic legacy item"),
 *   handlers = {
 *     "access" = "Drupal\agency_migrate_test\Access\DenyAccess"
 *   },
 *   base_table = "agency_migrate_legacy_item",
 *   revision_table = "agency_migrate_legacy_item_revision",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id",
 *     "label" = "title"
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
  id: 'agency_migrate_legacy_item',
  label: new TranslatableMarkup('Synthetic legacy item'),
  handlers: [
    'access' => 'Drupal\\agency_migrate_test\\Access\\DenyAccess',
  ],
  base_table: 'agency_migrate_legacy_item',
  revision_table: 'agency_migrate_legacy_item_revision',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'revision' => 'revision_id',
    'label' => 'title',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
  internal: TRUE,
)]
final class LegacyItem extends SyntheticItem {}
