<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Refused rollback must preserve data, replay markers and authority evidence.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRollbackPreflightTest extends MigrateKernelTestBase {

  /**
   * Authorization and invalid reasons refuse before any replay effects.
   *
   * @dataProvider refusedRollbackCases
   */
  #[DataProvider('refusedRollbackCases')]
  public function testRefusalPreservesState(bool $run, bool $administer, string $reason, bool $execute, string $refusal): void {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'register');
    $authority->transition('synthetic', 'shadow', 'shadow');
    $authority->transition('synthetic', 'agency', 'cutover');
    $target = $this->target(1);
    $target->set('payload', 'changed after cutover');
    $target->save();
    $this->container->get('agency_migrate.writes')->record(
      'synthetic', 'agency_migrate_agency_item', (int) $target->id(), $target->uuid(),
    );
    $permissions = [];
    if ($run) {
      $permissions[] = 'run agency migrate';
    }
    if ($administer) {
      $permissions[] = 'administer agency migrate authority';
    }
    Role::create([
      'id' => 'rollback_requester',
      'label' => 'Rollback requester',
      'permissions' => $permissions,
    ])->save();
    $account = User::create([
      'name' => 'rollback-requester',
      'status' => 1,
      'roles' => ['rollback_requester'],
    ]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $before = $this->snapshot();
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.rollback')->rollback('synthetic', $reason, $execute),
      $refusal,
    );
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * Refused requests exercise execution and preview using the same policy.
   */
  public static function refusedRollbackCases(): array {
    $cases = [];
    foreach ([FALSE, TRUE] as $execute) {
      $suffix = $execute ? 'execute' : 'preview';
      $cases['missing authority ' . $suffix] = [TRUE, FALSE, 'rehearsal', $execute, 'forbidden'];
      $cases['missing run ' . $suffix] = [FALSE, TRUE, 'rehearsal', $execute, 'forbidden'];
      $cases['empty reason ' . $suffix] = [TRUE, TRUE, '   ', $execute, 'invalid_transition'];
      $cases['oversized reason ' . $suffix] = [TRUE, TRUE, str_repeat('a', 256), $execute, 'invalid_transition'];
    }
    return $cases;
  }

  /**
   * Captures persistent effects independently of the rollback receipt.
   */
  private function snapshot(): array {
    $snapshot = [
      'legacy' => $this->source(1)->toArray(),
      'authority' => $this->container->get('agency_migrate.authority')->status(),
    ];
    foreach (['agency_migrate_write_log', 'agency_migrate_authority_event', 'agency_migrate_outbox'] as $table) {
      $snapshot[$table] = [];
      $result = $this->container->get('database')->select($table, 't')
        ->fields('t')->execute();
      while ($row = $result->fetchAssoc()) {
        $snapshot[$table][] = $row;
      }
    }
    return $snapshot;
  }

}
