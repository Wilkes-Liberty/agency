<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_recruiting\Kernel;

use Drupal\agency_recruiting\Access\DenyAccess;
use Drupal\agency_recruiting\Entity\Application;
use Drupal\agency_recruiting\Entity\CareerPosting;
use Drupal\agency_recruiting\Plugin\Validation\Constraint\PublishedCloseDateConstraint;
use Drupal\agency_recruiting\Plugin\Validation\Constraint\PublishedCloseDateConstraintValidator;
use Drupal\agency_recruiting\Storage\IdentityPreservingStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Recruiting PHP must not call workforce or Discovery command classes.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class RecruitingSeparationTest extends RecruitingKernelTestBase {

  /**
   * Forbidden command class fragments inside Drupal\agency_recruiting\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_staff\\Workforce\\',
    'Drupal\\agency_workforce\\',
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
      'agency_recruiting.routing.yml',
      'agency_recruiting.links.menu.yml',
      'agency_recruiting.links.task.yml',
      'agency_recruiting.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
  }

  /**
   * Loaded recruiting classes have no workforce or Discovery parents.
   */
  public function testLoadedClassesDoNotExtendCommandTypes(): void {
    $classes = [
      CareerPosting::class,
      Application::class,
      DenyAccess::class,
      IdentityPreservingStorage::class,
      PublishedCloseDateConstraint::class,
      PublishedCloseDateConstraintValidator::class,
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
      $this->assertStringContainsString('/agency_recruiting/', $file);
    }
  }

}
