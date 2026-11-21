<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/RecordingRouteCache.php';

use Drupal\agency_commercial\CommercialRoutes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Commercial route eligibility is route-name only.
 *
 * @group agency
 */
#[Group('agency')]
final class CommercialRoutesTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_commercial\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_commercial/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Opportunity, activity and agency_commercial routes stay eligible.
   */
  public function testAppliesMatchesCommercialRoutesOnly(): void {
    $this->assertTrue(
      CommercialRoutes::applies('entity.agency_opportunity.canonical'),
    );
    $this->assertTrue(
      CommercialRoutes::applies('entity.agency_opportunity.edit_form'),
    );
    $this->assertTrue(
      CommercialRoutes::applies('entity.agency_activity.canonical'),
    );
    $this->assertTrue(
      CommercialRoutes::applies('agency_commercial.settings'),
    );
    $this->assertFalse(CommercialRoutes::applies('system.admin'));
    $this->assertFalse(CommercialRoutes::applies('entity.node.canonical'));
    $this->assertFalse(CommercialRoutes::applies('wl_crm.dashboard'));
    $this->assertFalse(
      CommercialRoutes::applies('entity.crm_opportunity.canonical'),
    );
    $this->assertFalse(CommercialRoutes::applies('view.crm_pipeline.page'));
  }

  /**
   * Optional cache metadata records the route on both a hit and a miss.
   */
  public function testAppliesRecordsRouteCacheMetadata(): void {
    $hit = new RecordingRouteCache();
    $this->assertTrue(
      CommercialRoutes::applies('entity.agency_opportunity.canonical', $hit),
    );
    $this->assertSame(['route'], $hit->contexts);
    $miss = new RecordingRouteCache();
    $this->assertFalse(CommercialRoutes::applies('system.admin', $miss));
    $this->assertSame(['route'], $miss->contexts);
  }

}
