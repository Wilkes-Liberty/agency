<?php

declare(strict_types=1);

namespace Drupal\agency_staff\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Internal storage only; callers use the authorized Discovery services.
 */
abstract class InternalRecord extends ContentEntityBase {

  /**
   * Scalar fields declared by each bounded record.
   */
  protected const FIELDS = [];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['id'] = BaseFieldDefinition::create('integer')->setLabel(t('ID'))->setReadOnly(TRUE);
    $fields['uuid'] = BaseFieldDefinition::create('uuid')->setLabel(t('UUID'))->setReadOnly(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Changed'));
    foreach (static::FIELDS as $name => $type) {
      $field = BaseFieldDefinition::create($type)->setLabel(ucfirst(str_replace('_', ' ', $name)));
      if ($type === 'string') {
        $field->setSetting('max_length', 255);
      }
      $fields[$name] = $field;
    }
    if ($entity_type->isRevisionable()) {
      $fields['revision_id'] = BaseFieldDefinition::create('integer')->setLabel(t('Revision'))->setReadOnly(TRUE);
      $fields['revision_created'] = BaseFieldDefinition::create('timestamp')->setLabel(t('Revision time'))->setRevisionable(TRUE);
      foreach (array_diff(array_keys($fields), ['id', 'uuid', 'revision_id']) as $name) {
        if ($fields[$name] instanceof BaseFieldDefinition) {
          $fields[$name]->setRevisionable(TRUE);
        }
      }
    }
    return $fields;
  }

  /**
   * Returns a scalar field without exposing the entity to API consumers.
   */
  public function scalar(string $field): string {
    return (string) $this->get($field)->value;
  }

  /**
   * Decodes bounded, validated internal details.
   */
  public function details(): array {
    return json_decode($this->scalar('details') ?: '{}', TRUE, 32, JSON_THROW_ON_ERROR);
  }

  /**
   * Replaces internal details after command validation.
   */
  public function setDetails(array $details): static {
    $this->set('details', json_encode($details, JSON_THROW_ON_ERROR));
    return $this;
  }

}
