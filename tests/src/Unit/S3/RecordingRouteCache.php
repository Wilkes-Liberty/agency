<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

/**
 * Records cache contexts without Drupal cache metadata.
 */
final class RecordingRouteCache {

  /**
   * Recorded cache contexts.
   *
   * @var list<string>
   */
  public array $contexts = [];

  /**
   * Records cache contexts.
   *
   * @param list<string> $contexts
   *   Context names.
   */
  public function addCacheContexts(array $contexts): void {
    foreach ($contexts as $context) {
      $this->contexts[] = $context;
    }
  }

}
