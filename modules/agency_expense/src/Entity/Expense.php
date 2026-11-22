<?php

declare(strict_types=1);

namespace Drupal\agency_expense\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an expense with no direct entity access.
 *
 * Drupal 10 uses the annotation; Drupal 11 uses the attribute below.
 *
 * @\Drupal\Core\Entity\Annotation\ContentEntityType(
 *   id = "agency_expense",
 *   label = @Translation("Expense"),
 *   handlers = {
 *     "access" = "Drupal\agency_expense\Access\DenyAccess",
 *     "storage" = "Drupal\agency_expense\Storage\IdentityPreservingStorage"
 *   },
 *   base_table = "agency_expense",
 *   revision_table = "agency_expense_revision",
 *   entity_keys = {"id" = "id", "uuid" = "uuid", "revision" = "revision_id"},
 *   revision_metadata_keys = {"revision_created" = "revision_created"},
 *   internal = TRUE
 * )
 */
#[ContentEntityType(
  id: 'agency_expense',
  label: new TranslatableMarkup('Expense'),
  handlers: [
    'access' => 'Drupal\\agency_expense\\Access\\DenyAccess',
    'storage' => 'Drupal\\agency_expense\\Storage\\IdentityPreservingStorage',
  ],
  base_table: 'agency_expense',
  revision_table: 'agency_expense_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
  revision_metadata_keys: ['revision_created' => 'revision_created'],
  internal: TRUE,
)]
final class Expense extends ExpenseRecord {

  /**
   * {@inheritdoc}
   */
  protected const FIELDS = [
    'assignment_uuid' => 'uuid_string',
    'amount' => 'decimal',
    'status' => 'string',
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
    'assignment_uuid' => 'internal',
    'amount' => 'financial',
    'status' => 'internal',
  ];

}
