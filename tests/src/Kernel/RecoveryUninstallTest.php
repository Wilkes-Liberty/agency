<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Routine uninstall must preserve SQL recovery data before dropping schemas.
 *
 * @group agency
 * @runTestsInSeparateProcesses
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class RecoveryUninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'key', 'encrypt', 'audit_chain',
    'agency', 'agency_migrate', 'agency_workforce',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $handler = $this->container->get('module_handler');
    foreach (['agency_migrate', 'agency_workforce'] as $module) {
      $handler->loadInclude($module, 'install');
      $schema = ($module . '_schema')();
      $this->installSchema($module, array_keys($schema));
    }
  }

  /**
   * Actual removal refuses even a single retained or undelivered record.
   *
   * @dataProvider protectedTables
   */
  #[DataProvider('protectedTables')]
  public function testPopulatedRecoveryTablesBlockUninstall(string $module, string $table): void {
    $database = $this->container->get('database');
    $schema = ($module . '_schema')();
    $row = [];
    foreach ($schema[$table]['fields'] as $name => $field) {
      if ($field['type'] === 'serial' || empty($field['not null'])) {
        continue;
      }
      $row[$name] = $field['default'] ?? (in_array($field['type'], ['int', 'float', 'numeric'], TRUE) ? 1 : 'synthetic');
    }
    $database->insert($table)->fields($row)->execute();
    $before = $database->select($table, 'r')->fields('r')->execute()->fetchAll();
    try {
      $this->container->get('module_installer')->uninstall([$module]);
      $this->fail('Uninstall must refuse while recovery records remain.');
    }
    catch (ModuleUninstallValidatorException $exception) {
      $this->assertStringContainsString('recovery', $exception->getMessage());
    }
    $this->assertTrue($this->container->get('module_handler')->moduleExists($module));
    $this->assertTrue($database->schema()->tableExists($table));
    $this->assertEquals($before, $database->select($table, 'r')->fields('r')->execute()->fetchAll());
  }

  /**
   * Lists each independent SQL recovery store subject to module removal.
   */
  public static function protectedTables(): array {
    return [
      'invitation receipt' => ['agency_workforce', 'agency_workforce_receipt'],
      'invitation evidence' => ['agency_workforce', 'agency_workforce_outbox'],
      'migration lineage' => ['agency_migrate', 'agency_migrate_lineage'],
      'migration run' => ['agency_migrate', 'agency_migrate_run'],
      'migration evidence' => ['agency_migrate', 'agency_migrate_outbox'],
      'migration journal' => ['agency_migrate', 'agency_migrate_write_log'],
      'rollback receipt' => ['agency_migrate', 'agency_migrate_rollback_receipt'],
      'rollback provenance' => ['agency_migrate', 'agency_migrate_rollback_record'],
      'authority event' => ['agency_migrate', 'agency_migrate_authority_event'],
      'authority state' => ['agency_migrate', 'agency_migrate_authority_state'],
    ];
  }

  /**
   * Parent removal previews and execution both protect populated dependencies.
   *
   * @dataProvider populatedChildren
   */
  #[DataProvider('populatedChildren')]
  public function testParentRemovalProtectsChildRecords(string $module, string $table, array $row): void {
    $database = $this->container->get('database');
    $database->insert($table)->fields($row)->execute();
    $before = $database->select($table, 'r')->fields('r')->execute()->fetchAll();
    $reasons = $this->container->get('module_installer')->validateUninstall(['agency']);
    $this->assertArrayHasKey('agency', $reasons);
    $this->assertNotEmpty($reasons['agency']);
    try {
      $this->container->get('module_installer')->uninstall(['agency']);
      $this->fail('Parent uninstall must preserve dependent recovery records.');
    }
    catch (ModuleUninstallValidatorException $exception) {
      $this->assertStringContainsString('recovery', $exception->getMessage());
    }
    $this->assertTrue($this->container->get('module_handler')->moduleExists('agency'));
    $this->assertTrue($this->container->get('module_handler')->moduleExists($module));
    $this->assertEquals($before, $database->select($table, 'r')->fields('r')->execute()->fetchAll());
  }

  /**
   * Supplies retained records in each protected child module.
   */
  public static function populatedChildren(): array {
    return [
      'migration' => [
        'agency_migrate', 'agency_migrate_authority_state',
        [
          'domain' => 'synthetic',
          'state' => 'legacy',
          'version' => 1,
          'changed' => 1,
          'actor_hash' => 'synthetic',
          'reason' => 'synthetic',
        ],
      ],
      'invitations' => [
        'agency_workforce', 'agency_workforce_outbox',
        ['id' => 'synthetic', 'operation' => 'accepted', 'metadata' => '{}', 'recorded' => 1],
      ],
    ];
  }

  /**
   * Incomplete storage cannot establish that retained records are absent.
   */
  public function testUnreadableRecoveryStorageRefuses(): void {
    $this->container->get('database')->schema()->dropTable('agency_workforce_receipt');
    $reasons = $this->container->get('module_installer')->validateUninstall(['agency_workforce']);
    $this->assertArrayHasKey('agency_workforce', $reasons);
    $this->assertNotEmpty($reasons['agency_workforce']);
  }

  /**
   * Empty installations retain Drupal's ordinary removal behavior.
   *
   * @dataProvider emptyModules
   */
  #[DataProvider('emptyModules')]
  public function testEmptyModuleCanBeUninstalled(string $module): void {
    $database = $this->container->get('database');
    $tables = array_keys(($module . '_schema')());
    $this->assertSame([], $this->container->get('module_installer')->validateUninstall([$module]));
    $this->assertTrue($this->container->get('module_installer')->uninstall([$module]));
    $this->assertFalse($this->container->get('module_handler')->moduleExists($module));
    foreach ($tables as $table) {
      $this->assertFalse($database->schema()->tableExists($table));
    }
  }

  /**
   * Lists modules that remain removable before they contain recovery data.
   */
  public static function emptyModules(): array {
    return ['invitations' => ['agency_workforce'], 'migration' => ['agency_migrate']];
  }

}
