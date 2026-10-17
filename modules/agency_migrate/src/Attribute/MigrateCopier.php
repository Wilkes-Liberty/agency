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

  public function __construct(
    string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $source_type,
    public readonly string $target_type,
  ) {
    parent::__construct($id);
  }

}
