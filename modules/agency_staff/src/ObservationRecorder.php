<?php

declare(strict_types=1);

namespace Drupal\agency_staff;

use Drupal\agency_staff\Identity\IdentityProviderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Records minimized failures without changing the protected response or denial.
 */
final class ObservationRecorder {

  public function __construct(
    private readonly Connection $database,
    private readonly EvidenceOutbox $outbox,
    private readonly IdentityProviderInterface $identity,
    private readonly AccountProxyInterface $account,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records only a fixed reason, time and actor hash after command rollback.
   */
  public function report(string $reason): void {
    if (!in_array($reason, ['not_found', 'identity_unavailable', 'recording_unavailable'], TRUE)) {
      return;
    }
    $actor = hash('sha256', 'session-account:' . $this->account->id());
    try {
      $actor = $this->identity->current()?->key() ?? $actor;
    }
    catch (\Throwable) {
      // Provider failure cannot introduce a guessed identity into evidence.
    }
    try {
      $transaction = $this->database->startTransaction();
      try {
        $this->outbox->recordObservation($reason, $actor);
        $transaction->commitOrRelease();
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
    }
    catch (\Throwable) {
      // Never include exception text, request IDs, payloads or contact details.
      $this->warn('Discovery observation persistence unavailable: {reason}.', ['reason' => $reason]);
    }
    if ($reason !== 'not_found') {
      try {
        // One bounded latest-observation slot per fixed operational category.
        $this->state->set('agency_staff.alert.' . $reason, $this->time->getCurrentTime());
      }
      catch (\Throwable) {
        $this->warn('Discovery operational alert persistence unavailable.');
      }
    }
  }

  /**
   * Prevents a failing logging backend from replacing the protected response.
   */
  private function warn(string $message, array $context = []): void {
    try {
      $this->logger->warning($message, $context);
    }
    catch (\Throwable) {
      // Every observation sink can fail; the original refusal remains primary.
    }
  }

  /**
   * Returns historical outage observations to an authorized coordinator queue.
   */
  public function alerts(): array {
    $alerts = [];
    foreach (['identity_unavailable', 'recording_unavailable'] as $reason) {
      $time = $this->state->get('agency_staff.alert.' . $reason);
      if ($time !== NULL) {
        $alerts[] = ['reason' => $reason, 'last_observed_at' => (int) $time];
      }
    }
    return $alerts;
  }

}
