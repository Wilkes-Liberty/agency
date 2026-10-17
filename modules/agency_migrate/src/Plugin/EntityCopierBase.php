<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Entity-API copier that preserves integer ids, UUIDs and revision ids.
 *
 * Concrete plugins implement ContainerFactoryPluginInterface and call this
 * constructor from create().
 */
abstract class EntityCopierBase extends PluginBase implements CopierInterface {

  /**
   * Field names that carry identity rather than marked content.
   */
  private const IDENTITY_FIELDS = [
    'id',
    'uuid',
    'revision_id',
    'langcode',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'created',
    'changed',
    'revision_uid',
    'revision_timestamp',
    'revision_log',
    'revision_user',
    'revision_created',
    'revision_log_message',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly IdentityPreservingWriter $writer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function sourceEntityType(): string {
    return (string) $this->pluginDefinition['source_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function targetEntityType(): string {
    return (string) $this->pluginDefinition['target_type'];
  }

  /**
   * Unmapped or invalid markings for the source entity type.
   *
   * @return list<string>
   *   Property names that would travel without a known marking.
   */
  public function unmappedProperties(): array {
    $markings = $this->propertyMarkings();
    $unmapped = [];
    $probe = $this->entityTypeManager->getStorage($this->sourceEntityType())->create([]);
    if (!$probe instanceof ContentEntityInterface) {
      throw new MigrateRefusal('plugin_missing');
    }
    foreach ($probe->getFieldDefinitions() as $name => $definition) {
      if ($definition->isComputed() || in_array($name, self::IDENTITY_FIELDS, TRUE)) {
        continue;
      }
      if (!isset($markings[$name])) {
        $unmapped[] = $name;
        continue;
      }
      if (!in_array($markings[$name], CopierInterface::MARKINGS, TRUE)) {
        $unmapped[] = $name;
      }
    }
    return $unmapped;
  }

  /**
   * {@inheritdoc}
   */
  public function loadSourceBatch(int $after_id, int $limit): array {
    return $this->loadBatch($this->sourceEntityType(), $after_id, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function loadSource(int $id): ?ContentEntityInterface {
    return $this->loadOne($this->sourceEntityType(), $id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadTarget(int $id): ?ContentEntityInterface {
    return $this->loadOne($this->targetEntityType(), $id);
  }

  /**
   * {@inheritdoc}
   */
  public function countSource(): int {
    return $this->countType($this->sourceEntityType());
  }

  /**
   * {@inheritdoc}
   */
  public function countTarget(): int {
    return $this->countType($this->targetEntityType());
  }

  /**
   * {@inheritdoc}
   */
  public function targetOccupiedByDifferentUuid(int $id, string $uuid): bool {
    $target = $this->loadTarget($id);
    return $target instanceof ContentEntityInterface && $target->uuid() !== $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function copyToTarget(ContentEntityInterface $source): ContentEntityInterface {
    if ($this->targetOccupiedByDifferentUuid((int) $source->id(), $source->uuid())) {
      throw new MigrateRefusal('identity_conflict');
    }
    $existing = $this->loadTarget((int) $source->id());
    if ($existing instanceof ContentEntityInterface && $existing->uuid() === $source->uuid()) {
      return $existing;
    }
    return $this->writer->insertCopy($source, $this->targetEntityType());
  }

  /**
   * {@inheritdoc}
   */
  public function writeLegacyFromTarget(ContentEntityInterface $target): ContentEntityInterface {
    $legacy = $this->loadSource((int) $target->id());
    if ($legacy instanceof ContentEntityInterface) {
      if ($legacy->uuid() !== $target->uuid()) {
        throw new MigrateRefusal('rollback_conflict');
      }
      return $this->writer->overwriteContent($legacy, $target);
    }
    return $this->writer->insertCopy($target, $this->sourceEntityType());
  }

  /**
   * {@inheritdoc}
   */
  public function canonicalize(ContentEntityInterface $entity): array {
    $values = $this->writer->scalarValues($entity);
    ksort($values);
    $canonical = [];
    foreach ($values as $name => $value) {
      $canonical[$name] = is_scalar($value) || $value === NULL
        ? (string) $value
        : hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
    return $canonical;
  }

  /**
   * {@inheritdoc}
   */
  public function contentDigest(ContentEntityInterface $entity): string {
    return hash('sha256', json_encode($this->canonicalize($entity), JSON_THROW_ON_ERROR));
  }

  /**
   * Loads entities of one type after an integer cursor.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   Entities ordered by id.
   */
  private function loadBatch(string $entity_type, int $after_id, int $limit): array {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($storage->getEntityType()->getKey('id'), $after_id, '>')
      ->sort($storage->getEntityType()->getKey('id'))
      ->range(0, $limit)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $entities = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof ContentEntityInterface) {
        $entities[] = $entity;
      }
    }
    return $entities;
  }

  /**
   * Loads one content entity, bypassing the entity memory cache.
   */
  private function loadOne(string $entity_type, int $id): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Counts rows of one entity type.
   */
  private function countType(string $entity_type): int {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    return (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
  }

}
