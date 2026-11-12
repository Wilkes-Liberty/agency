<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Legacy journals remain available but cannot qualify operation replay.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRollbackTest extends MigrateKernelTestBase {

  /**
   * Unqualified post-cutover writes never rewrite existing legacy history.
   */
  public function testRollbackRefusesUnqualifiedPostCutoverWrites(): void {
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
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.rollback')->rollback('synthetic', 'rehearsal', TRUE),
      'rollback_unqualified',
    );
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertSame('body-a', (string) $this->source(1)->get('payload')->value);
    $this->assertCount(1, $this->container->get('agency_migrate.writes')->pending('synthetic'));
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
  public function testDryRunRejectsUnqualifiedJournalWithoutWrites(): void {
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
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.rollback')->rollback('synthetic', 'rehearsal', FALSE),
      'rollback_unqualified',
    );
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertSame('body-a', (string) $this->source(1)->get('payload')->value);
  }

}
