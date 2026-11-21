<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_expense\Kernel;

use Drupal\agency_expense\Access\DenyAccess;
use Drupal\agency_expense\ExpenseOutbox;
use Drupal\agency_expense\Entity\Expense;
use Drupal\agency_expense\RecordExpense;
use Drupal\agency_expense\Storage\IdentityPreservingStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Expense PHP must not call workforce, recruiting or Discovery commands.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class ExpenseSeparationTest extends ExpenseKernelTestBase {

  /**
   * Forbidden command class fragments inside Drupal\agency_expense\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_workforce\\',
    'Drupal\\agency_recruiting\\',
    'Drupal\\agency_commercial\\',
    'Drupal\\agency_intake\\',
    'Drupal\\agency_engagement\\',
  ];

  /**
   * Source files in the submodule do not reference those command classes.
   */
  public function testSourceDoesNotReferenceWorkforceOrDiscoveryCommands(
  ): void {
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
      'agency_expense.routing.yml',
      'agency_expense.links.menu.yml',
      'agency_expense.links.task.yml',
      'agency_expense.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
  }

  /**
   * Loaded expense classes have no workforce or Discovery parents.
   */
  public function testLoadedClassesDoNotExtendCommandTypes(): void {
    $classes = [
      Expense::class,
      DenyAccess::class,
      IdentityPreservingStorage::class,
      RecordExpense::class,
      ExpenseOutbox::class,
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
      $this->assertStringContainsString('/agency_expense/', $file);
    }
  }

}
