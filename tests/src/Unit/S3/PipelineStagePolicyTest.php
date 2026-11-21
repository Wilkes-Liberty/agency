<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_commercial\PipelineStagePolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ports PipelineTest stage-order denies without records or Views.
 *
 * @group agency
 */
#[Group('agency')]
final class PipelineStagePolicyTest extends TestCase {

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
   * Lost and parked records cannot skip unfinished service stages.
   */
  public function testLostAndParkedCannotSkipStages(): void {
    $policy = new PipelineStagePolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'skip_from_holding'],
      $policy->transition(['from' => 'lost', 'to' => 'sow']),
    );
    $this->assertSame(
      ['decision' => 'allow', 'reason' => NULL],
      $policy->transition(['from' => 'lost', 'to' => 'inbound']),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'skip_from_holding'],
      $policy->transition(['from' => 'parked', 'to' => 'sow']),
    );
    $this->assertSame(
      ['decision' => 'allow', 'reason' => NULL],
      $policy->transition(['from' => 'parked', 'to' => 'discovery']),
    );
  }

  /**
   * Direct saves cannot mark an opportunity won.
   */
  public function testDirectStageBypassDenied(): void {
    $policy = new PipelineStagePolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'direct_stage_bypass'],
      $policy->transition([
        'from' => 'inbound',
        'to' => 'won',
        'direct_save' => TRUE,
      ]),
    );
  }

  /**
   * Stale revision tokens and deletes are refused.
   */
  public function testTransitionAndStaleWrite(): void {
    $policy = new PipelineStagePolicy();
    $this->assertSame(
      ['decision' => 'allow', 'reason' => NULL],
      $policy->transition(['from' => 'inbound', 'to' => 'qualified']),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'stale_revision'],
      $policy->transition([
        'from' => 'inbound',
        'to' => 'qualified',
        'revision_match' => FALSE,
      ]),
    );
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'delete_denied'],
      $policy->transition(['delete' => TRUE]),
    );
  }

  /**
   * Conversion by the wrong owner is denied.
   */
  public function testConversionAndWrongOwnerDenial(): void {
    $policy = new PipelineStagePolicy();
    $this->assertSame(
      ['decision' => 'deny', 'reason' => 'wrong_owner'],
      $policy->transition([
        'from' => 'sow',
        'to' => 'won',
        'owner_match' => FALSE,
      ]),
    );
  }

}
