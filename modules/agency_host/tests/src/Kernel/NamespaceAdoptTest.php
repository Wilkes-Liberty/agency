<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Copies former chronicle_agency storage without dropping the source.
 *
 * @group agency_host
 */
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class NamespaceAdoptTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'options', 'path_alias', 'field_guard',
    'key', 'encrypt', 'audit_chain', 'externalauth', 'consumers',
    'serialization', 'simple_oauth', 'openid_connect',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('module_installer')->install(['agency', 'agency_staff', 'agency_host'], FALSE);
    $this->container = \Drupal::getContainer();
  }

  /**
   * Adoption copies rows, config, state and queue items and leaves sources.
   */
  public function testAdoptCopiesWithoutDroppingSource(): void {
    require_once dirname(__DIR__, 3) . '/agency_host.install';
    $database = $this->container->get('database');
    $schema = $database->schema();
    $source = 'chronicle_agency_binding_history';
    if (!$schema->tableExists($source)) {
      $definition = agency_host_schema()['agency_host_binding_history'];
      $schema->createTable($source, $definition);
    }
    $database->delete('agency_host_binding_history')->execute();
    $database->insert($source)->fields([
      'id' => str_repeat('a', 64),
      'scope' => str_repeat('b', 64),
      'revoked' => 0,
      'observed_at' => 1,
      'changed_at' => 2,
    ])->execute();
    // Former chronicle_agency config has no schema in this package.
    $this->container->get('config.storage')->write('chronicle_agency.settings', [
      'enabled' => TRUE,
      'bff_client_id' => 'copied-bff',
    ]);
    $this->container->get('config.storage')->write('chronicle_agency.identity_tokens', [
      'paladin_issuer' => 'https://identity.example.test/realms/copied',
      'paladin_audience' => 'copied-audience',
    ]);
    $this->container->get('config.factory')->reset();
    $this->container->get('state')->set('chronicle_agency.evidence_alert_status', 'degraded');
    $schema = $database->schema();
    if ($schema->tableExists('queue')) {
      $database->insert('queue')->fields([
        'name' => 'chronicle_agency.evidence',
        'data' => 'synthetic-item',
        'expire' => 0,
        'created' => 1,
      ])->execute();
    }

    agency_host_adopt_chronicle_agency_namespace();

    $this->assertTrue($schema->tableExists($source));
    $this->assertSame(1, (int) $database->select($source, 's')->countQuery()->execute()->fetchField());
    $copied = $database->select('agency_host_binding_history', 'h')->fields('h')->execute()->fetchAssoc();
    $this->assertSame(str_repeat('a', 64), $copied['id']);
    $this->assertSame('copied-bff', $this->config('agency_host.settings')->get('bff_client_id'));
    $this->assertSame('https://identity.example.test/realms/copied', $this->config('agency_host.identity_tokens')->get('paladin_issuer'));
    $this->assertSame('copied-audience', $this->config('agency_host.identity_tokens')->get('paladin_audience'));
    $this->assertSame('degraded', $this->container->get('state')->get('agency_host.evidence_alert_status'));
    $this->assertSame('degraded', $this->container->get('state')->get('chronicle_agency.evidence_alert_status'));
    if ($schema->tableExists('queue')) {
      $this->assertSame(1, (int) $database->select('queue', 'q')->condition('name', 'chronicle_agency.evidence')->countQuery()->execute()->fetchField());
      $this->assertSame(1, (int) $database->select('queue', 'q')->condition('name', 'agency_host.evidence')->countQuery()->execute()->fetchField());
    }
  }

}
