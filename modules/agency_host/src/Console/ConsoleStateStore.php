<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\Core\Session\SessionHandler;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Atomically stores session-bound console state outside serialized sessions.
 *
 * The session identifier is never stored. Existing Core key/value tables hold
 * bounded recovery metadata and separately expiring draft payloads. Nothing in
 * this service determines domain authority or reads Agency storage.
 */
final class ConsoleStateStore {

  public function __construct(
    private readonly KeyValueStoreInterface $metadata,
    private readonly KeyValueStoreExpirableInterface $payloads,
    private readonly LockBackendInterface $lock,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly \SessionHandlerInterface $sessionHandler,
  ) {}

  /**
   * Reads and changes one freshly locked bucket, never across a domain command.
   */
  public function mutate(Request $request, callable $operation): mixed {
    [$key, $digest] = $this->identity($request);
    return $this->locked($key, function () use ($key, $digest, $operation): mixed {
      $before = $this->metadata->get($key);
      $bucket = $before ?? [
        'uid' => (int) $this->account->id(),
        'session_digest' => $digest,
        'records' => [],
        'pending' => [],
      ];
      if (!is_array($bucket) || $bucket['uid'] !== (int) $this->account->id()
        || !hash_equals($bucket['session_digest'], $digest)) {
        throw new Refusal('not_found');
      }
      $result = $operation($bucket);
      if ($bucket !== $before && ($before !== NULL || $bucket['records'] || $bucket['pending'])) {
        if (!$bucket['records'] && !$bucket['pending']) {
          $this->metadata->delete($key);
        }
        else {
          $this->metadata->set($key, $bucket);
        }
      }
      return $result;
    });
  }

  /**
   * Writes a payload only until its original, immutable review expiry.
   */
  public function savePayload(Request $request, string $nonce, array $payload, int $expires): void {
    [$key] = $this->identity($request);
    $remaining = $expires - $this->time->getCurrentTime();
    if ($remaining <= 0) {
      throw new Refusal('review_expired');
    }
    $this->payloads->setWithExpire($key . ':' . $nonce, $payload, $remaining);
  }

  /**
   * Loads live payload without interpreting a failed read as an empty draft.
   */
  public function payload(Request $request, string $nonce): array {
    [$key] = $this->identity($request);
    $payload = $this->payloads->get($key . ':' . $nonce);
    if (!is_array($payload)) {
      throw new Refusal('review_expired');
    }
    return $payload;
  }

  /**
   * Deletes only the local draft associated with an authorized record removal.
   */
  public function deletePayload(Request $request, string $nonce): void {
    [$key] = $this->identity($request);
    $this->payloads->delete($key . ':' . $nonce);
  }

  /**
   * Gets a fresh minimal hold; unrelated session writes cannot replace it.
   */
  public function pending(Request $request, string $context): ?array {
    return $this->mutate($request, static fn(array &$bucket): ?array => $bucket['pending'][$context] ?? NULL);
  }

  /**
   * Reserves exactly one record before a public command can begin.
   */
  public function reserve(Request $request, string $context, array $intent): array {
    return $this->mutate($request, function (array &$bucket) use ($context, $intent): array {
      $nonce = $intent['review_nonce'] ?? '';
      $prior = $bucket['pending'][$context] ?? NULL;
      if ($prior && ($prior['key'] !== $intent['key'] || $prior['nonce'] !== $nonce)) {
        throw new Refusal('prior_unconfirmed');
      }
      $record = $bucket['records'][$nonce] ?? NULL;
      if (!$record || $record['context'] !== $context || $record['key'] !== $intent['key']
        || $record['command'] !== ($intent['command'] ?? NULL)) {
        throw new Refusal('not_found');
      }
      if ($record['expires'] <= $this->time->getCurrentTime()) {
        throw new Refusal('review_expired');
      }
      if ($this->settled($record['result'])) {
        return ['settled' => $record['result']];
      }
      $active = $record['active'] ?? [];
      if (count($active) >= 16) {
        throw new Refusal('rate_limited');
      }
      $ticket = bin2hex(random_bytes(16));
      $bucket['records'][$nonce]['uncertainty'] = !empty($record['uncertainty']) || !empty($active) || !empty($prior['unconfirmed']);
      $active[$ticket] = TRUE;
      $bucket['records'][$nonce]['active'] = $active;
      $bucket['records'][$nonce]['attempted'] = TRUE;
      $bucket['pending'][$context] = [
        'key' => $intent['key'],
        'nonce' => $nonce,
        'command' => $intent['command'],
        'unconfirmed' => TRUE,
      ];
      return ['ticket' => $ticket];
    });
  }

