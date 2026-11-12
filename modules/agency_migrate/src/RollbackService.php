<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Contains unqualified replay while allowing verified empty-journal rollback.
 *
 * Legacy journal rows lack durable operation snapshots and cannot qualify
 * replay. Historical Audit Chain events and original journal rows remain.
 */
final class RollbackService {

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly PostCutoverWriteLog $writes,
    private readonly AuthorityRegister $authority,
    private readonly AccountProxyInterface $currentUser,
    private readonly Reconciler $reconciler,
  ) {}

  /**
   * Qualifies an empty domain journal, then returns authority to legacy.
   *
   * @return array<string, mixed>
   *   Receipt with replayed count and resulting authority state.
   */
  public function rollback(string $domain, string $reason, bool $execute = FALSE): array {
    if (!$this->currentUser->hasPermission('run agency migrate')) {
      throw new MigrateRefusal('forbidden');
    }
    $this->authority->assertTransition($domain, 'legacy', $reason, TRUE);
    if (!$execute) {
      $this->preflight($domain);
      return [
        'domain' => $domain,
        'pending' => 0,
        'execute' => FALSE,
        'status' => 'dry_run',
      ];
    }
    return $this->authority->withTransitionFence($domain, 'legacy', $reason, TRUE, function () use ($domain, $reason): array {
      $this->preflight($domain);
      $this->authority->transition($domain, 'legacy', $reason, TRUE);
      return [
        'domain' => $domain,
        'replayed' => 0,
        'authority' => $this->authority->get($domain),
        'status' => 'completed',
      ];
    });
  }

  /**
   * Reads the whole known journal/domain before allowing any effects.
   */
  private function preflight(string $domain): void {
    $copiers = $this->copiers->copiersForDomain($domain);
    $targets = [];
    foreach ($copiers as $copier) {
      $targets[$copier->targetEntityType()] = $copier;
    }
    $pending = $this->writes->pending($domain);
    foreach ($pending as $write) {
      $copier = $targets[$write['entity_type']] ?? NULL;
      if ($copier === NULL) {
        throw new MigrateRefusal('rollback_unqualified');
      }
      $id = (int) $write['entity_id'];
      $target = $id > 0 ? $copier->loadTarget($id) : NULL;
      if ($target === NULL || $target->getEntityTypeId() !== $write['entity_type']
        || (int) $target->id() !== $id || $target->uuid() !== $write['entity_uuid']) {
        throw new MigrateRefusal('rollback_conflict');
      }
    }
    // The four-argument journal has no operation kind, generation or durable
    // history snapshots. Preserve those rows; never invent missing evidence.
    if ($pending !== []) {
      throw new MigrateRefusal('rollback_unqualified');
    }
    foreach (array_keys($copiers) as $id) {
      try {
        if (!$this->reconciler->compare($id)->fullPassed()) {
          throw new MigrateRefusal('rollback_unqualified');
        }
      }
      catch (\Throwable) {
        throw new MigrateRefusal('rollback_unqualified');
      }
    }
  }

}
