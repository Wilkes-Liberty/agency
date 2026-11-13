<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Engagement PHP must not reference Discovery, workforce or later slices.
 *
 * @group agency
 */
#[Group('agency')]
final class EngagementSeparationTest extends TestCase {

  /**
   * Forbidden class and module fragments inside Drupal\agency_engagement\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_staff\\',
    'Drupal\\agency_workforce\\',
    'Drupal\\agency_recruiting\\',
    'Drupal\\agency_commercial\\',
    'Drupal\\agency_intake\\',
    'agency_entitlement',
  ];

  /**
   * Source files in the submodule do not reference those classes.
   */
  public function testSourceDoesNotReferenceForbiddenClasses(): void {
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
    foreach ([
      'agency_engagement.routing.yml',
      'agency_engagement.permissions.yml',
      'agency_engagement.links.menu.yml',
      'agency_engagement.links.task.yml',
      'agency_engagement.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
    $this->assertDirectoryDoesNotExist($root . '/config');
    $this->assertDirectoryDoesNotExist($root . '/src/Entity');
  }

}
