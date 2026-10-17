<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Shared synthetic record used as a legacy source or an Agency target.
 */
abstract class SyntheticItem extends ContentEntityBase implements RevisionLogInterface {

  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(t('Created'))->setRevisionable(TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Changed'))->setRevisionable(TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE);
    $fields['payload'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payload'))
      ->setSetting('max_length', 255)
      ->setRevisionable(TRUE);
    $fields['related_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Related'))
      ->setRevisionable(TRUE);
    $fields['access_class'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Access class'))
      ->setSetting('max_length', 64)
      ->setRevisionable(TRUE);
    return $fields;
  }

}
