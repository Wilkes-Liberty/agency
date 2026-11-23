<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_intake\Kernel;

use Drupal\agency_intake\Access\DenyAccess;
use Drupal\agency_intake\Entity\IntakeSubmission;
use Drupal\agency_intake\Storage\IdentityPreservingStorage;
use Drupal\agency_intake\IntakeDeliveryWorker;
use Drupal\agency_intake\UnavailableIntakeDeliveryWorker;
use Drupal\agency_intake\UnavailableIntakeOutbox;
use Drupal\agency_intake\UnavailableIntakePolicy;
use Drupal\agency_intake\UnavailableIntakeTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Intake PHP must not call workforce, Discovery, recruiting or CRM writes.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class IntakeSeparationTest extends IntakeKernelTestBase {

  /**
   * Forbidden command class fragments inside Drupal\agency_intake\.
   */
  private const FORBIDDEN = [
    'Drupal\\agency_staff\\RelationshipCommands',
    'Drupal\\agency_staff\\InvitationCommands',
    'Drupal\\agency_staff\\RelationshipReads',
    'Drupal\\agency_staff\\Workforce\\',
    'Drupal\\agency_workforce\\',
    'Drupal\\agency_recruiting\\',
    'Drupal\\agency_commercial\\Entity\\Opportunity',
    'Drupal\\agency_commercial\\Entity\\Activity',
    'createOpportunity',
    'closeOpportunity',
    'recordActivity',
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
      'agency_intake.routing.yml',
      'agency_intake.links.menu.yml',
      'agency_intake.links.task.yml',
      'agency_intake.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
  }

  /**
   * Loaded intake classes have no workforce, Discovery or commercial parents.
   */
  public function testLoadedClassesDoNotExtendCommandTypes(): void {
    $classes = [
      IntakeSubmission::class,
      DenyAccess::class,
      IdentityPreservingStorage::class,
      IntakeDeliveryWorker::class,
      UnavailableIntakeDeliveryWorker::class,
      UnavailableIntakeOutbox::class,
      UnavailableIntakePolicy::class,
      UnavailableIntakeTools::class,
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
      $this->assertStringContainsString('/agency_intake/', $file);
    }
  }

}
