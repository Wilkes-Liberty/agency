<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a migrate copier plugin annotation.
 *
 * Plugin namespace: Plugin\AgencyMigrate\Copier.
 *
 * @Annotation
 */
final class MigrateCopier extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

  /**
   * Source entity type ID.
   *
   * @var string
   */
  public $source_type;

  /**
   * Target entity type ID.
   *
   * @var string
   */
  public $target_type;

}
