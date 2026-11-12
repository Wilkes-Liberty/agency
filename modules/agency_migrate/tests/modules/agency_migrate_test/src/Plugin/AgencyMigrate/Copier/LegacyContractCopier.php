<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Plugin\AgencyMigrate\Copier;

use Drupal\agency_migrate\Attribute\MigrateCopier;
use Drupal\agency_migrate\Plugin\CopierInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Models an existing adapter that has not implemented history qualification.
 *
 * @\Drupal\agency_migrate\Annotation\MigrateCopier(
 *   id = "legacy_contract",
 *   label = @Translation("Legacy contract adapter"),
 *   source_type = "agency_migrate_legacy_item",
 *   target_type = "agency_migrate_fallback_item"
 * )
 */
#[MigrateCopier(
  id: 'legacy_contract',
  label: new TranslatableMarkup('Legacy contract adapter'),
  source_type: 'agency_migrate_legacy_item',
  target_type: 'agency_migrate_fallback_item',
)]
final class LegacyContractCopier extends PluginBase implements CopierInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly CopierInterface $delegate,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.agency_migrate.copier')->createInstance('synthetic_item'));
  }

  /**
   * {@inheritdoc}
   */
  public function sourceEntityType(): string {
    return $this->delegate->sourceEntityType();
  }

  /**
   * {@inheritdoc}
   */
  public function targetEntityType(): string {
    return 'agency_migrate_fallback_item';
  }

  /**
   * {@inheritdoc}
   */
  public function propertyMarkings(): array {
    return $this->delegate->propertyMarkings();
  }

  /**
   * {@inheritdoc}
   */
  public function unmappedProperties(): array {
    return $this->delegate->unmappedProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function referencePropertyNames(): array {
    return $this->delegate->referencePropertyNames();
  }

  /**
   * {@inheritdoc}
   */
  public function loadSourceBatch(int $after_id, int $limit): array {
    return $this->delegate->loadSourceBatch($after_id, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function loadSource(int $id): ?ContentEntityInterface {
    return $this->delegate->loadSource($id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadTarget(int $id): ?ContentEntityInterface {
    return $this->delegate->loadTarget($id);
  }

  /**
   * {@inheritdoc}
   */
  public function countSource(): int {
    return $this->delegate->countSource();
  }

  /**
   * {@inheritdoc}
   */
  public function countTarget(): int {
    return $this->delegate->countTarget();
  }

  /**
   * {@inheritdoc}
   */
  public function targetOccupiedByDifferentUuid(int $id, string $uuid): bool {
    return $this->delegate->targetOccupiedByDifferentUuid($id, $uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function copyToTarget(ContentEntityInterface $source): ContentEntityInterface {
    return $this->delegate->copyToTarget($source);
  }

  /**
   * {@inheritdoc}
   */
  public function writeLegacyFromTarget(ContentEntityInterface $target): ContentEntityInterface {
    return $this->delegate->writeLegacyFromTarget($target);
  }

  /**
   * {@inheritdoc}
   */
  public function accessDecision(string $principal, string $side, int $id): bool {
    return $this->delegate->accessDecision($principal, $side, $id);
  }

  /**
   * {@inheritdoc}
   */
  public function canonicalize(ContentEntityInterface $entity): array {
    return $this->delegate->canonicalize($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function contentDigest(ContentEntityInterface $entity): string {
    return $this->delegate->contentDigest($entity);
  }

}
