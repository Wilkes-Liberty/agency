<?php

declare(strict_types=1);

namespace Drupal\agency_agreement\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Shared agreement storage with explicit identity fields.
 */
abstract class AgreementRecord extends ContentEntityBase {

  /**
   * Scalar fields declared by each record, keyed by name.
   *
   * @var array<string, string>
   */
  protected const FIELDS = [];

  /**
   * Field Guard markings for ontology properties, keyed by field name.
   *
   * @var array<string, string>
   */
  public const MARKINGS = [];

  /**
   * Identifier fields kept for ID-preserving copy that the ontology omits.
   *
   * @var array<string, string>
   */
  public const IDENTITY_FIELDS = [
    'owner_uid' => 'internal',
    'revision_created' => 'internal',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type,
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));
    $fields['owner_uid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Owner'));
    foreach (static::FIELDS as $name => $type) {
      $field = BaseFieldDefinition::create(self::storageType($type))
        ->setLabel(ucfirst(str_replace('_', ' ', $name)));
      if ($type === 'string') {
        $field->setSetting('max_length', 255);
      }
      if ($type === 'uuid_string') {
        $field->setSetting('max_length', 36);
      }
      $fields[$name] = $field;
    }
    if ($entity_type->isRevisionable()) {
      $fields['revision_created'] = BaseFieldDefinition::create('timestamp')
        ->setLabel(t('Revision time'))
        ->setRevisionable(TRUE);
      $skip = ['id', 'uuid', 'revision_id'];
      foreach (array_diff(array_keys($fields), $skip) as $name) {
        if ($fields[$name] instanceof BaseFieldDefinition) {
          $fields[$name]->setRevisionable(TRUE);
        }
      }
    }
    return $fields;
  }

  /**
   * Maps ontology JSON properties onto Drupal field types.
   */
  private static function storageType(string $type): string {
    return $type === 'uuid_string' ? 'string' : $type;
  }

}
