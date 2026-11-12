<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\agency_migrate\Plugin\CopierManager;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Replays Agency writes to legacy through plugins and the lineage map.
 *
 * This is not a database restore. Historical Audit Chain events stay as they
 * were written. Authority returns to legacy after a successful replay.
 */
final class RollbackService {

  public function __construct(
    private readonly CopierManager $copiers,
    private readonly PostCutoverWriteLog $writes,
    private readonly AuthorityRegister $authority,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Replays pending Agency writes, then sets authority to legacy.
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
      return [
        'domain' => $domain,
        'pending' => count($this->writes->pending($domain)),
        'execute' => FALSE,
        'status' => 'dry_run',
      ];
    }
    return $this->authority->withTransitionFence($domain, 'legacy', $reason, TRUE, function () use ($domain, $reason): array {
      $pending = $this->writes->pending($domain);
      $replayed = 0;
      foreach ($pending as $write) {
        $copier = $this->copiers->copierForTarget((string) $write['entity_type']);
        $target = $copier->loadTarget((int) $write['entity_id']);
        if ($target === NULL) {
          throw new MigrateRefusal('rollback_conflict');
        }
        $copier->writeLegacyFromTarget($target);
        $this->writes->markReplayed((int) $write['wid']);
        $replayed++;
      }
      $this->authority->transition($domain, 'legacy', $reason, TRUE);
      return [
        'domain' => $domain,
        'replayed' => $replayed,
        'authority' => $this->authority->get($domain),
        'status' => 'completed',
      ];
    });
  }

}
