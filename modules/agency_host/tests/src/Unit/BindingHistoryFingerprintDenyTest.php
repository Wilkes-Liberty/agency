<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Composer\InstalledVersions;
use Drupal\agency_host\Identity\BindingHistory;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\agency_host\Identity\QualificationLatchInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Executes the fingerprint deny paths an unreviewed Externalauth 2.0.x release hits.
 *
 * Findings live in docs/EXTERNALAUTH_2.0.15.md.
 *
 * @group agency_host
 *
 * @see \Drupal\agency_host\Identity\BindingHistory
 */
#[CoversClass(BindingHistory::class)]
#[Group('agency_host')]
final class BindingHistoryFingerprintDenyTest extends UnitTestCase {

  /**
   * Composer installed.php snapshot captured before a deny-path override.
   *
   * @var array<string, mixed>|null
   */
  private ?array $installedSnapshot = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->assertTrue(class_exists(InstalledVersions::class));
    $this->assertTrue(InstalledVersions::isInstalled('drupal/externalauth'), 'CI installs the reviewed Externalauth writers.');
    $this->assertTrue(InstalledVersions::isInstalled('drupal/openid_connect'));
    $datasets = InstalledVersions::getAllRawData();
    $this->assertNotEmpty($datasets);
    $this->installedSnapshot = $datasets[0];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreInstalled();
    parent::tearDown();
  }

  /**
   * An unreviewed Composer reference cannot produce a fingerprint.
   *
   * Local mutation: removing the IdentityDirectoryUnavailable throw while
   * leaving `'drupal/externalauth' => '2.0.13'` in the reviewed array makes
   * this case fail (fingerprint() returns a digest instead).
   */
  public function testUnreviewedExternalauthReferenceIsRefused(): void {
    $this->overrideInstalled(static function (array $data): array {
      $data['versions']['drupal/externalauth']['reference'] = '2.0.15';
      return $data;
    });
    $this->assertSame('2.0.15', InstalledVersions::getReference('drupal/externalauth'));
    $this->expectException(IdentityDirectoryUnavailable::class);
    $this->expectExceptionMessage('Binding writer dependency requires review.');
    $this->history()->fingerprint();
  }

  /**
   * A writer whose bytes do not match REVIEWED_WRITERS cannot qualify.
   */
  public function testMismatchedWriterSourceIsRefused(): void {
    $root = InstalledVersions::getInstallPath('drupal/externalauth');
    $this->assertNotFalse($root);
    $scratch = sys_get_temp_dir() . '/agency-fingerprint-deny-' . bin2hex(random_bytes(8));
    mkdir($scratch . '/src/Form', 0777, TRUE);
    foreach (['src/Authmap.php', 'src/Form/AuthmapDeleteForm.php'] as $file) {
      $this->assertTrue(copy($root . '/' . $file, $scratch . '/' . $file));
    }
    $this->assertNotFalse(file_put_contents($scratch . '/src/Authmap.php', "\n", FILE_APPEND));
    try {
      $this->overrideInstalled(static function (array $data) use ($scratch): array {
        $data['versions']['drupal/externalauth']['install_path'] = $scratch;
        return $data;
      });
      $this->assertSame('2.0.13', InstalledVersions::getReference('drupal/externalauth'));
      $this->expectException(IdentityDirectoryUnavailable::class);
      $this->expectExceptionMessage('Binding writer source requires review.');
      $this->history()->fingerprint();
    }
    finally {
      foreach (['src/Authmap.php', 'src/Form/AuthmapDeleteForm.php'] as $file) {
        if (is_file($scratch . '/' . $file)) {
          unlink($scratch . '/' . $file);
        }
      }
      if (is_dir($scratch . '/src/Form')) {
        rmdir($scratch . '/src/Form');
      }
      if (is_dir($scratch . '/src')) {
        rmdir($scratch . '/src');
      }
      if (is_dir($scratch)) {
        rmdir($scratch);
      }
    }
  }

  /**
   * Enabled Externalauth below schema 8104 cannot qualify.
   */
  public function testPendingExternalauthSchemaIsRefused(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnMap([
      ['externalauth', TRUE],
      ['openid_connect', TRUE],
      ['chronicle_agency', FALSE],
    ]);
    $updates = $this->createMock(UpdateHookRegistry::class);
    $updates->method('getInstalledVersion')->willReturnCallback(static function (string $module): int {
      return $module === 'externalauth' ? 8103 : 30007;
    });
    $this->expectException(IdentityDirectoryUnavailable::class);
    $this->expectExceptionMessage('Binding writer schema update is pending.');
    $this->history($modules, $updates)->fingerprint();
  }

  /**
   * History that reaches only InstalledVersions and schema guards.
   */
  private function history(?ModuleHandlerInterface $modules = NULL, ?UpdateHookRegistry $updates = NULL): BindingHistory {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    if ($modules === NULL) {
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturn(FALSE);
    }
    if ($updates === NULL) {
      $updates = $this->createMock(UpdateHookRegistry::class);
      $updates->method('getInstalledVersion')->willReturn(0);
    }
    return new BindingHistory(
      $database,
      new Settings(['hash_salt' => 'synthetic-fingerprint-deny']),
      $this->createMock(ConfigFactoryInterface::class),
      $modules,
      $updates,
      $this->createMock(TimeInterface::class),
      $this->createMock(QualificationLatchInterface::class),
      $this->createMock(StorageInterface::class),
    );
  }

  /**
   * Reloads Composer installed data without the process vendor scan.
   *
   * @param callable(array<string, mixed>): array<string, mixed> $mutate
   *   Returns the installed.php dataset to expose to InstalledVersions.
   */
  private function overrideInstalled(callable $mutate): void {
    $this->assertNotNull($this->installedSnapshot);
    $data = $mutate($this->installedSnapshot);
    $this->vendorScan(FALSE);
    InstalledVersions::reload($data);
  }

  /**
   * Restores Composer installed data and vendor discovery.
   */
  private function restoreInstalled(): void {
    if ($this->installedSnapshot !== NULL) {
      InstalledVersions::reload($this->installedSnapshot);
    }
    $this->vendorScan(TRUE);
  }

  /**
   * Enables or disables InstalledVersions' ClassLoader vendor scan.
   */
  private function vendorScan(bool $enabled): void {
    \Closure::bind(static function (bool $enabled): void {
      self::$canGetVendors = $enabled;
      self::$installedByVendor = [];
    }, NULL, InstalledVersions::class)($enabled);
  }

}
