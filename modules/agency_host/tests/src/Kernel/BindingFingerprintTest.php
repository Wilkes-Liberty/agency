<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Composer\InstalledVersions;
use Drupal\agency_host\Identity\BindingHistory;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\openid_connect\Entity\OpenIDConnectClientEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Live fingerprint stays stable on the reviewed Externalauth 2.0.15 writers.
 *
 * Findings live in docs/EXTERNALAUTH_2.0.15.md.
 *
 * @group agency_host
 *
 * @see \Drupal\Tests\agency_host\Unit\BindingHistoryFingerprintTest
 */
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class BindingFingerprintTest extends KernelTestBase {

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
    $this->installSchema('externalauth', ['authmap']);
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('externalauth', 8104);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('openid_connect', 30007);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('chronicle_agency', 10003);
    $this->container->get('module_installer')->install(['agency', 'agency_staff', 'agency_host'], FALSE);
    $this->container = \Drupal::getContainer();
    $this->config('agency_host.identity_tokens')
      ->set('paladin_issuer', 'https://identity.example.test/realms/example')
      ->set('paladin_audience', 'synthetic-agency')
      ->save();
    $this->config('agency_host.settings')
      ->set('binding_providers', ['openid_connect.client_portal'])
      ->save();
    OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [],
    ])->save();
  }

  /**
   * The reviewed 2.0.15 install still produces a stable, mapping-sensitive digest.
   */
  public function testFingerprintStableAcrossReviewedTwoOhWriters(): void {
    $this->assertTrue(InstalledVersions::isInstalled('drupal/externalauth'));
    $this->assertSame('2.0.15', InstalledVersions::getReference('drupal/externalauth'));
    $root = InstalledVersions::getInstallPath('drupal/externalauth');
    $writers = (new \ReflectionClass(BindingHistory::class))->getConstant('REVIEWED_WRITERS');
    $this->assertIsArray($writers);
    foreach ($writers as $row) {
      [$package, $file, $expected] = $row;
      if ($package !== 'drupal/externalauth') {
        continue;
      }
      $actual = hash_file('sha256', $root . '/' . $file);
      $this->assertIsString($actual);
      $this->assertTrue(hash_equals($expected, $actual), $file);
    }
    $history = $this->history();
    $full = $history->fingerprint();
    $authority = $history->fingerprint(FALSE);
    $this->assertSame(64, strlen($full));
    $this->assertSame(64, strlen($authority));
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $full);
    $this->assertSame($full, $history->fingerprint());
    $this->assertSame($authority, $history->fingerprint(FALSE));
    $this->container->get('database')->insert('authmap')->fields([
      'uid' => 2,
      'provider' => 'openid_connect.client_portal',
      'authname' => 'synthetic-person',
    ])->execute();
    $this->assertNotSame($full, $history->fingerprint());
    $this->assertSame($authority, $history->fingerprint(FALSE));
  }

  /**
   * Enabled Externalauth below schema 8104 cannot qualify on a live host.
   */
  public function testPendingExternalauthSchemaIsRefused(): void {
    $this->container->get('update.update_hook_registry')->setInstalledVersion('externalauth', 8103);
    $this->expectException(IdentityDirectoryUnavailable::class);
    $this->expectExceptionMessage('Binding writer schema update is pending.');
    $this->history()->fingerprint();
  }

  /**
   * Binding history constructed against the kernel config and identity settings.
   */
  private function history(): BindingHistory {
    return new BindingHistory(
      $this->container->get('database'),
      new Settings(Settings::getAll() + [
        'agency_host_identity' => [
          'issuer' => 'https://identity.example.test/realms/example',
          'binding_version' => 'synthetic-origin-v1',
          'binding_mode' => 'mapped_or_unbound',
        ],
      ]),
      $this->container->get('config.factory'),
      $this->container->get('module_handler'),
      $this->container->get('update.update_hook_registry'),
      $this->container->get('datetime.time'),
      $this->container->get('agency_host.qualification_latch'),
      $this->container->get('config.storage'),
    );
  }

}
