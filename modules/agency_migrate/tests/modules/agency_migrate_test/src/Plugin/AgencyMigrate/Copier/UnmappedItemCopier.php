<?php

declare(strict_types=1);

namespace Drupal\agency_migrate_test\Plugin\AgencyMigrate\Copier;

use Drupal\agency_migrate\Attribute\MigrateCopier;
use Drupal\agency_migrate\IdentityPreservingWriter;
use Drupal\agency_migrate\Plugin\EntityCopierBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Synthetic copier that omits a required marking.
 *
 * @\Drupal\agency_migrate\Annotation\MigrateCopier(
 *   id = "synthetic_item_unmapped",
 *   label = @Translation("Synthetic item with unmapped marking"),
 *   source_type = "agency_migrate_legacy_item",
 *   target_type = "agency_migrate_agency_item"
 * )
 */
#[MigrateCopier(
  id: 'synthetic_item_unmapped',
  label: new TranslatableMarkup('Synthetic item with unmapped marking'),
  source_type: 'agency_migrate_legacy_item',
  target_type: 'agency_migrate_agency_item',
)]
final class UnmappedItemCopier extends EntityCopierBase implements ContainerFactoryPluginInterface {

  use SyntheticCopierTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    IdentityPreservingWriter $writer,
    protected readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entityTypeManager, $database, $writer);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('agency_migrate.identity_writer'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function propertyMarkings(): array {
    return [
      'title' => 'public',
      'related_id' => 'internal',
      'access_class' => 'confidential',
    ];
  }

}
