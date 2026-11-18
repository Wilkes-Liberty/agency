<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Slice 2 workforce and recruiting rows register at legacy only.
 *
 * Failure cases: missing rows on install, existing rows rewritten, skipped
 * shadow, and creating a Slice 2 domain away from legacy.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateS2AuthorityRowsTest extends MigrateKernelTestBase {

  /**
   * A fresh install records both Slice 2 domains at legacy.
   */
  public function testFreshInstallHasWorkforceAndRecruitingAtLegacy(): void {
    $this->deleteS2Rows();
    $authority = $this->container->get('agency_migrate.authority');
    $this->assertNull($authority->get('workforce'));
    $this->assertNull($authority->get('recruiting'));
    agency_migrate_install();
    $this->assertSame('legacy', $authority->get('workforce'));
    $this->assertSame('legacy', $authority->get('recruiting'));
    $status = $authority->status();
    $this->assertSame('legacy', $status['workforce']['state']);
    $this->assertSame('legacy', $status['recruiting']['state']);
    $this->assertSame(1, $status['workforce']['version']);
    $this->assertSame(1, $status['recruiting']['version']);
    $this->assertSame('legacy', $this->config('agency_migrate.authority')->get('domains.workforce.state'));
    $this->assertSame('legacy', $this->config('agency_migrate.authority')->get('domains.recruiting.state'));
  }

  /**
   * The update adds missing Slice 2 rows once and leaves other domains alone.
   */
  public function testUpdateAddsS2DomainsOnceAndPreservesExistingRows(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'historical cutover fixture');
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $authority->transition('synthetic', 'agency', 'historical cutover');
    $before = $authority->status()['synthetic'];
    $this->deleteS2Rows();
    $this->assertNull($authority->get('workforce'));
    $this->assertNull($authority->get('recruiting'));
    agency_migrate_update_10003();
    $this->assertSame('legacy', $authority->get('workforce'));
    $this->assertSame('legacy', $authority->get('recruiting'));
    $this->assertSame($before, $authority->status()['synthetic']);
    $this->assertSame('agency', $authority->get('synthetic'));
    $first = $authority->status();
    agency_migrate_update_10003();
    $this->assertSame($first, $authority->status());
  }

  /**
   * Slice 2 rows still cannot skip shadow or enter Agency without the fence.
   */
  public function testAdvancingS2RowsUsesExistingRegisterRules(): void {
    agency_migrate_install();
    $authority = $this->container->get('agency_migrate.authority');
    foreach (['workforce', 'recruiting'] as $domain) {
      $this->assertSame('legacy', $authority->get($domain));
      $this->assertRefused(
        fn () => $authority->transition($domain, 'agency', 'skip shadow'),
        'invalid_transition',
      );
      $this->assertSame('legacy', $authority->get($domain));
      $this->assertRefused(
        fn () => $authority->transition($domain, 'removed', 'skip to removed'),
        'invalid_transition',
      );
      $authority->transition($domain, 'shadow', 'begin shadow');
      $this->assertSame('shadow', $authority->get($domain));
      $this->assertRefused(
        fn () => $authority->transition($domain, 'legacy', 'illegal rollback', TRUE),
        'invalid_transition',
      );
      $this->assertSame('shadow', $authority->get($domain));
      $authority->transition($domain, 'agency', 'cutover');
      $this->assertSame('agency', $authority->get($domain));
      $this->assertRefused(
        fn () => $authority->transition($domain, 'legacy', 'unqualified rollback', TRUE),
        'rollback_unqualified',
      );
      $this->assertSame('agency', $authority->get($domain));
    }
  }

  /**
   * The installer refuses to create Slice 2 domains in any other state.
   */
  public function testCreateRefusesNonLegacyState(): void {
    $this->deleteS2Rows();
    $authority = $this->container->get('agency_migrate.authority');
    try {
      agency_migrate_s2_create_state('shadow');
      $this->fail('Slice 2 domains must not be created away from legacy.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame(
        'Slice two domains may only be created at legacy.',
        $exception->getMessage(),
      );
    }
    $this->assertNull($authority->get('workforce'));
    $this->assertNull($authority->get('recruiting'));
    $this->assertSame('legacy', agency_migrate_s2_create_state('legacy'));
  }

  /**
   * Removes Slice 2 operational rows so install and update paths start empty.
   */
  private function deleteS2Rows(): void {
    $this->container->get('database')->delete('agency_migrate_authority_state')
      ->condition('domain', ['workforce', 'recruiting'], 'IN')
      ->execute();
  }

}
