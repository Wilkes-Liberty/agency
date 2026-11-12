<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a migrate copier plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MigrateCopier extends Plugin {

  /**
   * Constructs an explicitly scoped copier definition.
   *
   * @param string $id
   *   The plugin identifier.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The display label.
   * @param string $source_type
   *   The source entity type identifier.
   * @param string $target_type
   *   The target entity type identifier.
   * @param list<string> $domains
   *   Declared authority domains. Empty definitions cannot qualify rollback.
   */
  public function __construct(
    string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $source_type,
    public readonly string $target_type,
    public readonly array $domains = [],
  ) {
    parent::__construct($id);
  }

}
