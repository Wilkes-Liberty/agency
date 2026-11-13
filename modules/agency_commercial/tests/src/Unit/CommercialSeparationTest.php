<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Commercial PHP must not call Discovery, workforce or recruiting classes.
 *
 * @group agency
 */
#[Group('agency')]
final class CommercialSeparationTest extends TestCase {

  /**
   * Forbidden class fragments inside Drupal\agency_commercial\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_workforce\\',
    'Drupal\\agency_recruiting\\',
  ];

  /**
   * Source files in the submodule do not reference those classes.
   */
  public function testSourceDoesNotCrossSeparationWall(): void {
    $root = dirname(__DIR__, 3);
    $this->assertDirectoryExists($root . '/src');
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root),
    );
    $scanned = 0;
    foreach ($iterator as $file) {
      if (!$file->isFile()) {
        continue;
      }
      $path = $file->getPathname();
      if (!preg_match('/\\.(php|module|install|yml)$/', $path)) {
        continue;
      }
      if (str_contains($path, '/tests/')) {
        continue;
      }
      $source = file_get_contents($path);
      $this->assertNotFalse($source, $path);
      $scanned++;
      foreach (self::FORBIDDEN as $needle) {
        $this->assertStringNotContainsString(
          $needle,
          $source,
          $path . ' must not reference ' . $needle,
        );
      }
    }
    $this->assertGreaterThan(0, $scanned);
  }

}
