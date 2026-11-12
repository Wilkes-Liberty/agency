<?php

declare(strict_types=1);

namespace Drupal\agency\Uninstall;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Preserves recovery and evidence tables through routine module removal.
 */
final class RecoveryUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * Recovery records remain protected whether evidence was delivered or not.
   */
  private const TABLES = [
    'agency_migrate' => [
      'agency_migrate_lineage',
      'agency_migrate_run',
      'agency_migrate_outbox',
      'agency_migrate_write_log',
      'agency_migrate_authority_event',
      'agency_migrate_authority_state',
    ],
    'agency_workforce' => [
      'agency_workforce_receipt',
      'agency_workforce_outbox',
    ],
  ];

  public function __construct(
    private readonly Connection $database,
    TranslationInterface $translation,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    $tables = self::TABLES[$module] ?? [];
    if ($module === 'agency') {
      foreach (self::TABLES as $child => $child_tables) {
        if ($this->moduleHandler->moduleExists($child)) {
          $tables = array_merge($tables, $child_tables);
        }
      }
    }
    try {
      foreach ($tables as $table) {
        if (!$this->database->schema()->tableExists($table)) {
          return $this->unverified();
        }
        $query = $this->database->select($table, 'recovery')->range(0, 1);
        $query->addExpression('1');
        if ($query->execute()->fetchField() !== FALSE) {
          return [(string) $this->t('Agency recovery or evidence records must be retained; complete a reviewed retention and recovery procedure before removing this module')];
        }
      }
    }
    catch (\Throwable) {
      return $this->unverified();
    }
    return [];
  }

  /**
   * Reports a storage failure without exposing database details.
   *
   * @return list<string>
   *   The refusal reason.
   */
  private function unverified(): array {
    return [(string) $this->t('Agency recovery storage could not be verified; resolve storage and update errors before uninstalling this module')];
  }

}
