<?php

declare(strict_types=1);

namespace Drupal\agency_campaign;

use Drupal\agency_campaign\Entity\Campaign;
use Drupal\agency_campaign\Exception\CampaignRefusal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field_guard\ExplicitPermissionChecker;
use Drupal\user\UserInterface;

/**
 * Named launch action: deny paths, replay and required audit first.
 */
final class LaunchCampaign {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountProxyInterface $currentUser,
    private readonly ExplicitPermissionChecker $permissions,
    private readonly CampaignOutbox $outbox,
    private readonly TimeInterface $time,
    private readonly TransactionCommitter $committer,
  ) {}

  /**
   * Creates a draft campaign or activates an existing draft.
   *
   * Launch does not grant relationship or roster authority.
   *
   * @param array<string, mixed> $input
   *   Title for create, or campaign_uuid for activate. Optional not_after.
   * @param string $key
   *   One user-intent idempotency key.
   *
   * @return array<string, mixed>
   *   Minimal committed receipt.
   */
  public function launch(array $input, string $key): array {
    $this->assertRequest($input, $key);
    $account = $this->coordinator();
    $actor = hash('sha256', 'coordinator:' . $account->id());
    $semantic = $this->semantic($input);
    $context = $semantic['campaign_uuid'] ?? '';
    $intent = hash('sha256', json_encode(
      [$actor, 'launchCampaign', $context, $key],
      JSON_THROW_ON_ERROR,
    ));
    $hash = $this->fingerprint($semantic);
    $transaction = $this->database->startTransaction();
    try {
      $replay = $this->replay($intent, $hash, $account);
      if ($replay !== NULL) {
        $this->committer->commit($transaction);
        unset($transaction);
        return ['outcome' => 'committed', 'receipt' => $replay];
      }
      $campaign = $this->write($semantic, $account);
      $event = $this->outbox->recordRequired(
        $campaign->uuid(),
        $actor,
        (string) $campaign->get('status')->value,
      );
      $receipt = [
        'id' => $event,
        'campaign_id' => $campaign->uuid(),
        'effective_at' => $this->time->getCurrentTime(),
        'state' => $campaign->get('status')->value,
      ];
      $this->database->insert('agency_campaign_intent')->fields([
        'id' => $intent,
        'payload_hash' => $hash,
        'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR),
      ])->execute();
      $this->committer->commit($transaction);
      unset($transaction);
      return ['outcome' => 'committed', 'receipt' => $receipt];
    }
    catch (\Throwable $exception) {
      try {
        $transaction?->rollBack();
      }
      finally {
        unset($transaction);
      }
      if ($exception instanceof CampaignRefusal) {
        throw $exception;
      }
      throw new CampaignRefusal('failed');
    }
  }

  /**
   * Refuses tenant arguments, stale deadlines and malformed keys first.
   *
   * @param array<string, mixed> $input
   *   Caller payload.
   * @param string $key
   *   Idempotency key.
   */
  private function assertRequest(array $input, string $key): void {
    if (array_key_exists('tenant', $input)) {
      throw new CampaignRefusal('forbidden');
    }
    $allowed = ['title', 'campaign_uuid', 'not_after'];
    foreach (array_keys($input) as $field) {
      if (!in_array($field, $allowed, TRUE)) {
        throw new CampaignRefusal('invalid');
      }
    }
    if (isset($input['not_after'])) {
      if (!is_int($input['not_after'])
        || $input['not_after'] <= $this->time->getCurrentTime()) {
        throw new CampaignRefusal('timeout');
      }
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $key)) {
      throw new CampaignRefusal('invalid');
    }
  }

  /**
   * Requires an explicit coordinator grant on a fresh, non-anonymous account.
   */
  private function coordinator(): UserInterface {
    $user = $this->entities->getStorage('user')
      ->loadUnchanged($this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()
      || $user->isAnonymous()
      || !$this->permissions->hasExplicitPermission(
        $this->currentUser,
        'coordinate agency campaigns',
      )
      || !$this->permissions->hasExplicitPermission(
        $user,
        'coordinate agency campaigns',
      )) {
      throw new CampaignRefusal('forbidden');
    }
    return $user;
  }

  /**
   * Returns the create or activate payload after validation.
   *
   * @param array<string, mixed> $input
   *   Caller payload.
   *
   * @return array{title?: string, campaign_uuid?: string}
   *   Canonical write input.
   */
  private function semantic(array $input): array {
    $has_title = array_key_exists('title', $input);
    $has_uuid = array_key_exists('campaign_uuid', $input);
    if ($has_title === $has_uuid) {
      throw new CampaignRefusal('invalid');
    }
    if ($has_title) {
      if (!is_string($input['title']) || mb_strlen($input['title']) > 255
        || str_contains($input['title'], "\0")
        || trim($input['title']) === '') {
        throw new CampaignRefusal('invalid');
      }
      return ['title' => trim($input['title'])];
    }
    if (!is_string($input['campaign_uuid'])
      || !Uuid::isValid($input['campaign_uuid'])) {
      throw new CampaignRefusal('invalid');
    }
    return ['campaign_uuid' => $input['campaign_uuid']];
  }

  /**
   * Replays a matching receipt after current authority, or refuses drift.
   *
   * @param string $intent
   *   Intent row id.
   * @param string $hash
   *   Payload fingerprint.
   * @param \Drupal\user\UserInterface $account
   *   Fresh coordinator.
   *
   * @return array<string, mixed>|null
   *   Stored receipt, or NULL when this is a new intent.
   */
  private function replay(
    string $intent,
    string $hash,
    UserInterface $account,
  ): ?array {
    $row = $this->database->select('agency_campaign_intent', 'i')
      ->fields('i')
      ->condition('id', $intent)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    if (!hash_equals($row['payload_hash'], $hash)) {
      throw new CampaignRefusal('invalid');
    }
    $receipt = json_decode($row['receipt'], TRUE, 16, JSON_THROW_ON_ERROR);
    $storage = $this->entities->getStorage('agency_campaign');
    $loaded = $storage->loadByProperties(['uuid' => $receipt['campaign_id']]);
    $campaign = $loaded ? reset($loaded) : NULL;
    if (!$campaign instanceof Campaign
      || (int) $campaign->get('owner_uid')->value !== (int) $account->id()) {
      throw new CampaignRefusal('not_found');
    }
    return $receipt;
  }

  /**
   * Writes a new draft or activates an owned draft.
   *
   * @param array{title?: string, campaign_uuid?: string} $semantic
   *   Canonical write input.
   * @param \Drupal\user\UserInterface $account
   *   Fresh coordinator.
   *
   * @return \Drupal\agency_campaign\Entity\Campaign
   *   The saved record.
   */
  private function write(array $semantic, UserInterface $account): Campaign {
    $storage = $this->entities->getStorage('agency_campaign');
    if (isset($semantic['title'])) {
      $campaign = $storage->create([
        'title' => $semantic['title'],
        'status' => 'draft',
        'owner_uid' => $account->id(),
      ]);
      $campaign->save();
      if (!$campaign instanceof Campaign) {
        throw new CampaignRefusal('failed');
      }
      return $campaign;
    }
    if (!isset($semantic['campaign_uuid'])) {
      throw new CampaignRefusal('invalid');
    }
    $loaded = $storage->loadByProperties([
      'uuid' => $semantic['campaign_uuid'],
    ]);
    $campaign = $loaded ? reset($loaded) : NULL;
    if (!$campaign instanceof Campaign
      || (int) $campaign->get('owner_uid')->value !== (int) $account->id()
      || $campaign->get('status')->value !== 'draft') {
      throw new CampaignRefusal('not_found');
    }
    $campaign->set('status', 'active');
    $campaign->setNewRevision(TRUE);
    $campaign->save();
    return $campaign;
  }

  /**
   * Hashes the semantic payload for replay comparison.
   *
   * @param array<string, string> $semantic
   *   Canonical write input.
   *
   * @return string
   *   Hex SHA-256 digest.
   */
  private function fingerprint(array $semantic): string {
    ksort($semantic);
    return hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR));
  }

}
