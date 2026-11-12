<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
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
   *   Version 1 reconstruction payload.
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
    return [
      'version' => 1,
      'source_type' => $current->getEntityTypeId(),
      'id' => (int) $current->id(),
      'uuid' => $current->uuid(),
      'default_revision_id' => (int) $current->getRevisionId(),
      'layout' => self::layout($current),
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
      || $snapshot['version'] !== 1
      || !is_string($snapshot['source_type']) || $snapshot['source_type'] === ''
      || !is_int($snapshot['id']) || $snapshot['id'] < 1
      || !is_string($snapshot['uuid']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $snapshot['uuid'])
      || !is_int($snapshot['default_revision_id']) || $snapshot['default_revision_id'] < 1
      || !is_array($snapshot['layout'])
      || !is_int($snapshot['revision_count']) || $snapshot['revision_count'] < 1
      || !is_array($snapshot['revisions']) || !array_is_list($snapshot['revisions']) || count($snapshot['revisions']) !== $snapshot['revision_count']) {
      throw new MigrateRefusal('history_unverified');
    }
    $probe = $storage->create([]);
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
          $properties = array_keys($snapshot['layout']['fields'][$name]['properties']);
          foreach ($items as $item) {
            if (!is_array($item) || array_keys($item) !== $properties) {
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
   * Structural compatibility, including property types and identity key names.
   *
   * @return array<string, mixed>
   *   Object-free stored field layout.
   */
  private static function layout(ContentEntityInterface $entity): array {
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
      'revisionable' => $entity->getEntityType()->isRevisionable(),
      'translatable' => $entity->getEntityType()->isTranslatable(),
      'fields' => $fields,
    ];
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
