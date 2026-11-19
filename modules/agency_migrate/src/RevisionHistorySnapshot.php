<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\MapItem;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;

/**
 * Converts persisted revision fields into a versioned, object-free value tree.
 *
 * This is a reconstruction payload, not a history qualification or write fence.
 */
final class RevisionHistorySnapshot {

  /**
   * Builds a complete snapshot from an explicitly loaded persisted history.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $current
   *   The explicitly selected persisted default revision.
   * @param list<\Drupal\Core\Entity\ContentEntityInterface> $history
   *   Persisted revisions, including the selected default.
   *
   * @return array<string, mixed>
   *   Versioned reconstruction payload.
   */
  public static function capture(ContentEntityInterface $current, array $history): array {
    $revisions = [];
    foreach ($history as $revision) {
      $fields = self::fields($revision);
      $revisions[] = [
        'revision_id' => (int) $revision->getRevisionId(),
        'default' => $revision->isDefaultRevision(),
        'langcode' => $revision->getUntranslated()->language()->getId(),
        'languages' => array_keys($fields[array_key_first($fields)]),
        'fields' => $fields,
      ];
    }
    $layout = self::layout($current);
    return [
      'version' => self::formatVersion($layout),
      'source_type' => $current->getEntityTypeId(),
      'id' => (int) $current->id(),
      'uuid' => $current->uuid(),
      'default_revision_id' => (int) $current->getRevisionId(),
      'layout' => $layout,
      'revision_count' => count($revisions),
      'revisions' => $revisions,
    ];
  }

  /**
   * Validates every value and reconstructs detached target revision objects.
   *
   * No entity is loaded or saved. Missing fields and implicit defaults refuse.
   *
   * @return array<int, \Drupal\Core\Entity\ContentEntityInterface>
   *   Detached revisions indexed by their original revision identifiers.
   */
  public static function decode(array $snapshot, ContentEntityStorageInterface $storage): array {
    self::assertValues($snapshot);
    $keys = [
      'version', 'source_type', 'id', 'uuid', 'default_revision_id',
      'layout', 'revision_count', 'revisions',
    ];
    if (!self::keys($snapshot, $keys)
      || !is_string($snapshot['source_type']) || $snapshot['source_type'] === ''
      || !is_int($snapshot['id']) || $snapshot['id'] < 1
      || !is_string($snapshot['uuid']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $snapshot['uuid'])
      || !is_int($snapshot['default_revision_id']) || $snapshot['default_revision_id'] < 1
      || !is_array($snapshot['layout']) || !is_array($snapshot['layout']['fields'] ?? NULL)
      || $snapshot['version'] !== self::formatVersion($snapshot['layout'])
      || !is_int($snapshot['revision_count']) || $snapshot['revision_count'] < 1
      || !is_array($snapshot['revisions']) || !array_is_list($snapshot['revisions']) || count($snapshot['revisions']) !== $snapshot['revision_count']) {
      throw new MigrateRefusal('history_unverified');
    }
    $probe = $storage->create(self::probeValues($snapshot, $storage));
    if (!$probe instanceof ContentEntityInterface
      || !$probe->getEntityType()->isRevisionable()
      || self::layout($probe) !== $snapshot['layout']) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $result = [];
    $defaults = 0;
    foreach ($snapshot['revisions'] as $row) {
      if (!is_array($row) || !self::keys($row, ['revision_id', 'default', 'langcode', 'languages', 'fields'])
        || !is_int($row['revision_id']) || $row['revision_id'] < 1
        || isset($result[$row['revision_id']])
        || !is_bool($row['default'])
        || !is_string($row['langcode']) || $row['langcode'] === ''
        || !is_array($row['languages']) || !array_is_list($row['languages'])
        || !is_array($row['fields']) || array_keys($row['fields']) !== array_keys($snapshot['layout']['fields'])) {
        throw new MigrateRefusal('history_unverified');
      }
      $languages = NULL;
      $translations = [];
      foreach ($row['fields'] as $name => $values) {
        if (!is_array($values) || $values === [] || !isset($values[$row['langcode']])) {
          throw new MigrateRefusal('history_unverified');
        }
        $languages ??= array_keys($values);
        if (array_keys($values) !== $languages || $languages !== $row['languages']) {
          throw new MigrateRefusal('history_unverified');
        }
        foreach ($values as $langcode => $items) {
          if (!is_string($langcode) || $langcode === '' || !is_array($items) || !array_is_list($items)) {
            throw new MigrateRefusal('history_unverified');
          }
          $cardinality = $snapshot['layout']['fields'][$name]['cardinality'];
          if ($cardinality > 0 && count($items) > $cardinality) {
            throw new MigrateRefusal('history_unverified');
          }
          $definition = $snapshot['layout']['fields'][$name];
          $properties = array_keys($definition['properties']);
          $dynamicMap = $definition['type'] === 'map' && $properties === [];
          foreach ($items as $item) {
            if (!is_array($item) || (!$dynamicMap && array_keys($item) !== $properties)) {
              throw new MigrateRefusal('incompatible_field_layout');
            }
          }
          $translations[$langcode][$name] = $items;
        }
      }
      $revision = $storage->create($translations[$row['langcode']]);
      if (!$revision instanceof ContentEntityInterface || $revision->language()->getId() !== $row['langcode']) {
        throw new MigrateRefusal('history_unverified');
      }
      foreach ($translations as $langcode => $values) {
        if ($langcode !== $row['langcode']) {
          $revision->addTranslation($langcode, $values);
        }
      }
      $revision->enforceIsNew(FALSE);
      $revision->setNewRevision(FALSE);
      $revision->set($revision->getEntityType()->getKey('revision'), $row['revision_id']);
      $revision->isDefaultRevision($row['default']);
      if ((int) $revision->id() !== $snapshot['id'] || $revision->uuid() !== $snapshot['uuid']
        || (int) $revision->getRevisionId() !== $row['revision_id']
        || self::fields($revision) !== $row['fields']
        || ($row['default'] && ($row['revision_id'] !== $snapshot['default_revision_id'] || !$revision->wasDefaultRevision()))) {
        throw new MigrateRefusal('history_unverified');
      }
      $defaults += (int) $row['default'];
      $result[$row['revision_id']] = $revision;
    }
    if ($defaults !== 1 || !isset($result[$snapshot['default_revision_id']]) || !$result[$snapshot['default_revision_id']]->isDefaultRevision()) {
      throw new MigrateRefusal('history_unverified');
    }
    ksort($result, SORT_NUMERIC);
    return $result;
  }

