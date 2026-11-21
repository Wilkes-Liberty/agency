<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_campaign\Kernel;

use Drupal\agency_campaign\Access\DenyAccess;
use Drupal\agency_campaign\CampaignOutbox;
use Drupal\agency_campaign\Entity\Campaign;
use Drupal\agency_campaign\LaunchCampaign;
use Drupal\agency_campaign\Storage\IdentityPreservingStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Campaign PHP must not call workforce, recruiting or Discovery commands.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class CampaignSeparationTest extends CampaignKernelTestBase {

  /**
   * Forbidden command class fragments inside Drupal\agency_campaign\.
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
      'agency_campaign.routing.yml',
      'agency_campaign.links.menu.yml',
      'agency_campaign.links.task.yml',
      'agency_campaign.links.action.yml',
    ] as $ui_file) {
      $this->assertFileDoesNotExist($root . '/' . $ui_file);
    }
  }

  /**
   * Loaded campaign classes have no workforce or Discovery parents.
   */
  public function testLoadedClassesDoNotExtendCommandTypes(): void {
    $classes = [
      Campaign::class,
      DenyAccess::class,
      IdentityPreservingStorage::class,
      LaunchCampaign::class,
      CampaignOutbox::class,
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
      $this->assertStringContainsString('/agency_campaign/', $file);
    }
  }

}
