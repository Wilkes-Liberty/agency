<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Backup restore intent must never invoke replay or change authority.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRestoreRefusalTest extends MigrateKernelTestBase {

  /**
   * The service and command reject restore with durable minimized evidence.
   *
   * @dataProvider entryPoints
   */
  #[DataProvider('entryPoints')]
  public function testRestoreIntentIsRefused(string $entry, bool $execute): void {
    $this->seedSources();
    $this->copyAll();
    $authority = $this->container->get('agency_migrate.authority');
    $authority->register('synthetic', 'Register test records');
    $authority->transition('synthetic', 'shadow', 'Test shadow');
    $authority->transition('synthetic', 'agency', 'Test cutover');
    $before = $authority->status();
    $reason = 'private-operator-reason-must-not-be-logged';
    $generation = $before['synthetic']['version'];
    $this->assertRefused(
      fn () => $entry === 'service'
        ? $this->container->get('agency_migrate.rollback')->rollback('synthetic', $reason, $execute, $generation, TRUE)
        : $this->container->get('agency_migrate.commands')->authority([
          'domain' => 'synthetic',
          'set' => 'legacy',
          'rollback' => TRUE,
          'restore-from-backup' => TRUE,
          'reason' => $reason,
          'generation' => $generation,
          'execute' => $execute,
        ]),
      'restore_refused',
    );
    $this->assertSame($before, $authority->status());
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
    $this->assertSame('body-a', $this->target(1)->get('payload')->value);
    $database = $this->container->get('database');
    $this->assertSame(0, (int) $database->select('agency_migrate_rollback_receipt', 'r')->countQuery()->execute()->fetchField());
    $rows = $database->select('agency_migrate_outbox', 'o')->fields('o')
      ->condition('operation', 'restore_refused')->execute()->fetchAll();
    $this->assertCount(1, $rows);
    $metadata = json_decode($rows[0]->metadata, TRUE, 16, JSON_THROW_ON_ERROR);
    $keys = array_keys($metadata);
    sort($keys);
    $this->assertSame(['domain', 'effective_at', 'event_id', 'reason'], $keys);
    $this->assertSame('synthetic', $metadata['domain']);
    $this->assertSame('restore_refused', $metadata['reason']);
    $this->assertStringNotContainsString($reason, json_encode($rows, JSON_THROW_ON_ERROR));

    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $database->insert('audit_chain_mutex')->fields(['id' => 1, 'locked' => 1])->execute();
    Key::create([
      'id' => 'restore_refusal_test',
      'label' => 'Synthetic refusal audit key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-restore-refusal-key'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'restore_refusal_test')->save();
    $outbox = $this->container->get('agency_migrate.outbox');
    $this->assertGreaterThan(0, $outbox->drain(100));
    $this->assertSame(0, $outbox->drain(100));
    $audit = $database->select('audit_chain_log', 'l')->fields('l')
      ->condition('channel', 'agency_migrate')->condition('operation', 'restore_refused')->execute()->fetchAll();
    $this->assertCount(1, $audit);
    $this->assertSame($metadata, json_decode($audit[0]->metadata, TRUE, 16, JSON_THROW_ON_ERROR));
    $this->assertSame('restore_refusal_test', $audit[0]->key_id);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
    $this->assertStringNotContainsString($reason, json_encode($audit, JSON_THROW_ON_ERROR));
  }

  /**
   * Every operator entry point refuses even a previewed restore request.
   */
  public static function entryPoints(): array {
    return [
      'service preview' => ['service', FALSE],
      'service execute' => ['service', TRUE],
      'command preview' => ['command', FALSE],
      'command execute' => ['command', TRUE],
    ];
  }

}