  /**
   * Settles monotonically without recreating a released or superseded hold.
   */
  public function settle(Request $request, string $context, array $intent, string $ticket, array $result): array {
    return $this->mutate($request, function (array &$bucket) use ($context, $intent, $ticket, $result): array {
      $nonce = $intent['review_nonce'];
      $record = $bucket['records'][$nonce] ?? NULL;
      $pending = $bucket['pending'][$context] ?? NULL;
      if (!$record || !$pending || $pending['nonce'] !== $nonce
        || $pending['key'] !== $intent['key'] || $record['key'] !== $intent['key']
        || !isset($record['active'][$ticket])) {
        throw new Refusal('not_found');
      }
      unset($bucket['records'][$nonce]['active'][$ticket]);
      if ($this->settled($record['result'])) {
        return $record['result'];
      }
      if (!in_array($result['outcome'] ?? '', ['committed', 'conflict'], TRUE)
        && (!empty($record['uncertainty']) || ($result['outcome'] ?? '') === 'uncertain'
          || ($result['reason'] ?? '') === 'rate_limited')) {
        $result['unconfirmed'] = TRUE;
      }
      $safe = $result;
      unset($safe['invitation']);
      $bucket['records'][$nonce]['result'] = $safe;
      $bucket['pending'][$context]['unconfirmed'] = !empty($safe['unconfirmed']) || ($safe['outcome'] ?? '') === 'uncertain';
      $bucket['records'][$nonce]['uncertainty'] = $bucket['pending'][$context]['unconfirmed'];
      return $result;
    });
  }

  /**
   * Only saved receipts and qualified conflicts settle an exact retry forever.
   */
  public function settled(?array $result): bool {
    return $result && empty($result['unconfirmed']) && in_array($result['outcome'] ?? '', ['committed', 'conflict'], TRUE);
  }

  /**
   * Identifies a definitive result, including source-qualified conflicts.
   */
  public function definitive(?array $result): bool {
    return $result && empty($result['unconfirmed']) && ($result['outcome'] ?? '') !== 'uncertain';
  }

  /**
   * Removes orphan metadata only after Core has actually removed its session.
   *
   * This bounded cleanup supports Core's database session handler. A storage
   * error never means an absent session. Session timestamps are not used: a
   * valid request may be running before its session timestamp is refreshed.
   */
  public function collectOrphans(): void {
    if (get_class($this->sessionHandler) !== SessionHandler::class) {
      return;
    }
    foreach ($this->metadata->getAllKeys() as $key) {
      $this->locked($key, function () use ($key): void {
        $bucket = $this->metadata->get($key);
        if (!is_array($bucket) || !isset($bucket['session_digest'], $bucket['uid'])) {
          return;
        }
        $exists = $this->database->select('sessions', 's')->fields('s', ['uid'])
          ->condition('sid', $bucket['session_digest'])->condition('uid', $bucket['uid'])
          ->range(0, 1)->execute()->fetchField();
        if ($exists !== FALSE) {
          return;
        }
        foreach (array_keys($bucket['records']) as $nonce) {
          $this->payloads->delete($key . ':' . $nonce);
        }
        $this->metadata->delete($key);
      });
    }
  }

  /**
   * Derives separate secret bucket and Core lookup digests from this session.
   */
  private function identity(Request $request): array {
    if (!$this->account->isAuthenticated() || !$request->hasSession()) {
      throw new Refusal('not_found');
    }
    $session = $request->getSession();
    $session->start();
    $id = $session->getId();
    if ($id === '') {
      throw new Refusal('not_found');
    }
    return [
      hash_hmac('sha256', json_encode([(int) $this->account->id(), $id], JSON_THROW_ON_ERROR), Settings::getHashSalt()),
      Crypt::hashBase64($id),
    ];
  }

  /**
   * A short lock protects bookkeeping only; storage failures refuse closed.
   */
  private function locked(string $key, callable $operation): mixed {
    $name = 'agency_host.console:' . $key;
    $owned = FALSE;
    try {
      $owned = $this->lock->acquire($name, 30.0);
      if (!$owned) {
        $this->lock->wait($name, 1);
        $owned = $this->lock->acquire($name, 30.0);
      }
      if (!$owned) {
        throw new Refusal('unavailable');
      }
      return $operation();
    }
    catch (Refusal $error) {
      throw $error;
    }
    catch (\Throwable) {
      throw new Refusal('unavailable');
    }
    finally {
      if ($owned) {
        $this->lock->release($name);
      }
    }
  }

}
