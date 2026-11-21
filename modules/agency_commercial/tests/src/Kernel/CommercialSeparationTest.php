<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_commercial\Kernel;

use Drupal\agency_commercial\Access\DenyAccess;
use Drupal\agency_commercial\Entity\Activity;
use Drupal\agency_commercial\Entity\Opportunity;
use Drupal\agency_commercial\Storage\IdentityPreservingStorage;
use Drupal\agency_commercial\UnavailableCommercialRecords;
use Drupal\agency_commercial\UnavailableContactMatch;
use Drupal\agency_commercial\UnavailableOperatingRuns;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Commercial PHP must not call workforce, Discovery, recruiting or intake.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CommercialSeparationTest extends CommercialKernelTestBase {

  /**
   * Forbidden command class fragments inside Drupal\agency_commercial\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_staff\\Workforce\\',
    'Drupal\\agency_workforce\\',
    'Drupal\\agency_recruiting\\',
    'Drupal\\agency_intake\\',
    'submitIntake',
  ];

  /**
   * Source files in the submodule do not reference those command classes.
   */
  public function testSourceDoesNotReferenceWorkforceOrDiscoveryCommands(): void {
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
      'agency_commercial.routing.yml',
      'agency_commercial.links.menu.yml',
      'agency_commercial.links.task.yml',
      'agency_commercial.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
  }

  /**
   * Loaded commercial classes have no workforce, Discovery or intake parents.
   */
  public function testLoadedClassesDoNotExtendCommandTypes(): void {
    $classes = [
      Opportunity::class,
      Activity::class,
      DenyAccess::class,
      IdentityPreservingStorage::class,
      UnavailableCommercialRecords::class,
      UnavailableContactMatch::class,
      UnavailableOperatingRuns::class,
    ];
    foreach ($classes as $class) {
      $reflection = new \ReflectionClass($class);
      $parents = class_parents($class) ?: [];
      foreach ($parents as $parent) {
        foreach (self::FORBIDDEN as $needle) {
          $this->assertStringNotContainsString($needle, $parent, $class);
        }
      }
      $file = $reflection->getFileName();
      $this->assertNotFalse($file);
      $this->assertStringContainsString('/agency_commercial/', $file);
    }
  }

}