  /**
   * Uses the target's bundle key and the same captured value for every row.
   *
   * @return array<string, string>
   *   Only the bundle, or no probe defaults for an unbundled target.
   */
  private static function probeValues(array $snapshot, ContentEntityStorageInterface $storage): array {
    $key = $storage->getEntityType()->getKey('bundle');
    if (($snapshot['layout']['keys']['bundle'] ?? NULL) !== $key) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $bundle = self::bundle($snapshot);
    if ($bundle === NULL) {
      return [];
    }
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($storage->getEntityTypeId());
    if (!isset($bundles[$bundle])) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    return [$key => $bundle];
  }

  /**
   * Reads the bundle from every revision and translation of a snapshot.
   */
  public static function bundle(array $snapshot): ?string {
    $key = $snapshot['layout']['keys']['bundle'];
    if (!$key) {
      return NULL;
    }
    $bundle = NULL;
    foreach ($snapshot['revisions'] as $revision) {
      if (!is_array($revision) || !isset($revision['fields'][$key])
        || !is_array($revision['fields'][$key]) || $revision['fields'][$key] === []) {
        throw new MigrateRefusal('history_unverified');
      }
      foreach ($revision['fields'][$key] as $items) {
        if (!is_array($items) || !array_is_list($items) || count($items) !== 1
          || !is_array($items[0]) || count($items[0]) !== 1) {
          throw new MigrateRefusal('history_unverified');
        }
        $value = array_values($items[0])[0];
        if (!is_string($value) || $value === '' || ($bundle !== NULL && $value !== $bundle)) {
          throw new MigrateRefusal('history_unverified');
        }
        $bundle = $value;
      }
    }
    if ($bundle === NULL) {
      throw new MigrateRefusal('history_unverified');
    }
    return $bundle;
  }

