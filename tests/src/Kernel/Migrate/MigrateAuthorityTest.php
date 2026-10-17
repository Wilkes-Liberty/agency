<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Authority moves only forward, except the rehearsed agency-to-legacy rollback.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateAuthorityTest extends MigrateKernelTestBase {

  /**
   * Forward transitions record actor hash and reason; skips are refused.
   */
  public function testForwardTransitionsAndDenials(): void {
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'initial register');
    $this->assertSame('legacy', $authority->get('synthetic'));
    $this->assertRefused(fn () => $authority->transition('synthetic', 'agency', 'skip shadow'), 'invalid_transition');
    $this->assertSame('legacy', $authority->get('synthetic'));
    $authority->transition('synthetic', 'shadow', 'begin shadow');
    $this->assertSame('shadow', $authority->get('synthetic'));
    $authority->transition('synthetic', 'agency', 'cutover');
    $this->assertSame('agency', $authority->get('synthetic'));
    $this->assertRefused(fn () => $authority->transition('synthetic', 'legacy', 'missing rollback flag'), 'invalid_transition');
    $authority->transition('synthetic', 'legacy', 'rehearsal rollback', TRUE);
    $this->assertSame('legacy', $authority->get('synthetic'));
    $authority->transition('synthetic', 'shadow', 'shadow again');
    $authority->transition('synthetic', 'agency', 'cutover again');
    $authority->transition('synthetic', 'removed', 'drain complete');
    $this->assertSame('removed', $authority->get('synthetic'));
    $this->assertRefused(fn () => $authority->transition('synthetic', 'agency', 'from removed'), 'invalid_transition');

    $events = [];
    $result = $this->container->get('database')
      ->select('agency_migrate_authority_event', 'e')
      ->fields('e')
      ->condition('domain', 'synthetic')
      ->orderBy('eid')
      ->execute();
    while ($row = $result->fetchAssoc()) {
      $events[] = $row;
    }
    $this->assertGreaterThanOrEqual(6, count($events));
    foreach ($events as $event) {
      $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event['actor_hash']);
      $this->assertNotSame('', $event['reason']);
    }
  }

  /**
   * Authority changes require the dedicated permission.
   */
  public function testUnauthorizedAuthorityChangeRefuses(): void {
    $this->container->get('agency_migrate.authority')->register('synthetic', 'initial register');
    $stranger = User::create(['name' => 'stranger', 'status' => 1]);
    $stranger->save();
    $this->container->get('current_user')->setAccount($stranger);
    $this->assertRefused(
      fn () => $this->container->get('agency_migrate.authority')->transition('synthetic', 'shadow', 'no permission'),
      'forbidden',
    );
    $this->assertSame('legacy', $this->container->get('agency_migrate.authority')->get('synthetic'));
  }

}
