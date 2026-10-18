<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Retains bounded session-owned requests outside Form API and session caches.
 */
final class ConsoleReviewStore {

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly ConsoleStateStore $state,
  ) {}

  /**
   * Captures a read-only review; the nonce is a lookup key, never authority.
   */
  public function capture(Request $request, string $context, string $command, array $intent, array $projection): string {
    ConsoleFieldInventory::command($command, $intent['input']);
    return $this->state->mutate($request, function (array &$bucket) use ($request, $context, $command, $intent, $projection): string {
      foreach ($bucket['records'] as $nonce => $record) {
        if ($record['expires'] <= $this->time->getCurrentTime() && !$record['attempted']) {
          $this->state->deletePayload($request, $nonce);
          unset($bucket['records'][$nonce]);
        }
      }
      foreach ($bucket['records'] as $nonce => $record) {
        if (!$record['attempted'] && $record['context'] === $context && $record['command'] === $command) {
          $payload = $this->state->payload($request, $nonce);
          if ($payload['projection'] === $projection
            && array_diff_key($payload['intent'], ['key' => TRUE]) === array_diff_key($intent, ['key' => TRUE])) {
            return $nonce;
          }
        }
      }
      if (count($bucket['records']) >= 16) {
        throw new Refusal('review_capacity');
      }
      $nonce = bin2hex(random_bytes(24));
      $expires = $this->time->getCurrentTime() + 1800;
      $this->state->savePayload($request, $nonce, ['intent' => $intent, 'projection' => $projection], $expires);
      $bucket['records'][$nonce] = [
        'uid' => (int) $this->account->id(),
        'context' => $context,
        'command' => $command,
        'key' => $intent['key'],
        'expires' => $expires,
        'attempted' => FALSE,
        'result' => NULL,
      ];
      return $nonce;
    });
  }

  /**
   * Resolves only a live review owned by this account, session and route.
   */
  public function read(Request $request, string $nonce, string $context, string $command, bool $allow_expired = FALSE): array {
    return $this->state->mutate($request, function (array &$bucket) use ($request, $nonce, $context, $command, $allow_expired): array {
      $record = $this->record($bucket, $nonce, $context, $command, $allow_expired);
      if ($record['expires'] <= $this->time->getCurrentTime()) {
        return $record + ['intent' => ['key' => $record['key']], 'projection' => []];
      }
      return $record + $this->state->payload($request, $nonce);
    });
  }

  /**
   * Lists minimal own-session metadata, never old relationship projections.
   */
  public function listing(Request $request): array {
    return $this->state->mutate($request, static fn(array &$bucket): array => $bucket['records']);
  }

  /**
   * Discards only an unattempted local review, without loading its draft.
   */
  public function discard(Request $request, string $nonce): void {
    $this->state->mutate($request, function (array &$bucket) use ($request, $nonce): void {
      $record = $bucket['records'][$nonce] ?? NULL;
      if (!$record || $record['attempted']) {
        throw new Refusal('prior_unconfirmed');
      }
      $this->remove($request, $bucket, $nonce, $record);
    });
  }

  /**
   * Explicitly releases expired local metadata without claiming its outcome.
   */
  public function releaseExpired(Request $request, string $nonce, string $context, string $command): void {
    $this->state->mutate($request, function (array &$bucket) use ($request, $nonce, $context, $command): void {
      $record = $this->record($bucket, $nonce, $context, $command, TRUE);
      if ($record['expires'] > $this->time->getCurrentTime()) {
        throw new Refusal('not_found');
      }
      $this->remove($request, $bucket, $nonce, $record);
    });
  }

  /**
   * Returns an unsubmitted review to editing without discarding attempts.
   */
  public function edit(Request $request, string $nonce, string $context, string $command): array {
    return $this->state->mutate($request, function (array &$bucket) use ($request, $nonce, $context, $command): array {
      $record = $this->record($bucket, $nonce, $context, $command);
      if ($record['attempted']) {
        throw new Refusal('prior_unconfirmed');
      }
      $payload = $this->state->payload($request, $nonce);
      $this->remove($request, $bucket, $nonce, $record);
      return $payload['intent']['input'];
    });
  }

  /**
   * Marks a record before its atomic pending reservation and domain call.
   */
  public function attempted(Request $request, string $nonce): void {
    $this->state->mutate($request, function (array &$bucket) use ($nonce): void {
      $record = $bucket['records'][$nonce] ?? NULL;
      if (!$record || $record['expires'] <= $this->time->getCurrentTime()) {
        throw new Refusal('not_found');
      }
      $bucket['records'][$nonce]['attempted'] = TRUE;
    });
  }

  /**
   * Never downgrades established evidence or recreates a removed record.
   */
  public function result(Request $request, string $nonce, array $result): array {
    unset($result['invitation']);
    return $this->state->mutate($request, function (array &$bucket) use ($nonce, $result): array {
      if (!isset($bucket['records'][$nonce])) {
        throw new Refusal('not_found');
      }
      $record = $bucket['records'][$nonce];
      if ($this->state->settled($record['result'])) {
        return $record['result'];
      }
      $pending = $bucket['pending'][$record['context']] ?? NULL;
      if (!empty($pending['unconfirmed']) || !empty($record['uncertainty'])) {
        $result['unconfirmed'] = TRUE;
      }
      $bucket['records'][$nonce]['result'] = $result;
      return $result;
    });
  }

  /**
   * Refreshes identity confirmation without extending draft retention.
   */
  public function reconfirm(Request $request, string $nonce, array $selection): array {
    return $this->state->mutate($request, function (array &$bucket) use ($request, $nonce, $selection): array {
      $record = $this->record($bucket, $nonce, 'prepare', 'prepare');
      $payload = $this->state->payload($request, $nonce);
      foreach (['uid', 'subject', 'issuer', 'email'] as $field) {
        if (($selection[$field] ?? NULL) !== ($payload['intent']['selection'][$field] ?? NULL)) {
          throw new Refusal('selection_lapsed');
        }
      }
      $payload['intent']['selection'] = $selection;
      $this->state->savePayload($request, $nonce, $payload, $record['expires']);
      return $payload['intent'];
    });
  }

  /**
   * Acknowledges only a definitive result; uncertainty cannot be discarded.
   */
  public function acknowledge(Request $request, string $nonce, string $context, string $command): void {
    $this->state->mutate($request, function (array &$bucket) use ($request, $nonce, $context, $command): void {
      $record = $this->record($bucket, $nonce, $context, $command);
      if (!$this->state->definitive($record['result']) || !empty($bucket['pending'][$context]['unconfirmed'])) {
        throw new Refusal('prior_unconfirmed');
      }
      $this->remove($request, $bucket, $nonce, $record);
    });
  }

  /**
   * Validates ownership and lifetime under the bucket lock.
   */
  private function record(array $bucket, string $nonce, string $context, string $command, bool $allow_expired = FALSE): array {
    $record = $bucket['records'][$nonce] ?? NULL;
    if (!is_array($record) || $record['uid'] !== (int) $this->account->id()
      || $record['context'] !== $context || $record['command'] !== $command) {
      throw new Refusal('not_found');
    }
    if (!$allow_expired && $record['expires'] <= $this->time->getCurrentTime()) {
      throw new Refusal('review_expired');
    }
    return $record;
  }

  /**
   * Removes only an authorized record and its exactly matching reservation.
   */
  private function remove(Request $request, array &$bucket, string $nonce, array $record): void {
    $context = $record['context'];
    $pending = $bucket['pending'][$context] ?? NULL;
    if ($pending && $pending['nonce'] === $nonce && $pending['key'] === $record['key']) {
      unset($bucket['pending'][$context]);
    }
    $this->state->deletePayload($request, $nonce);
    unset($bucket['records'][$nonce]);
  }

}
