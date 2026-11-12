<?php

declare(strict_types=1);

namespace Drupal\agency_migrate\Plugin;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Site\Settings;

/**
 * Discovers migrate copier plugins.
 *
 * @method \Drupal\agency_migrate\Plugin\CopierInterface createInstance($plugin_id, array $configuration = [])
 */
final class CopierManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/AgencyMigrate/Copier',
      $namespaces,
      $module_handler,
      CopierInterface::class,
      'Drupal\agency_migrate\Attribute\MigrateCopier',
      'Drupal\agency_migrate\Annotation\MigrateCopier',
    );
    $this->alterInfo('agency_migrate_copier_info');
    $this->setCacheBackend($cache_backend, 'agency_migrate_copier');
  }

  /**
   * Returns the uniquely qualified copier for a target in an explicit domain.
   */
  public function copierForTarget(string $target_type, ?string $domain = NULL): CopierInterface {
    if ($domain === NULL) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    foreach ($this->copiersForDomain($domain) as $copier) {
      if ($copier->targetEntityType() === $target_type) {
        return $copier;
      }
    }
    throw new MigrateRefusal('rollback_unqualified');
  }

  /**
   * Requires agreement between the host registry and every domain declaration.
   *
   * @return array<string, \Drupal\agency_migrate\Plugin\CopierInterface>
   *   All expected domain copiers, uniquely keyed by plugin id.
   */
  public function copiersForDomain(string $domain): array {
    $registry = Settings::get('agency_migrate_copier_domains', []);
    $expected = $this->declaredNames(is_array($registry) ? ($registry[$domain] ?? NULL) : NULL);
    if ($expected === []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $definitions = $this->getDefinitions();
    $declared = [];
    foreach ($definitions as $id => $definition) {
      $domains = $this->declaredNames($definition['domains'] ?? []);
      if (in_array($domain, $domains, TRUE)) {
        $declared[] = (string) $id;
      }
    }
    sort($expected, SORT_STRING);
    sort($declared, SORT_STRING);
    if ($expected !== $declared) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $targets = [];
    foreach ($expected as $id) {
      $source = $definitions[$id]['source_type'] ?? NULL;
      $target = $definitions[$id]['target_type'] ?? NULL;
      if (!is_string($source) || $source === '' || !is_string($target) || $target === '') {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if (isset($targets[$target])) {
        throw new MigrateRefusal('rollback_ambiguous');
      }
      $targets[$target] = TRUE;
    }
    $copiers = [];
    foreach ($expected as $id) {
      try {
        $copier = $this->createInstance($id);
      }
      catch (\Throwable) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      if ($copier->sourceEntityType() !== $definitions[$id]['source_type']
        || $copier->targetEntityType() !== $definitions[$id]['target_type']) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $copiers[$id] = $copier;
    }
    return $copiers;
  }

  /**
   * Validates explicit lists without guessing or coercing missing names.
   *
   * @return list<string>
   *   Explicit, unique nonempty names.
   */
  private function declaredNames(mixed $names): array {
    if (!is_array($names) || !array_is_list($names)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    $validated = [];
    foreach ($names as $name) {
      if (!is_string($name) || $name === '') {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $validated[] = $name;
    }
    if (count(array_unique($validated)) !== count($validated)) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    return $validated;
  }

}
