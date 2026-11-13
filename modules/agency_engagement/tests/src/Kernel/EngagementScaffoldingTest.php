<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Kernel;

use Drupal\agency_engagement\Exception\EngagementRefusal;
use Drupal\agency_engagement\ReviewPathPolicy;
use Drupal\agency_engagement\UnavailableEngagementPorts;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves scaffolding installs nothing and refusing ports stay closed.
 *
 * @group agency
 *
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class EngagementScaffoldingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'agency',
    'agency_engagement',
  ];

  /**
   * Proposed Slice 4 types that must stay unregistered.
   */
  private const UNREGISTERED_TYPES = [
    'agency_engagement',
    'agency_assignment',
    'agency_time_entry',
    'agency_time_session',
    'agency_time_submission',
    'agency_statement',
  ];

  /**
   * Enabling the module creates no types, tables or granting permissions.
   */
  public function testInstallCreatesNothing(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('agency'));
    $this->assertTrue($handler->moduleExists('agency_engagement'));
    $definitions = $this->container
      ->get('entity_type.manager')
      ->getDefinitions();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertArrayNotHasKey($type, $definitions);
    }
    $schema = $this->container->get('database')->schema();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertFalse($schema->tableExists($type));
      $this->assertFalse($schema->tableExists($type . '_revision'));
    }
    foreach ($this->container->getDefinitions() as $id => $definition) {
      $class = $definition->getClass();
      if ($class === ReviewPathPolicy::class) {
        $this->fail($id . ' must not register ReviewPathPolicy.');
      }
    }
  }

  /**
   * Registered ports refuse and share the unavailable implementation.
   */
  public function testPortsRefuse(): void {
    $ids = [
      'agency_engagement.engagement_assignment_reader',
      'agency_engagement.time_submission_review',
      'agency_engagement.statement_snapshot_reader',
    ];
    foreach ($ids as $id) {
      $this->assertTrue($this->container->has($id));
      $port = $this->container->get($id);
      $this->assertSame(
        UnavailableEngagementPorts::class,
        get_class($port),
      );
    }
    $reader = $this->container->get(
      'agency_engagement.engagement_assignment_reader',
    );
    try {
      $reader->readEngagementAssignments('engagement-1');
      $this->fail('Engagement reads must refuse.');
    }
    catch (EngagementRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }
    $review = $this->container->get(
      'agency_engagement.time_submission_review',
    );
    try {
      $review->reviewTimeSubmission('submission-1');
      $this->fail('Time-submission review must refuse.');
    }
    catch (EngagementRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }
    $snapshot = $this->container->get(
      'agency_engagement.statement_snapshot_reader',
    );
    try {
      $snapshot->readStatementSnapshot('snapshot-1');
      $this->fail('Statement-snapshot reads must refuse.');
    }
    catch (EngagementRefusal $exception) {
      $this->assertSame('unavailable', $exception->reason);
    }
  }

  /**
   * An empty install can be removed and leaves no Slice 4 types.
   */
  public function testEmptyModuleCanBeUninstalled(): void {
    $this->container->get('module_installer')->uninstall(['agency_engagement']);
    $this->assertFalse(
      $this->container
        ->get('module_handler')
        ->moduleExists('agency_engagement'),
    );
    $definitions = $this->container
      ->get('entity_type.manager')
      ->getDefinitions();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertArrayNotHasKey($type, $definitions);
    }
    $schema = $this->container->get('database')->schema();
    foreach (self::UNREGISTERED_TYPES as $type) {
      $this->assertFalse($schema->tableExists($type));
    }
    $this->assertFalse(
      $this->container->has('agency_engagement.engagement_assignment_reader'),
    );
    $this->assertFalse(
      $this->container->has('agency_engagement.time_submission_review'),
    );
    $this->assertFalse(
      $this->container->has('agency_engagement.statement_snapshot_reader'),
    );
  }

}
