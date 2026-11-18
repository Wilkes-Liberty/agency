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
    $domain = match ($entry) {
      'uppercase domain' => 'SYNTHETIC',
      'accent domain' => 'synthétic',
      'status' => '',
      default => 'synthetic',
    };
    $database = $this->container->get('database');
    if ($entry === 'unavailable authority') {
      $database->schema()->renameTable('agency_migrate_authority_state', 'agency_migrate_authority_unavailable');
    }
    $this->assertRefused(
      fn () => $entry === 'service'
        ? $this->container->get('agency_migrate.rollback')->rollback('synthetic', $reason, $execute, $generation, TRUE)
        : $this->container->get('agency_migrate.commands')->authority([
          'domain' => $domain,
          'set' => $entry === 'status' ? '' : ($entry === 'transition' ? 'removed' : 'legacy'),
          'register' => $entry === 'register',
          'rollback' => TRUE,
          'restore-from-backup' => TRUE,
          'reason' => $reason,
          'generation' => $generation,
          'execute' => $execute,
        ]),
      'restore_refused',
    );
    if ($entry === 'unavailable authority') {
      $database->schema()->renameTable('agency_migrate_authority_unavailable', 'agency_migrate_authority_state');
    }
    $this->assertSame($before, $authority->status());
    $this->assertSame('body-a', $this->source(1)->get('payload')->value);
    $this->assertSame('body-a', $this->target(1)->get('payload')->value);
    $this->assertSame(0, (int) $database->select('agency_migrate_rollback_receipt', 'r')->countQuery()->execute()->fetchField());
    $rows = $database->select('agency_migrate_outbox', 'o')->fields('o')
      ->condition('operation', 'restore_refused')->execute()->fetchAll();
    $this->assertCount(1, $rows);
    $metadata = json_decode($rows[0]->metadata, TRUE, 16, JSON_THROW_ON_ERROR);
    $keys = array_keys($metadata);
    sort($keys);
    $expected_keys = ['effective_at', 'event_id', 'reason'];
    $unverified = in_array($entry, ['status', 'unavailable authority', 'uppercase domain', 'accent domain'], TRUE);
    if (!$unverified) {
      array_unshift($expected_keys, 'domain');
    }
    $this->assertSame($expected_keys, $keys);
    if (!$unverified) {
      $this->assertSame('synthetic', $metadata['domain']);
    }
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
   * Untrusted input, ambient transactions and broken evidence cannot leak data.
   */
  public function testRestoreFailureBoundaries(): void {
    $rollback = $this->container->get('agency_migrate.rollback');
    $database = $this->container->get('database');
    $private = 'unknown-private-record-or-backup-location';
    $this->container->get('current_user')->setAccount($this->author);
    $this->assertRefused(fn () => $rollback->rollback($private, $private, TRUE, NULL, TRUE), 'forbidden');
    $this->assertSame(0, (int) $database->select('agency_migrate_outbox', 'o')->countQuery()->execute()->fetchField());
    $this->container->get('current_user')->setAccount($this->operator);
    $transaction = $database->startTransaction();
    $this->assertRefused(fn () => $rollback->rollback($private, $private, FALSE, NULL, TRUE), 'writer_transaction_owned');
    $this->assertSame(0, (int) $database->select('agency_migrate_outbox', 'o')->countQuery()->execute()->fetchField());
    $transaction->rollBack();
    unset($transaction);
    $this->assertRefused(fn () => $rollback->rollback($private, $private, TRUE, NULL, TRUE), 'restore_refused');
    $rows = $database->select('agency_migrate_outbox', 'o')->fields('o')->execute()->fetchAll();
    $this->assertCount(1, $rows);
    $this->assertStringNotContainsString($private, json_encode($rows, JSON_THROW_ON_ERROR));
    $this->assertArrayNotHasKey('domain', json_decode($rows[0]->metadata, TRUE, 16, JSON_THROW_ON_ERROR));
    $database->schema()->dropTable('agency_migrate_outbox');
    $this->assertRefused(fn () => $rollback->rollback($private, $private, TRUE, NULL, TRUE), 'rollback_failed');
    $this->assertSame([], $this->container->get('agency_migrate.authority')->status());
    $this->assertSame(0, (int) $database->select('agency_migrate_rollback_receipt', 'r')->countQuery()->execute()->fetchField());
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
      'command status' => ['status', FALSE],
      'command register' => ['register', TRUE],
      'command transition' => ['transition', TRUE],
      'unavailable authority' => ['unavailable authority', TRUE],
      'uppercase domain' => ['uppercase domain', TRUE],
      'accent domain' => ['accent domain', TRUE],
    ];
  }

}
