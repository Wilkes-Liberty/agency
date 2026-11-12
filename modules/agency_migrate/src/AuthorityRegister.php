<?php

declare(strict_types=1);

namespace Drupal\agency_migrate;

use Drupal\agency_migrate\Exception\MigrateRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Per-domain authority with forward-only transitions and one rollback edge.
 *
 * States: legacy → shadow → agency → removed. The only reverse edge is
 * agency → legacy, and only as an explicit rollback.
 */
final class AuthorityRegister {

  private const STATES = ['legacy', 'shadow', 'agency', 'removed'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly MigrateOutbox $outbox,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Current state for a domain, or NULL when the domain is unregistered.
   */
  public function get(string $domain): ?string {
    $row = $this->configFactory->get('agency_migrate.authority')->get('domains.' . $domain);
    if (!is_array($row) || !isset($row['state'])) {
      return NULL;
    }
    return (string) $row['state'];
  }

  /**
   * All registered domains and their current states.
   *
   * @return array<string, array<string, mixed>>
   *   Domain id keyed to state, changed time, actor hash and reason.
   */
  public function status(): array {
    $domains = $this->configFactory->get('agency_migrate.authority')->get('domains') ?? [];
    return is_array($domains) ? $domains : [];
  }

  /**
   * Registers a domain at legacy-authoritative. Idempotent for that state.
   */
  public function register(string $domain, string $reason): void {
    $this->assertDomain($domain);
    $this->assertReason($reason);
    $this->assertPermission('administer agency migrate authority');
    if ($this->get($domain) !== NULL) {
      throw new MigrateRefusal('invalid_transition');
    }
    $this->write($domain, 'legacy', 'none', $reason, FALSE);
  }

  /**
   * Advances one step, or rolls back agency → legacy when $rollback is TRUE.
   */
  public function transition(string $domain, string $to, string $reason, bool $rollback = FALSE): void {
    $from = $this->assertTransition($domain, $to, $reason, $rollback);
    $this->write($domain, $to, $from, $reason, $rollback);
  }

  /**
   * Validates a proposed transition without changing authority or evidence.
   *
   * @return string
   *   The current authority state. Validation does not reserve that state.
   */
  public function assertTransition(string $domain, string $to, string $reason, bool $rollback = FALSE): string {
    $this->assertDomain($domain);
    $this->assertReason($reason);
    $this->assertPermission('administer agency migrate authority');
    if (!in_array($to, self::STATES, TRUE)) {
      throw new MigrateRefusal('invalid_transition');
    }
    $from = $this->get($domain);
    if ($from === NULL) {
      throw new MigrateRefusal('invalid_transition');
    }
    if (!$this->allowed($from, $to, $rollback)) {
      throw new MigrateRefusal('invalid_transition');
    }
    return $from;
  }

  /**
   * Whether $from → $to is permitted.
   */
  private function allowed(string $from, string $to, bool $rollback): bool {
    if ($rollback) {
      return $from === 'agency' && $to === 'legacy';
    }
    return match ($from) {
      'legacy' => $to === 'shadow',
      'shadow' => $to === 'agency',
      'agency' => $to === 'removed',
      default => FALSE,
    };
  }

  /**
   * Persists config, a durable event row and one minimized evidence intent.
   */
  private function write(string $domain, string $to, string $from, string $reason, bool $rollback): void {
    $now = $this->time->getCurrentTime();
    $actor = hash('sha256', 'uid:' . $this->currentUser->id());
    $config = $this->configFactory->getEditable('agency_migrate.authority');
    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('agency_migrate_authority_event')->fields([
        'domain' => $domain,
        'from_state' => $from,
        'to_state' => $to,
        'actor_hash' => $actor,
        'reason' => $reason,
        'recorded' => $now,
      ])->execute();
      $this->outbox->record('authority_change', [
        'domain' => $domain,
        'from' => $from,
        'to' => $to,
        'rollback' => $rollback,
      ], hash('sha256', implode(':', ['authority', $domain, $from, $to, (string) $now, $actor])));
      $domains = $config->get('domains') ?? [];
      $domains[$domain] = [
        'state' => $to,
        'changed' => $now,
        'actor_hash' => $actor,
        'reason' => $reason,
      ];
      $config->set('domains', $domains)->save();
      TransactionBoundary::commit($transaction);
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      throw $exception;
    }
  }

  /**
   * Fails closed when the current account lacks the named permission.
   */
  public function assertPermission(string $permission): void {
    if (!$this->currentUser->hasPermission($permission)) {
      throw new MigrateRefusal('forbidden');
    }
  }

  /**
   * Restricts domain ids to tenant-neutral machine names.
   */
  private function assertDomain(string $domain): void {
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $domain)) {
      throw new MigrateRefusal('invalid_transition');
    }
  }

  /**
   * Requires a short operator reason with no empty or oversized value.
   */
  private function assertReason(string $reason): void {
    $trimmed = trim($reason);
    if ($trimmed === '' || strlen($trimmed) > 255) {
      throw new MigrateRefusal('invalid_transition');
    }
  }

}