  /**
   * Every stored property, preserving typed scalars and ordered field items.
   *
   * @return array<string, array<string, list<array<string, mixed>>>>
   *   Fields, translations and their typed property values.
   */
  public static function fields(ContentEntityInterface $entity): array {
    $result = [];
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      foreach ($entity->getTranslation($langcode)->getFields(FALSE) as $name => $field) {
        if ($field->getFieldDefinition()->isComputed()) {
          continue;
        }
        $result[$name][$langcode] = [];
        foreach ($field as $item) {
          // Core maps have dynamic keys and an empty property definition.
          if ($field->getFieldDefinition()->getType() === 'map') {
            $result[$name][$langcode][] = self::mapValues($item);
            continue;
          }
          $properties = [];
          foreach ($item->getProperties(FALSE) as $property_name => $property) {
            $value = $property->getValue();
            $properties[$property_name] = $value !== NULL && $property instanceof PrimitiveInterface
              ? $property->getCastedValue() : $value;
          }
          ksort($properties);
          $result[$name][$langcode][] = $properties;
        }
      }
    }
    ksort($result);
    foreach ($result as &$translations) {
      ksort($translations);
    }
    self::assertValues($result);
    return $result;
  }

  /**
   * Returns the exact object-free values of a stored core map item.
   *
   * Key order and scalar types are part of the persisted map value.
   */
  public static function mapValues(FieldItemInterface $item): array {
    if (!$item instanceof MapItem || !is_array($values = $item->getValue())) {
      throw new MigrateRefusal('history_unverified');
    }
    self::assertValues($values);
    return $values;
  }

  /**
   * Structural compatibility, including property types and identity key names.
   *
   * @return array<string, mixed>
   *   Object-free stored field layout.
   */
  public static function layout(ContentEntityInterface $entity): array {
    $type = $entity->getEntityType();
    if (!$type instanceof ContentEntityTypeInterface) {
      throw new MigrateRefusal('incompatible_field_layout');
    }
    $metadata = $type->getRevisionMetadataKeys();
    ksort($metadata);
    $fields = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->isComputed()) {
        continue;
      }
      $properties = [];
      $item_definition = $definition->getItemDefinition();
      if (!$item_definition instanceof ComplexDataDefinitionInterface) {
        throw new MigrateRefusal('incompatible_field_layout');
      }
      foreach ($item_definition->getPropertyDefinitions() as $property_name => $property) {
        if (!$property->isComputed()) {
          $properties[$property_name] = $property->getDataType();
        }
      }
      ksort($properties);
      $fields[$name] = [
        'type' => $definition->getType(),
        'translatable' => $definition->isTranslatable(),
        'revisionable' => $definition->getFieldStorageDefinition()->isRevisionable(),
        'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
        'settings' => self::settings($definition->getFieldStorageDefinition()->getSettings()),
        'properties' => $properties,
      ];
    }
    ksort($fields);
    $keys = [];
    foreach (['id', 'uuid', 'revision', 'langcode', 'bundle'] as $key) {
      $keys[$key] = $entity->getEntityType()->getKey($key);
    }
    return [
      'keys' => $keys,
      'revision_metadata_keys' => $metadata,
      'revisionable' => $entity->getEntityType()->isRevisionable(),
      'translatable' => $entity->getEntityType()->isTranslatable(),
      'fields' => $fields,
    ];
  }

  /**
   * Rejects older map snapshots whose capture could discard stored keys.
   */
  private static function formatVersion(array $layout): int {
    return in_array('map', array_column($layout['fields'], 'type'), TRUE) ? 2 : 1;
  }

  /**
   * Normalizes definition labels without accepting objects in stored payloads.
   */
  private static function settings(mixed $value): mixed {
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if (is_array($value)) {
      if (!array_is_list($value)) {
        ksort($value);
      }
      return array_map(self::settings(...), $value);
    }
    self::assertValues($value);
    return $value;
  }

  /**
   * Refuses object graphs, resources, nonfinite numbers and excessive nesting.
   */
  private static function assertValues(mixed $value, int $depth = 0): void {
    if ($depth > 32 || (is_float($value) && !is_finite($value))
      || (!is_null($value) && !is_scalar($value) && !is_array($value))) {
      throw new MigrateRefusal('history_unverified');
    }
    if (is_array($value)) {
      foreach ($value as $item) {
        self::assertValues($item, $depth + 1);
      }
    }
  }

  /**
   * Checks exact envelope keys independently of their input order.
   */
  private static function keys(array $value, array $expected): bool {
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    return $actual === $expected;
  }

}
