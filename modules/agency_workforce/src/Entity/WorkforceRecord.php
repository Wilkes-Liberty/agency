<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\agency_workforce\Exception\WorkforceRefusal;

/**
 * Internal workforce storage; callers must not treat these as a public API.
 */
abstract class WorkforceRecord extends ContentEntityBase {

  /**
   * Scalar fields declared by each bounded record.
   *
   * @var array<string, string>
   */
  protected const FIELDS = [];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE);
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setReadOnly(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));
    $owner = $entity_type->getKey('owner');
    if (is_string($owner) && $owner !== '') {
      $fields[$owner] = BaseFieldDefinition::create('integer')
        ->setLabel(t('Owner'))
        ->setDefaultValue(0);
    }
    foreach (static::FIELDS as $name => $type) {
      $field = BaseFieldDefinition::create($type)
        ->setLabel(ucfirst(str_replace('_', ' ', $name)));
      if ($type === 'string') {
        $field->setSetting('max_length', 255);
      }
      $fields[$name] = $field;
    }
    if ($entity_type->isRevisionable()) {
      $fields['revision_id'] = BaseFieldDefinition::create('integer')
        ->setLabel(t('Revision'))
        ->setReadOnly(TRUE);
      $fields['revision_created'] = BaseFieldDefinition::create('timestamp')
        ->setLabel(t('Revision time'))
        ->setRevisionable(TRUE);
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
   * Refuses values that violate the registered workforce contract.
   */
  public function assertWorkforceConstraints(): void {
    // Bounded records add their own refusals.
  }

  /**
   * Refuses inline file bodies, data URIs and remote file payloads.
   */
  protected function assertFileGateUuid(?string $value): void {
    if ($value === NULL || $value === '') {
      return;
    }
    if (str_starts_with($value, 'data:') || str_contains($value, '://')) {
      throw new WorkforceRefusal('invalid');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
      throw new WorkforceRefusal('invalid');
    }
  }

  /**
   * Refuses non-JSON blobs on marked JSON properties.
   */
  protected function assertJsonPayload(?string $value): void {
    if ($value === NULL || $value === '') {
      return;
    }
    try {
      $decoded = json_decode($value, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new WorkforceRefusal('invalid');
    }
    if (!is_array($decoded)) {
      throw new WorkforceRefusal('invalid');
    }
  }

}
