<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\StorageComparer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises policy import/export using the committed ignore list.
 *
 * @group agency_host
 */
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class PolicyConfigurationSyncTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config', 'config_ignore', 'agency'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'config_ignore']);
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync);
    $sync->write('config_ignore.settings', Yaml::decode(file_get_contents(__DIR__ . '/../../fixtures/config-ignore.settings.yml')));
    $site = $sync->read('system.site');
    $site['name'] = 'Imported synthetic site';
    $sync->write('system.site', $site);
  }

  /**
   * Missing source policy must not delete active policy or export it afterward.
   */
  public function testImportPreservesPolicyAndExportOmitsIt(): void {
    $policy = $this->policy();
    $this->config('agency.settings')->setData($policy)->save();
    $this->importConfiguration();
    $this->assertSame($policy, $this->container->get('config.storage')->read('agency.settings'));
    $this->assertFalse($this->container->get('config.storage.export')->exists('agency.settings'));
  }

  /**
   * Export must omit an environment policy that has never existed in sync.
   */
  public function testExportDoesNotPublishActivePolicy(): void {
    $ignore = $this->container->get('config.storage.sync')->read('config_ignore.settings');
    $this->config('config_ignore.settings')->setData($ignore)->save();
    $this->config('agency.settings')->setData($this->policy())->save();
    $this->assertFalse($this->container->get('config.storage.export')->exists('agency.settings'));
  }

  /**
   * An accidentally committed other-tenant policy cannot replace active values.
   */
  public function testImportCannotReplaceOrExtendActivePolicy(): void {
    $policy = $this->policy();
    $this->config('agency.settings')->setData($policy)->save();
    $other = $policy;
    $other['display_name'] = 'Another synthetic tenant';
    $other['policy']['close_reasons'][] = [
      'id' => 'obsolete',
      'label' => 'Obsolete',
      'message' => 'Obsolete reason.',
    ];
    $this->container->get('config.storage.sync')->write('agency.settings', $other);
    $this->importConfiguration();
    $this->assertSame($policy, $this->container->get('config.storage')->read('agency.settings'));
  }

  /**
   * A fresh environment must not acquire policy from another environment.
   */
  public function testImportCannotProvisionAbsentPolicy(): void {
    $this->container->get('config.storage.sync')->write('agency.settings', $this->policy());
    $this->importConfiguration();
    $this->assertFalse($this->container->get('config.storage')->exists('agency.settings'));
  }

  /**
   * Uses Drupal's transformation and importer, with a control write.
   */
  private function importConfiguration(): void {
    $source = $this->container->get('config.import_transformer')->transform($this->container->get('config.storage.sync'));
    $comparer = new StorageComparer($source, $this->container->get('config.storage'));
    if (class_exists(ConfigImporterFactory::class) && $this->container->has(ConfigImporterFactory::class)) {
      $importer = $this->container->get(ConfigImporterFactory::class)->get($comparer);
    }
    else {
      $importer = new ConfigImporter(
        $comparer,
        $this->container->get('event_dispatcher'),
        $this->container->get('config.manager'),
        $this->container->get('lock'),
        $this->container->get('config.typed'),
        $this->container->get('module_handler'),
        $this->container->get('module_installer'),
        $this->container->get('theme_handler'),
        $this->container->get('string_translation'),
        $this->container->get('extension.list.module'),
        $this->container->get('extension.list.theme'),
      );
    }
    $importer->reset()->import();
    $this->assertSame([], $importer->getErrors());
    $this->assertSame('Imported synthetic site', $this->config('system.site')->get('name'));
  }

  /**
   * Returns only synthetic configuration, never live tenant values.
   */
  private function policy(): array {
    $path = dirname(__DIR__, 2) . '/fixtures/synthetic-host.json';
    $fixture = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    return ['langcode' => 'en'] + $fixture['tenant'];
  }

}
