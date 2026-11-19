<?php

declare(strict_types=1);

namespace Drupal\agency_commercial\ContactMigration;

use Drupal\agency_commercial\Exception\CommercialRefusal;

/**
 * Defines the stored property contract for the CRM contact source.
 *
 * @internal
 */
final class ContactFields {

  private const FIELD_TYPES = [
    'address' => 'address',
    'changed' => 'changed',
    'client_user' => 'entity_reference',
    'contact_restriction' => 'list_string',
    'created' => 'created',
    'email' => 'email',
    'email_match_key' => 'string',
    'email_merge_hint' => 'string',
    'email_original' => 'string',
    'first_touch' => 'string_long',
    'id' => 'integer',
    'label' => 'string',
    'last_touch' => 'string_long',
    'mailbox_kind' => 'list_string',
    'merge_token' => 'string',
    'merged_into' => 'entity_reference',
    'organization_name' => 'string',
    'owner' => 'entity_reference',
    'phone' => 'telephone',
    'revision_created' => 'created',
    'revision_default' => 'boolean',
    'revision_id' => 'integer',
    'revision_log_message' => 'string_long',
    'revision_user' => 'entity_reference',
    'source' => 'list_string',
    'uuid' => 'uuid',
  ];

  private const ADDRESS_PROPERTIES = [
    'additional_name',
    'address_line1',
    'address_line2',
    'address_line3',
    'administrative_area',
    'country_code',
    'dependent_locality',
    'family_name',
    'given_name',
    'langcode',
    'locality',
    'organization',
    'postal_code',
    'sorting_code',
  ];

  /**
   * Refuses unreviewed layout changes before accepting any property values.
   */
  public static function assertLayout(array $layout): void {
    if (($layout['keys'] ?? NULL) !== [
      'id' => 'id',
      'uuid' => 'uuid',
      'revision' => 'revision_id',
      'langcode' => '',
      'bundle' => '',
    ] || ($layout['revision_metadata_keys'] ?? NULL) !== [
      'revision_created' => 'revision_created',
      'revision_default' => 'revision_default',
      'revision_log_message' => 'revision_log_message',
      'revision_user' => 'revision_user',
    ] || ($layout['revisionable'] ?? NULL) !== TRUE || ($layout['translatable'] ?? NULL) !== FALSE
      || !is_array($layout['fields'] ?? NULL) || array_keys($layout['fields']) !== array_keys(self::FIELD_TYPES)) {
      throw new CommercialRefusal('contact_source_layout_unreviewed');
    }

    foreach (self::FIELD_TYPES as $field => $type) {
      $definition = $layout['fields'][$field];
      $revisionable = !in_array($field, ['id', 'uuid', 'revision_id', 'created'], TRUE);
      if (!is_array($definition) || ($definition['type'] ?? NULL) !== $type || ($definition['cardinality'] ?? NULL) !== 1
        || ($definition['revisionable'] ?? NULL) !== $revisionable || ($definition['translatable'] ?? NULL) !== FALSE
        || ($definition['properties'] ?? NULL) !== self::properties($type)
        || ($type === 'entity_reference' && ($definition['settings']['target_type'] ?? NULL) !== ($field === 'merged_into' ? 'crm_contact' : 'user'))) {
        throw new CommercialRefusal('contact_source_layout_unreviewed');
      }
    }
  }

  /**
   * Extracts exact typed item lists from one nontranslatable source revision.
   */
  public static function items(array $revision): array {
    $fields = $revision['fields'] ?? [];
    $language = $revision['langcode'] ?? NULL;
    if (!is_string($language) || $language === '' || ($revision['languages'] ?? NULL) !== [$language]
      || !is_array($fields) || array_keys($fields) !== array_keys(self::FIELD_TYPES)) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
    $result = [];
    foreach (self::FIELD_TYPES as $field => $type) {
      if (!is_array($fields[$field]) || array_keys($fields[$field]) !== [$language]) {
        throw new CommercialRefusal('contact_source_history_invalid');
      }
      $items = $fields[$field][$language];
      if (!is_array($items) || !array_is_list($items) || count($items) > 1) {
        throw new CommercialRefusal('contact_source_history_invalid');
      }
      $properties = self::properties($type);
      foreach ($items as $item) {
        if (!is_array($item) || array_keys($item) !== array_keys($properties)) {
          throw new CommercialRefusal('contact_source_layout_unreviewed');
        }
        foreach ($properties as $key => $propertyType) {
          self::assertValue($item[$key], $propertyType);
        }
      }
      $result[$field] = $items;
    }
    return $result;
  }

  /**
   * Returns the exact reviewed stored property names and scalar types.
   */
  private static function properties(string $type): array {
    return match ($type) {
      'address' => array_fill_keys(self::ADDRESS_PROPERTIES, 'string'),
      'entity_reference' => ['target_id' => 'integer'],
      'integer' => ['value' => 'integer'],
      'created', 'changed' => ['value' => 'timestamp'],
      'email' => ['value' => 'email'],
      'boolean' => ['value' => 'boolean'],
      default => ['value' => 'string'],
    };
  }

  /**
   * Checks the PHP value without coercion or vocabulary normalization.
   */
  private static function assertValue(mixed $value, string $type): void {
    if ($value === NULL) {
      return;
    }
    $valid = match ($type) {
      'integer', 'timestamp' => is_int($value),
      'boolean' => is_bool($value),
      'string', 'email' => is_string($value),
      default => FALSE,
    };
    if (!$valid) {
      throw new CommercialRefusal('contact_source_history_invalid');
    }
  }

}
