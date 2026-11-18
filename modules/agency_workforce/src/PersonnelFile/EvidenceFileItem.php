<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

/**
 * A projected file item with its original Drupal field properties.
 */
final readonly class EvidenceFileItem {

  public function __construct(
    public QualifiedFile $file,
    public ?bool $display,
    public ?string $description,
  ) {}

  /**
   * Returns the replay values for the source field.
   *
   * @return array{target_id: int, display: ?bool, description: ?string}
   *   The Drupal file field item.
   */
  public function sourceValues(): array {
    return [
      'target_id' => $this->file->fid,
      'display' => $this->display,
      'description' => $this->description,
    ];
  }

}
