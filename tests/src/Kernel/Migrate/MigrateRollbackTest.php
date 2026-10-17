<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rollback replays post-cutover Agency writes without a database restore.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRollbackTest extends MigrateKernelTestBase {

  /**
   * After rollback, legacy content matches Agency and authority is legacy.
   */
  public function testRollbackReplaysPostCutoverWrites(): void {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $authority->transition('synthetic', 'agency', 'cutover');

    $target = $this->target(1);
    $target->set('payload', 'post-cutover');
    $target->save();
    $this->container->get('agency_migrate.writes')->record(
      'synthetic',
      'agency_migrate_agency_item',
      (int) $target->id(),
      $target->uuid(),
    );

    $this->assertSame('body-a', (string) $this->source(1)->get('payload')->value);
    $receipt = $this->container->get('agency_migrate.rollback')->rollback('synthetic', 'rehearsal', TRUE);
    $this->assertSame('completed', $receipt['status']);
    $this->assertSame(1, $receipt['replayed']);
    $this->assertSame('legacy', $receipt['authority']);
    $this->assertSame('legacy', $authority->get('synthetic'));
    $legacy = $this->source(1);
    $agency = $this->target(1);
    $this->assertSame((int) $agency->id(), (int) $legacy->id());
    $this->assertSame($agency->uuid(), $legacy->uuid());
    $this->assertSame((string) $agency->get('payload')->value, (string) $legacy->get('payload')->value);
    $this->assertSame('post-cutover', (string) $legacy->get('payload')->value);
    $this->assertSame((string) $agency->get('title')->value, (string) $legacy->get('title')->value);
    $this->assertSame((string) $agency->get('access_class')->value, (string) $legacy->get('access_class')->value);
  }

  /**
   * Rollback from a non-agency state is refused.
   */
  public function testRollbackFromLegacyRefuses(): void {
    $this->container->get('agency_migrate.authority')->register('synthetic', 'initial register');
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.rollback')->rollback('synthetic', 'too early', TRUE),
      'invalid_transition',
    );
  }

  /**
   * Dry-run rollback does not replay writes or change authority.
   */
  public function testDryRunRollbackWritesNothing(): void {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $authority->transition('synthetic', 'agency', 'cutover');
    $target = $this->target(1);
    $target->set('payload', 'post-cutover');
    $target->save();
    $this->container->get('agency_migrate.writes')->record(
      'synthetic',
      'agency_migrate_agency_item',
      (int) $target->id(),
      $target->uuid(),
    );
    $plan = $this->container->get('agency_migrate.rollback')->rollback('synthetic', 'rehearsal', FALSE);
    $this->assertSame('dry_run', $plan['status']);
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertSame('body-a', (string) $this->source(1)->get('payload')->value);
  }

}
