<?php

declare(strict_types=1);

namespace Drupal\agency_host\Delivery;

use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Access\ActorAccess;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\agency_staff\InvitationCommands;
use Drupal\agency_staff\RelationshipCommands;
use Drupal\agency_staff\RelationshipReads;
use Drupal\agency_host\Console\ConsoleApplication;
use Drupal\agency_host\Console\IdentitySelection;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Owns captured invitation delivery without accessing Agency persistence.
 */
final class InvitationDelivery {

  public const PERMISSION = 'approve agency discovery invitation delivery';

  public function __construct(
    private readonly ConsoleApplication $console,
    private readonly IdentitySelection $selection,
    private readonly AgencyIdentityProvider $identity,
    private readonly RelationshipCommands $commands,
    private readonly RelationshipReads $reads,
    private readonly InvitationCommands $invitations,
    private readonly DeliveryConfiguration $configuration,
    private readonly DeliveryLedger $ledger,
    private readonly InvitationMailInterface $mail,
    private readonly DeliverySuppressionInterface $suppression,
    private readonly AccountProxyInterface $account,
    private readonly LockBackendInterface $lock,
    private readonly ActorAccess $actors,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * Reports configuration only; it grants no authority or transport readiness.
   */
  public function enabled(): bool {
    try {
      $this->configuration->read();
      return TRUE;
    }
    catch (Refusal) {
      return FALSE;
    }
  }

  /**
   * Preserves legacy captured-link commands; mapped generations gain custody.
   */
  public function command(Request $request, string $relationship, string $command, array $intent): array {
    if (!$this->enabled() || !in_array($command, ['issue', 'resend', 'revokeInvitation'], TRUE)) {
      return $this->console->command($request, $relationship, $command, $intent);
    }
    if (in_array($command, ['issue', 'resend'], TRUE) && $this->ledger->mapping($relationship) !== NULL) {
      return $this->capture($request, $relationship, $command, $intent['key'], $intent['version']);
    }
    return $this->console->run($request, fn(): array => $this->locked($relationship,
      fn(): array => $this->console->command($request, $relationship, $command, $intent)), TRUE);
  }

  /**
   * Offers review/recovery links only to a current assigned delivery approver.
   */
  public function listing(Request $request, string $relationship): array {
    return $this->console->run($request, function () use ($relationship): array {
      $this->configuration->read();
      $this->requireApproval();
      $this->authority($relationship, $this->mapping($relationship));
      return ['outcome' => 'available', 'deliveries' => $this->ledger->listing($relationship)];
    });
  }

  /**
   * Warns current owners from retained attempt metadata even after custody ends.
   */
  public function replacementWarning(Request $request, string $relationship): array {
    return $this->console->run($request, function () use ($relationship): array {
      foreach ($this->reads->queue()['contexts'] ?? [] as $context) {
        if ($context['id'] !== $relationship || $context['owner_unavailable']
          || !in_array('resend', $context['permitted_actions'], TRUE)) {
          continue;
        }
        return ['outcome' => 'available', 'prior_handoff' => $this->ledger->hasHandoff($relationship)];
      }
      throw new Refusal('not_found');
    });
  }

  /**
   * Establishes provenance only from an explicit, freshly verified preparation.
   */
  public function prepare(Request $request, array $selection, array $input, string $key): array {
    return $this->console->run($request, function () use ($selection, $input, $key): array {
      $this->configuration->read();
      $id = $this->commandId('prepare', $key);
      return $this->locked($id, function () use ($id, $selection, $input, $key): array {
        return $this->selection->apply($selection, $input['contact']['value'] ?? '', function () use ($id, $selection, $input, $key): array {
          $mapping = [
            'issuer' => $selection['issuer'],
            'subject' => $selection['subject'],
            'contact' => $input['contact'],
          ];
          $fingerprint = DeliveryConfiguration::fingerprint(['mapping' => $mapping, 'input' => $input]);
          $row = $this->ledger->reserve($id, 'preparation', '', $fingerprint, ['mapping' => $mapping]);
          $result = $this->commands->startDiscovery($input, $key);
          if (($result['outcome'] ?? '') === 'committed' && $row['state'] !== 'mapped') {
            $this->ledger->transition($row, 'mapped', ['mapping' => $mapping, 'result' => $result], $result['receipt']['relationship_id']);
          }
          return $result;
        });
      });
    }, TRUE);
  }

  /**
   * Captures one public generation; replay never fabricates lost token custody.
   */
  public function capture(Request $request, string $relationship, string $command, string $key, int $version): array {
    return $this->console->run($request, function () use ($relationship, $command, $key, $version): array {
      $this->configuration->read();
      $this->console->invitationOrigin();
      if (!in_array($command, ['issue', 'resend'], TRUE)) {
        throw new Refusal('invalid');
      }
      return $this->locked($relationship, function () use ($relationship, $command, $key, $version): array {
        $mapping = $this->mapping($relationship);
        $this->authority($relationship, $mapping);
        $id = $this->commandId($relationship . ':' . $command, $key);
        $row = $this->ledger->reserve($id, 'invitation', $relationship,
          DeliveryConfiguration::fingerprint(['command' => $command, 'version' => $version, 'mapping' => $mapping]), ['mapping' => $mapping]);
        if ($row['state'] !== 'reserved') {
          $payload = $this->ledger->payload($row);
          $result = $payload['result'] ?? ['outcome' => $row['state'], 'delivery_id' => $id];
          unset($result['invitation']);
          return $result;
        }
        // Agency owns its root transaction. No host transaction spans this call.
        try {
          $result = $this->invitations->{$command}($relationship, $key, $version);
        }
        catch (Refusal $error) {
          if (in_array($error->reason, ['unavailable', 'identity_unavailable'], TRUE)) {
            throw $error;
          }
          $result = ['outcome' => 'refused', 'reason' => $error->reason];
        }
        if (($result['outcome'] ?? '') !== 'committed') {
          if (in_array($result['outcome'] ?? '', ['refused', 'conflict'], TRUE)
            && !in_array($result['reason'] ?? '', ['unavailable', 'identity_unavailable'], TRUE)) {
            $this->ledger->transition($row, 'refused', ['mapping' => $mapping, 'result' => $result]);
          }
          $result['delivery_id'] = $id;
          return $result;
        }
        $captured = isset($result['invitation']['token']);
        $result['delivery_id'] = $id;
        $result['delivery_state'] = $captured ? 'captured' : 'capture_unavailable';
        $this->ledger->transition($row, $result['delivery_state'], ['mapping' => $mapping, 'result' => $result]);
        return $result;
      });
    }, TRUE);
  }

  /**
   * Returns a freshly authorized exact message without approving or sending it.
   */
  public function preview(Request $request, string $id): array {
    return $this->console->run($request, fn(): array => $this->review($id));
  }

  /**
   * Commits one dispatch fence before handing an immutable envelope to mail.
   */
  public function dispatch(Request $request, string $id, string $fingerprint, string $key): array {
    return $this->console->run($request, function () use ($id, $fingerprint, $key): array {
      $this->configuration->read();
      $row = $this->ledger->read($id);
      if ($row === NULL || $row['kind'] !== 'invitation') {
        throw new Refusal('not_found');
      }
      $this->commandId('approval', $key);
      return $this->locked($row['relationship_id'], function () use ($id, $fingerprint, $key): array {
        $review = $this->review($id);
        if ($review['outcome'] !== 'available') {
          $previous = $this->ledger->payload($this->ledger->read($id))['approval'] ?? NULL;
          if ($previous !== NULL && hash_equals($previous['key_hash'], hash('sha256', $key))
            && !hash_equals($previous['fingerprint'], $fingerprint)) {
            throw new Refusal('idempotency_conflict');
          }
          return $review;
        }
        if (!hash_equals($review['delivery']['fingerprint'], $fingerprint)) {
          throw new Refusal('preview_changed');
        }
        $row = $this->ledger->read($id);
        if ($row['state'] !== 'captured' || (int) $row['revision'] !== $review['delivery']['revision']) {
          throw new Refusal('delivery_conflict');
        }
        $payload = $this->ledger->payload($row);
        // Formatting hooks may be slow. Recheck after them, before the fence.
        $this->requireApproval();
        $this->authority($row['relationship_id'], $payload['mapping']);
        if (!$this->suppression->permits($payload['mapping']['contact']['value'])) {
          throw new Refusal('delivery_suppressed');
        }
        $current = $this->invitations->inspect($payload['result']['invitation']['token']);
        if (($current['status'] ?? '') !== 'open') {
          throw new Refusal('not_found');
        }
        if (!hash_equals($review['delivery']['generation'], DeliveryConfiguration::fingerprint($current))) {
          throw new Refusal('preview_changed');
        }
        // A reused approval key cannot authorize a different delivery/message.
        // This reservation can outlive custody cleanup without storing content.
        $this->ledger->reserve($this->commandId('approval', $key), 'approval', $row['relationship_id'],
          DeliveryConfiguration::fingerprint(['delivery' => $id, 'preview' => $fingerprint]), ['delivery' => $id]);
        $payload['approval'] = [
          'fingerprint' => $fingerprint,
          'key_hash' => hash('sha256', $key),
          'uid' => (int) $this->account->id(),
        ];
        $payload['envelope'] = $review['delivery']['envelope'];
        $row = $this->ledger->transition($row, 'attempting', $payload);
        // A crash from this point is unknown. No retry may cross this fence.
        try {
          $outcome = $this->mail->send($payload['envelope']);
        }
        catch (\Throwable) {
          $outcome = 'unknown';
        }
        if (!in_array($outcome, ['captured', 'accepted'], TRUE)) {
          $outcome = 'unknown';
        }
        $state = $outcome === 'captured' ? 'test_captured' : $outcome;
        try {
          $this->ledger->transition($row, $state, $payload);
        }
        catch (\Throwable) {
          // Preserve the already committed attempting fence and honest outcome.
          return ['outcome' => 'unknown', 'delivery_id' => $id];
        }
        return ['outcome' => $state, 'delivery_id' => $id];
      });
    }, TRUE);
  }

  /**
   * Builds the exact final formatted envelope behind current authority.
   */
  private function review(string $id): array {
    $this->configuration->read();
    $this->requireApproval();
    $row = $this->ledger->read($id);
    if ($row === NULL || $row['kind'] !== 'invitation') {
      throw new Refusal('not_found');
    }
    $payload = $this->ledger->payload($row);
    $this->authority($row['relationship_id'], $payload['mapping']);
    if ($row['state'] !== 'captured') {
      if ($row['state'] === 'refused') {
        return ['outcome' => 'issuance_refused', 'delivery_id' => $id];
      }
      return ['outcome' => $row['state'] === 'attempting' ? 'unknown' : $row['state'], 'delivery_id' => $id];
    }
    $token = $payload['result']['invitation']['token'];
    $invitation = $this->invitations->inspect($token);
    if (($invitation['status'] ?? '') !== 'open') {
      throw new Refusal('not_found');
    }
    $recipient = $payload['mapping']['contact']['value'];
    if (!$this->suppression->permits($recipient)) {
      throw new Refusal('delivery_suppressed');
    }
    $url = $this->console->invitationOrigin() . '/en/invite/' . $token;
    $support = $invitation['support_contact'];
    if (!is_array($support) || !in_array($support['kind'] ?? '', ['email', 'url', 'phone'], TRUE) || !is_string($support['value'] ?? NULL)) {
      throw new Refusal('delivery_unavailable');
    }
    $body = $invitation['agency_name'] . " has invited you to explore working together.\n\n"
      . "Sign in to view your invitation:\n" . $url . "\n\n"
      . "Signing in does not accept the invitation, create an account or profile, grant workspace access, or establish a working relationship.\n\n"
      . 'This invitation expires at ' . gmdate('Y-m-d H:i:s', $invitation['expires']) . " UTC.\n"
      . 'For help: ' . $support['value'];
    $envelope = $this->mail->prepare([
      'recipient' => $recipient,
      'subject' => 'Invitation to explore working together',
      'body' => $body,
      'language' => 'en',
      'expires' => $invitation['expires'],
      'support' => $support,
      'template' => 1,
    ]);
    // Do not expose newly formatted private content after authority changes.
    $this->requireApproval();
    $this->authority($row['relationship_id'], $payload['mapping']);
    if (!$this->suppression->permits($recipient)) {
      throw new Refusal('delivery_suppressed');
    }
    $fresh_invitation = $this->invitations->inspect($token);
    if (($fresh_invitation['status'] ?? '') !== 'open'
      || DeliveryConfiguration::fingerprint($fresh_invitation) !== DeliveryConfiguration::fingerprint($invitation)) {
      throw new Refusal('preview_changed');
    }
    if (($envelope['send'] ?? FALSE) !== TRUE) {
      throw new Refusal('delivery_suppressed');
    }
    return [
      'outcome' => 'available',
      'delivery' => [
        'id' => $id,
        'recipient' => $envelope['recipient'],
        'subject' => $envelope['subject'],
        'relationship' => $row['relationship_id'],
        'revision' => (int) $row['revision'],
        'generation' => DeliveryConfiguration::fingerprint($invitation),
        'body' => $envelope['body'],
        'expires' => $invitation['expires'],
        'envelope' => $envelope,
        'fingerprint' => DeliveryConfiguration::fingerprint(['id' => $id, 'envelope' => $envelope]),
      ],
    ];
  }

  /**
   * Fails closed for all pre-existing or otherwise unmapped relationships.
   */
  private function mapping(string $relationship): array {
    $row = $this->ledger->mapping($relationship);
    if ($row === NULL) {
      throw new Refusal('delivery_unmapped');
    }
    return $this->ledger->payload($row)['mapping'];
  }

  /**
   * Rechecks assignment, exact identity, binding and authoritative permission.
   */
  private function authority(string $relationship, array $mapping): void {
    $queue = $this->reads->queue();
    $assigned = FALSE;
    foreach ($queue['contexts'] ?? [] as $context) {
      if ($context['id'] === $relationship && !$context['owner_unavailable']
        && (in_array('issue', $context['permitted_actions'], TRUE) || in_array('resend', $context['permitted_actions'], TRUE))) {
        $assigned = TRUE;
        break;
      }
    }
    if (!$assigned || $mapping['issuer'] !== (Settings::get('agency_host_identity', [])['issuer'] ?? NULL)) {
      throw new Refusal('not_found');
    }
    $fresh = $this->identity->withIntendedSubject($mapping['subject'], $mapping['contact'], fn(): array => ['outcome' => 'available']);
    if ($fresh['outcome'] !== 'available') {
      throw new Refusal($fresh['reason']);
    }
    if (!$this->identity->mayContact($mapping['contact'])) {
      throw new Refusal('contact_refused');
    }
  }

  /**
   * Requires explicit approval on both the effective and fresh account.
   */
  private function requireApproval(): void {
    // Refresh both role config and entity caches after a slow formatter.
    $fresh = $this->entities->getStorage('user')->loadUnchanged($this->account->id());
    $roles = array_unique(array_merge($this->account->getRoles(), $fresh?->getRoles() ?? []));
    foreach ($roles as $role) {
      $this->config->reset('user.role.' . $role);
    }
    $this->entities->getStorage('user_role')->resetCache($roles);
    $this->actors->coordinator(NULL, self::PERMISSION);
  }

  /**
   * Binds a bounded idempotency key to the initiating coordinator and operation.
   */
  private function commandId(string $scope, string $key): string {
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
      throw new Refusal('invalid');
    }
    return hash('sha256', $this->account->id() . ':' . $scope . ':' . $key);
  }

  /**
   * Serializes this host's operations without claiming a cross-owner mutex.
   */
  private function locked(string $scope, callable $action): array {
    $name = 'agency_host.delivery:' . hash('sha256', $scope);
    if (!$this->lock->acquire($name, 60.0)) {
      throw new Refusal('delivery_busy');
    }
    try {
      return $action();
    }
    finally {
      $this->lock->release($name);
    }
  }

}
