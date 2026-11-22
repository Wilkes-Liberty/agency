<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A8: Agency engagement package omits tracker, gateway and tenant identifiers.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyEngagementVendorNeutralPackageTest extends TestCase {

  /**
   * Identifiers that must not appear in product source.
   */
  private const FORBIDDEN = [
    'tracker_url',
    'tracker_project_ref',
    'tracker_item_ref',
    'JsmServiceRequestGateway',
    'stripe.com',
    'api.stripe',
    'resourcespace',
    'wilkesliberty.com',
    'wilkesliberty.ddev',
  ];

  /**
   * Source and metadata omit tracker URL, project ref, token and gateways.
   */
  public function testPackageOmitsTrackerAndGatewayIdentifiers(): void {
    $root = dirname(__DIR__, 3);
    $this->assertDirectoryExists($root . '/src');
    $scanned = 0;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root),
    );
    foreach ($iterator as $file) {
      if (!$file->isFile()) {
        continue;
      }
      $path = $file->getPathname();
      if (str_contains($path, '/tests/')) {
        continue;
      }
      if (!preg_match('/\\.(php|module|install|yml|md)$/', $path)) {
        continue;
      }
      $source = file_get_contents($path);
      $this->assertNotFalse($source, $path);
      $scanned++;
      foreach (self::FORBIDDEN as $needle) {
        $this->assertStringNotContainsString(
          $needle,
          $source,
          $path . ' must not contain ' . $needle,
        );
      }
    }
    $this->assertGreaterThan(0, $scanned);
  }

}
