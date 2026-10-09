<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\agency_staff\ObservationRecorder;
use Psr\Log\LoggerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\audit_chain\AuditChainLogger;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\key\Entity\Key;
use Drupal\agency_staff\Identity\Principal;
use Drupal\agency_staff\Policy;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\Tests\agency\Fixtures\DiscoveryIdentityFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

/**
 * Proves the failure boundaries recorded before implementation.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class DiscoveryDomainTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'key',
    'encrypt',
    'audit_chain',
    'field_guard',
    'agency',
    'agency_staff',
  ];

  /**
   * Trusted synthetic identity provider.
   */
  private DiscoveryIdentityFixture $identity;

  /**
   * Explicitly authorized coordinator, distinct from uid 1.
   */
  private User $coordinator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    foreach (DomainStore::TYPES as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('agency_staff', ['agency_staff_mutex', 'agency_staff_outbox', 'agency_staff_intent']);
    $this->container->get('database')->insert('agency_staff_mutex')->fields(['id' => 1, 'value' => 0])->execute();
    $this->installConfig(['system', 'field', 'user', 'audit_chain']);
    $this->config('agency.settings')->setData([
      'display_name' => 'Example Studio',
      'policy' => [
        'invitation_validity' => 'P7D',
        'acknowledgement_period' => 'P2D',
        'follow_up_period' => 'P3D',
        'retention_after_close' => 'P1Y',
        'summary_window' => 'P30D',
        'retained_information_statement' => 'Conversation details.',
        'fallback_contact_route' => 'https://example.test/help',
        'close_reasons' => [['id' => 'not_now', 'label' => 'Not now', 'message' => 'No current next step.']],
      ],
    ])->save();
    // Reserve uid 1 so explicit permissions, not the administrator shortcut,
    // authorize the synthetic coordinator.
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'discovery_coordinator',
      'label' => 'Discovery coordinator',
      'permissions' => [
        'coordinate agency discovery',
        'suspend agency discovery access',
      ],
    ])->save();
    $this->coordinator = User::create([
      'name' => 'example-coordinator',
      'status' => 1,
      'roles' => [
        'discovery_coordinator',
      ],
    ]);
    $this->coordinator->save();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->identity = new DiscoveryIdentityFixture();
    $this->container->set('agency_staff.identity', $this->identity);
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('POST');
  }

  /**
   * Synthetic two-actor flow, stale Stop and terminal-retry denial.
   */
  public function testJourneyAndSummaryDenial(): void {
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    [$id, $version] = $this->accepted();
    $this->assertSame('discovery', $reads->forActor($id)['workspace']['kind']);
    $reply = $commands->respond($id, ['choice' => 'not_now', 'note' => 'Synthetic note.'], 'reply-intent', $version);
    $this->assertSame('committed', $reply['outcome']);
    $this->assertSame('exploring', $reply['workspace']['state']);
    $this->assertSame('agency', $reply['workspace']['waiting_on']);
    $repeat = $commands->respond($id, ['choice' => 'not_now', 'note' => 'Synthetic note.'], 'reply-intent', $version);
    $this->assertSame($reply['receipt'], $repeat['receipt']);
    $this->assertSame('refused', $commands->respond($id, ['choice' => 'yes'], 'reply-intent', $version)['outcome']);
    $this->assertSame('conflict', $commands->pauseRelationship($id, [], 'pause-stale', $version)['outcome']);
    $paused = $commands->pauseRelationship($id, [], 'pause-fresh', $reply['workspace']['version']);
    $this->assertSame('paused', $paused['workspace']['state']);
    $this->assertContains('resumeRelationship', $reads->forActor($id)['workspace']['permitted_actions']);
    $stopped = $commands->withdrawRelationship($id, [], 'stop-intent', $version);
    $this->assertSame('committed', $stopped['outcome']);
    $this->assertArrayNotHasKey('workspace', $stopped);
    $this->assertSame('pending', $stopped['receipt']['evidence']);
    $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
    $this->assertSame($denied, $reads->forActor($id));
    $this->assertSame($denied, $commands->withdrawRelationship($id, [], 'stop-intent', $version));
    $summary = $reads->summary($id)['summary'];
    $this->assertSame('withdrawn', $summary['state']);
    $this->assertArrayNotHasKey('next_action', $summary);
    $this->assertArrayNotHasKey('evidence', $summary);
    $this->identity->principal = new Principal('https://identity.example.test', 'other');
    $this->assertSummaryDenied($id);
    $this->identity->principal = new Principal('https://identity.example.test', 'person-one');
    $suspend = $commands->suspendAccess($id, [], 'suspend-intent', 0);
    $this->assertSame('committed', $suspend['outcome']);
    $this->assertSummaryDenied($id);
    $this->assertSame($denied, $commands->withdrawRelationship($id, [], 'stop-intent', $version));
    $this->assertCount(2, User::loadMultiple(), 'Discovery never provisions a participant user.');
  }

  /**
   * A missing local outbox cannot leave partial records or claim success.
   */
  public function testRequiredEvidenceRollsBack(): void {
    $database = $this->container->get('database');
    $database->schema()->dropTable('agency_staff_outbox');
    $result = $this->container->get('agency_staff.commands')->startDiscovery($this->input(), 'start-intent');
    $this->assertSame(['outcome' => 'refused', 'reason' => 'recording_unavailable'], $result);
    foreach (DomainStore::TYPES as $type) {
      $this->assertSame([], $this->container->get('entity_type.manager')->getStorage($type)->loadMultiple());
    }
  }

  /**
   * Wrong identity, GET and superseded credentials cannot consume an invite.
   */
  public function testInvitationDenialAndAuthority(): void {
    $commands = $this->container->get('agency_staff.commands');
    $invitations = $this->container->get('agency_staff.invitations');
    $start = $commands->startDiscovery($this->input(), 'start-intent');
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    $issued = $invitations->issue($id, 'issue-intent', 1);
    $token = $issued['invitation']['token'];
    $this->container->get('request_stack')->push(Request::create('/', 'GET'));
    $this->assertSame('refused', $invitations->accept($token, 'accept-intent')['outcome']);
    $this->container->get('request_stack')->pop();
    $this->identity->principal = new Principal('https://other.example.test', 'person-one');
    $this->assertSame('wrong_account', $invitations->accept($token, 'accept-intent')['reason']);
    $this->identity->principal = new Principal('https://identity.example.test', 'person-one');
    $resend = $invitations->resend($id, 'resend-intent', $issued['version']);
    $this->assertSame('invitation_closed', $invitations->accept($token, 'accept-intent')['reason']);
    $accepted = $invitations->accept($resend['invitation']['token'], 'accept-intent');
    $this->assertSame('committed', $accepted['outcome']);
    $this->assertSame('already_used', $invitations->accept($resend['invitation']['token'], 'other-accept')['reason']);
    $this->identity->eligible = FALSE;
    $this->assertSame('not_found', $commands->suspendAccess($id, [], 'suspend-denied', 0)['reason']);
    $this->assertSame([], $this->container->get('agency_staff.reads')->forActor('missing')['workspace'] ?? []);
    $relationship = $this->container->get('agency_staff.store')->find('agency_discovery', ['uuid' => $id]);
    $this->assertFalse($relationship->access('view', User::load(1)));
  }

  /**
   * Observation and fallback-logger failure cannot replace the domain refusal.
   */
  public function testObservationSinkFailurePreservesRefusal(): void {
    $database = $this->container->get('database');
    $state = $this->createMock(StateInterface::class);
    $state->method('set')->willThrowException(new \RuntimeException('Synthetic state outage.'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2))->method('warning')->willThrowException(new \RuntimeException('Synthetic logging outage.'));
    $observations = new ObservationRecorder(
      $database, $this->container->get('agency_staff.outbox'), $this->identity,
      $this->container->get('current_user'), $state,
      $this->container->get('datetime.time'), $logger,
    );
    $this->container->set('agency_staff.observations', $observations);
    $database->schema()->dropTable('agency_staff_outbox');
    $result = $this->container->get('agency_staff.commands')->startDiscovery($this->input(), 'start-during-outage');
    $this->assertSame(['outcome' => 'refused', 'reason' => 'recording_unavailable'], $result);
    $this->assertSame([], $this->container->get('agency_staff.store')->all('agency_discovery'));
  }

  /**
   * Terminal ownership recovery cannot extend or recreate summary access.
   */
  public function testTerminalOwnerRecoveryAndSuspension(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    $closed = $commands->close($id, [
      'category' => 'not_now',
      'message' => 'Thank you.',
      'contact' => $this->input()['owner_contact'],
    ], 'close-owner-recovery', $version);
    $before = $reads->summary($id);
    Role::create([
      'id' => 'discovery_recovery',
      'label' => 'Recovery',
      'permissions' => [
        'recover agency discovery ownership',
      ],
    ])->save();
    $next = User::create([
      'name' => 'recovery-coordinator',
      'status' => 1,
      'roles' => [
        'discovery_coordinator',
        'discovery_recovery',
      ],
    ]);
    $next->save();
    $this->coordinator->block()->save();
    $this->container->get('current_user')->setAccount($next);
    $this->assertSame(['setNextAction'], $reads->queue()['contexts'][0]['permitted_actions']);
    $recovered = $commands->setNextAction($id, [
      'owner' => [
        'uid' => (int) $next->id(),
        'contact' => $this->input()['owner_contact'],
      ],
    ], 'recover-terminal-owner', $closed['version']);
    $this->assertSame('committed', $recovered['outcome']);
    $this->assertSame($before, $reads->summary($id), 'Reassignment preserves the exact summary and viewing deadline.');
    $this->assertSame('committed', $commands->suspendAccess($id, [], 'suspend-terminal-summary', 0)['outcome']);
    $this->assertSummaryDenied($id);
  }

  /**
   * Agency obligations sort ahead of overdue optional participant actions.
   */
  public function testQueueResponsibilityOrdering(): void {
    [$participant] = $this->accepted('participant');
    [$agency, $version] = $this->accepted('agency');
    $commands = $this->container->get('agency_staff.commands');
    $action = $this->input()['next_action'];
    $action['side'] = 'agency';
    $this->assertSame('committed', $commands->setNextAction($agency, $action, 'agency-action', $version)['outcome']);
    [$late, $version] = $this->accepted('late');
    $this->assertSame('committed', $commands->setNextAction($late, $action, 'late-action', $version)['outcome']);
    foreach ([$participant, $late] as $id) {
      $row = $this->container->get('agency_staff.store')->find('agency_discovery', ['uuid' => $id]);
      $data = $row->details();
      $data['next_action']['expected_by'] = time() - 60;
      $row->setDetails($data)->save();
    }
    $queue = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->assertSame([$late, $agency, $participant], array_column($queue, 'id'));
    $this->assertTrue($queue[0]['agency_late']);
    $this->assertFalse($queue[2]['agency_late']);
  }

  /**
   * Existing receipts survive elapsed deadlines and later policy changes.
   */
  public function testReplayAfterDeadlineAndPolicyChange(): void {
    $now = time();
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(static function () use (&$now): int {
      return $now;
    });
    $clock->method('getRequestTime')->willReturn($now);
    $this->container->set('datetime.time', $clock);
    $input = $this->input();
    $input['next_action']['expected_by'] = $now + 10;
    $commands = $this->container->get('agency_staff.commands');
    $invitations = $this->container->get('agency_staff.invitations');
    $started = $commands->startDiscovery($input, 'original-start');
    $this->assertSame('committed', $started['outcome']);
    $id = $started['receipt']['relationship_id'];
    $issued = $invitations->issue($id, 'original-issue', $started['version']);
    $now += 60;
    $this->config('agency.settings')->clear('policy.invitation_validity')->save();
    $this->assertSame($started['receipt'], $commands->startDiscovery($input, 'original-start')['receipt']);
    $replay = $invitations->issue($id, 'original-issue', $started['version']);
    $this->assertSame($issued['receipt'], $replay['receipt']);
    $this->assertArrayNotHasKey('invitation', $replay);
    $this->assertSame('policy_unavailable', $invitations->resend($id, 'new-generation', $issued['version'])['reason']);
  }

  /**
   * A prepared response cannot hide consent to a still-current proposal.
   */
  public function testPreparedProposalActions(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $proposal = $commands->moveForward($id, [
      'target_purpose' => 'A discussion',
      'message' => 'Discuss next steps?',
    ], 'proposal-intent', $version);
    $ready = $commands->respond($id, ['choice' => 'ready'], 'ready-intent', $proposal['version']);
    $this->assertSame('prepared', $ready['workspace']['state']);
    $this->assertContains('moveForward', $ready['workspace']['permitted_actions']);
    $consent = $commands->moveForward($id, ['consent' => TRUE], 'consent-intent', $ready['version']);
    $this->assertSame('moved_forward', $consent['workspace']['state']);
  }

  /**
   * Returning participants discover only their currently entitled summaries.
   */
  public function testSummaryListingAndOutageIndistinguishability(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    $commands->close($id, [
      'category' => 'not_now',
      'message' => 'Thank you.',
      'contact' => $this->input()['owner_contact'],
    ], 'close-for-list', $version);
    $this->assertSame([], $reads->forActor()['contexts']);
    $past = $reads->summaries()['contexts'];
    $this->assertCount(1, $past);
    $this->assertSame($id, $past[0]['id']);
    $this->assertSame('closed', $past[0]['summary']['state']);
    $this->identity->principal = new Principal('https://identity.example.test', 'other');
    $this->assertSame([], $reads->summaries()['contexts']);
    $this->identity->principal = NULL;
    $unavailable = ['outcome' => 'refused', 'reason' => 'identity_unavailable'];
    $this->assertSame($unavailable, $reads->summary($id));
    $this->assertSame($unavailable, $reads->summary('guessed-record'));
    $this->assertSame($unavailable, $commands->withdrawRelationship($id, [], 'outage-stop', 0));
    $this->assertSame($unavailable, $commands->withdrawRelationship('guessed-record', [], 'outage-stop', 0));
    $queue = $reads->queue();
    $this->assertSame('identity_unavailable', $queue['alerts'][0]['reason']);
    $database = $this->container->get('database');
    $observations = $database->select('agency_staff_outbox', 'o')->fields('o')->condition('operation', 'access_observation')->execute()->fetchAll();
    $this->assertCount(4, $observations);
    foreach ($observations as $observation) {
      $this->assertSame('', $observation->relationship_uuid);
      $metadata = json_decode($observation->metadata, TRUE);
      $this->assertSame(['event_id', 'reason', 'subject_hash', 'observed_at'], array_keys($metadata));
      $this->assertStringNotContainsString('guessed-record', $observation->metadata);
      $this->assertStringNotContainsString($id, $observation->metadata);
    }
  }

  /**
   * Explicit recovery permission rescues ownerless work without changing pause.
   */
  public function testOwnerlessPausedRecovery(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $pause = $commands->pauseRelationship($id, [], 'pause-before-owner-loss', $version);
    $recovery_role = Role::create([
      'id' => 'discovery_recovery',
      'label' => 'Discovery recovery',
      'permissions' => [
        'recover agency discovery ownership',
      ],
    ]);
    $recovery_role->save();
    $next = User::create([
      'name' => 'recovery-coordinator',
      'status' => 1,
      'roles' => [
        'discovery_coordinator',
        'discovery_recovery',
      ],
    ]);
    $next->save();
    $this->coordinator->block()->save();
    $this->container->get('current_user')->setAccount($next);
    $reads = $this->container->get('agency_staff.reads');
    $queue = $reads->queue()['contexts'];
    $this->assertCount(1, $queue);
    $this->assertTrue($queue[0]['owner_unavailable']);
    $this->assertSame(['setNextAction'], $queue[0]['permitted_actions']);
    $this->assertSame('not_found', $commands->suspendAccess($id, [], 'unassigned-stop', 0)['reason'], 'Recovery does not grant ordinary mutation authority.');
    $result = $commands->setNextAction($id, [
      'owner' => [
        'uid' => (int) $next->id(),
        'contact' => $this->input()['owner_contact'],
      ],
    ], 'recover-owner', $pause['version']);
    $this->assertSame('committed', $result['outcome']);
    $workspace = $reads->forActor($id)['workspace'];
    $this->assertSame('paused', $workspace['state']);
    $this->assertSame('participant', $workspace['pause_origin']);
    $this->assertNull($workspace['next_action']);
    $this->assertSame('recovery-coordinator', $workspace['owner']['name']);
    $this->assertFalse($reads->queue()['contexts'][0]['owner_unavailable']);
  }

  /**
   * Owner transfer validates its target and removes the previous owner's scope.
   */
  public function testOwnerReassignment(): void {
    [$id, $version] = $this->accepted();
    $next = User::create(['name' => 'next-coordinator', 'status' => 1, 'roles' => ['discovery_coordinator']]);
    $next->save();
    $commands = $this->container->get('agency_staff.commands');
    $input = $this->input()['next_action'];
    $input['owner'] = ['uid' => 1, 'contact' => $this->input()['owner_contact']];
    $this->assertSame('refused', $commands->setNextAction($id, $input, 'invalid-owner', $version)['outcome'], 'Uid 1 without explicit permission is not an eligible assignee.');
    $input['owner']['uid'] = (int) $next->id();
    $result = $commands->setNextAction($id, $input, 'reassign-owner', $version);
    $this->assertSame('committed', $result['outcome']);
    $workspace = $this->container->get('agency_staff.reads')->forActor($id)['workspace'];
    $this->assertSame('next-coordinator', $workspace['owner']['name']);
    $this->assertNotNull($workspace['owner_changed_at']);
    $this->assertArrayNotHasKey('uid', $workspace['owner']);
    $this->assertSame('not_found', $commands->suspendAccess($id, [], 'former-owner-stop', 0)['reason']);
    $this->container->get('current_user')->setAccount($next);
    $queue = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->assertCount(1, $queue);
    $this->assertSame('owner_changed', $queue[0]['notification']['code']);
    $this->assertNotContains('respond', $queue[0]['permitted_actions']);
    $this->assertContains('setNextAction', $queue[0]['permitted_actions']);
    $this->assertSame('committed', $commands->suspendAccess($id, [], 'new-owner-stop', 0)['outcome']);
  }

  /**
   * Identity/policy refusal and a failed accept never leave partial activation.
   */
  public function testInvitationAtomicityAndExpiry(): void {
    $commands = $this->container->get('agency_staff.commands');
    $invitations = $this->container->get('agency_staff.invitations');
    $this->identity->contactAllowed = FALSE;
    $this->assertSame('contact_refused', $commands->startDiscovery($this->input(), 'blocked-contact')['reason']);
    $this->identity->contactAllowed = TRUE;
    $this->identity->principal = NULL;
    $this->assertSame('identity_unavailable', $commands->startDiscovery($this->input(), 'missing-identity')['reason']);
    $this->identity->principal = new Principal('https://identity.example.test', 'person-one');
    $this->config('agency.settings')->set('policy.invitation_validity', 'PT0S')->save();
    $this->assertSame('policy_unavailable', $commands->startDiscovery($this->input(), 'zero-policy')['reason']);
    $this->config('agency.settings')->set('policy.invitation_validity', 'P7D')->save();
    $start = $commands->startDiscovery($this->input(), 'start-intent');
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    $issued = $invitations->issue($id, 'issue-intent', 1);
    $database = $this->container->get('database');
    $database->schema()->renameTable('agency_staff_outbox', 'agency_staff_outbox_offline');
    try {
      $this->assertSame('recording_unavailable', $invitations->accept($issued['invitation']['token'], 'failed-accept')['reason']);
      $this->assertSame('not_found', $this->container->get('agency_staff.reads')->forActor($id)['reason']);
      $this->assertSame('open', $invitations->inspect($issued['invitation']['token'])['status']);
    }
    finally {
      $database->schema()->renameTable('agency_staff_outbox_offline', 'agency_staff_outbox');
    }
    $generation = $this->container->get('agency_staff.store')->find('agency_invitation', ['relationship_uuid' => $id]);
    $generation->set('expires', time() - 1)->save();
    $this->identity->principal = new Principal('https://identity.example.test', 'wrong-person');
    $this->assertSame('invitation_closed', $invitations->accept($issued['invitation']['token'], 'expired-accept')['reason']);
    $this->assertSame('closed', $invitations->inspect($issued['invitation']['token'])['status']);
    $this->assertSame('committed', $commands->revokeInvitation($id, [], 'revoke-stale', 0)['outcome']);
    $this->assertSame('invitation_closed', $invitations->accept($issued['invitation']['token'], 'revoked-accept')['reason']);
  }

  /**
   * Required evidence follows its caller's root and nested savepoints exactly.
   */
  public function testRequiredEvidenceSavepoints(): void {
    $database = $this->container->get('database');
    $outbox = $this->container->get('agency_staff.outbox');
    $root = $database->startTransaction();
    $outbox->recordRequired('respond', '11111111-1111-4111-8111-111111111111', str_repeat('a', 64), 'exploring', 'agency');
    $nested = $database->startTransaction();
    $outbox->recordRequired('respond', '11111111-1111-4111-8111-111111111111', str_repeat('a', 64), 'exploring', 'agency');
    $nested->rollBack();
    $root->commitOrRelease();
    $this->assertCount(1, $database->select('agency_staff_outbox', 'o')->fields('o')->execute()->fetchAll());
    $root = $database->startTransaction();
    $nested = $database->startTransaction();
    $outbox->recordRequired('respond', '11111111-1111-4111-8111-111111111111', str_repeat('a', 64), 'exploring', 'agency');
    $nested->commitOrRelease();
    $root->rollBack();
    $this->assertSame(1, (int) $database->select('agency_staff_outbox', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * Each positive mutation rolls back its revision and grant with the outbox.
   */
  public function testPositiveMutationRollback(): void {
    $commands = $this->container->get('agency_staff.commands');
    $database = $this->container->get('database');
    foreach ([
      'respond',
      'setNextAction',
      'pauseRelationship',
      'resumeRelationship',
      'close',
      'moveForward',
    ] as $operation) {
      [$id, $version] = $this->accepted($operation);
      $input = match ($operation) {
        'respond' => ['choice' => 'ready'],
        'setNextAction' => $this->input()['next_action'],
        'close' => [
          'category' => 'not_now',
          'message' => 'A synthetic closing message.',
          'contact' => $this->input()['owner_contact'],
        ],
        'moveForward' => ['target_purpose' => 'Another conversation', 'message' => 'Discuss a possible next step.'],
        default => [],
      };
      if ($operation === 'resumeRelationship') {
        $pause = $commands->pauseRelationship($id, [], 'prepare-pause', $version);
        $version = $pause['version'];
      }
      $before = $this->container->get('agency_staff.reads')->forActor($id);
      $database->schema()->renameTable('agency_staff_outbox', 'agency_staff_outbox_offline');
      try {
        $result = $commands->$operation($id, $input, 'failed-intent', $version);
        $this->assertSame(['outcome' => 'refused', 'reason' => 'recording_unavailable'], $result, $operation);
        $this->assertSame($before, $this->container->get('agency_staff.reads')->forActor($id), $operation);
      }
      finally {
        $database->schema()->renameTable('agency_staff_outbox_offline', 'agency_staff_outbox');
      }
    }
  }

  /**
   * Agency pause and explicit proposal consent are governed by current state.
   */
  public function testPauseAndProposalAuthority(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    $pause = $commands->pauseRelationship($id, [
      'side' => 'agency',
      'summary' => 'An update from the agency.',
      'why_it_helps' => 'Confirm whether to continue.',
      'expected_by' => time() + 3600,
    ], 'agency-pause', $version);
    $this->assertSame('committed', $pause['outcome']);
    $workspace = $reads->forActor($id)['workspace'];
    $this->assertSame(['withdrawRelationship'], $workspace['permitted_actions']);
    $this->assertNotNull($workspace['next_action']['expected_by']);
    $this->assertSame('refused', $commands->resumeRelationship($id, [], 'wrong-resume', $pause['version'])['outcome']);
    $resume = $commands->resumeRelationship($id, ['side' => 'agency'], 'agency-resume', $pause['version']);
    $this->assertSame('committed', $resume['outcome']);
    $proposal = $commands->moveForward($id, [
      'target_purpose' => 'Another conversation',
      'message' => 'Would you like to discuss it?',
    ], 'propose-one', $resume['version']);
    $decline = $commands->respond($id, ['choice' => 'decline_proposal'], 'decline-one', $proposal['version']);
    $this->assertSame('exploring', $decline['workspace']['state']);
    $this->assertNull($decline['workspace']['proposal']);
    $this->assertSame('agency', $decline['workspace']['waiting_on']);
    $this->assertSame('refused', $commands->moveForward($id, [
      'consent' => TRUE,
    ], 'no-proposal', $decline['version'])['outcome']);
    $proposal = $commands->moveForward($id, [
      'target_purpose' => 'Another conversation',
      'message' => 'Would you like to discuss it?',
    ], 'propose-two', $decline['version']);
    $this->assertSame('conflict', $commands->moveForward($id, [
      'consent' => TRUE,
    ], 'consent-two', $decline['version'])['outcome']);
    $consent = $commands->moveForward($id, ['consent' => TRUE], 'consent-two', $proposal['version']);
    $this->assertSame('moved_forward', $consent['workspace']['state']);
    $this->assertSame([], $consent['workspace']['permitted_actions']);
    $this->assertCount(2, User::loadMultiple());
  }

  /**
   * Failed Stop plus access loss never becomes a claimed successful withdrawal.
   */
  public function testDenialIntentFailureAndUnknownRecovery(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    $database = $this->container->get('database');
    $database->schema()->renameTable('agency_staff_outbox', 'agency_staff_outbox_offline');
    try {
      $result = $commands->withdrawRelationship($id, [], 'failed-stop', 0);
      $this->assertSame(['outcome' => 'refused', 'reason' => 'recording_unavailable'], $result);
      $this->assertSame('exploring', $reads->forActor($id)['workspace']['state']);
    }
    finally {
      $database->schema()->renameTable('agency_staff_outbox_offline', 'agency_staff_outbox');
    }
    $this->assertSame('committed', $commands->suspendAccess($id, [], 'security-stop', 0)['outcome']);
    $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
    $this->assertSame($denied, $commands->withdrawRelationship($id, [], 'failed-stop', $version));
    $this->assertSummaryDenied($id);
    $this->assertSame($denied, $reads->forActor($id));
    $row = $this->container->get('agency_staff.store')->find('agency_discovery', ['uuid' => $id]);
    $this->assertSame('exploring', $row->scalar('state'), 'Access loss does not establish a terminal relationship state.');
    $closed = $commands->close($id, [
      'category' => 'not_now',
      'message' => 'A closing message.',
      'contact' => $this->input()['owner_contact'],
    ], 'close-after-security', (int) $row->scalar('version'));
    $this->assertSame('committed', $closed['outcome']);
    $this->assertSame($denied, $reads->summary($id), 'Closing never revives security-denied access.');
  }

  /**
   * Expiry, deactivation, rebinding and missing summary policy fail closed.
   */
  public function testSummaryBoundaries(): void {
    [$id, $version] = $this->accepted();
    $commands = $this->container->get('agency_staff.commands');
    $reads = $this->container->get('agency_staff.reads');
    $closed = $commands->close($id, [
      'category' => 'not_now',
      'message' => 'Thank you for the conversation.',
      'contact' => $this->input()['owner_contact'],
    ], 'close-intent', $version);
    $this->assertSame('committed', $closed['outcome']);
    $summary = $reads->summary($id)['summary'];
    $this->assertSame('Thank you for the conversation.', $summary['message']);
    $this->assertSame([
      'state',
      'effective_at',
      'retained_information',
      'retention_period',
      'contact_route',
      'message',
      'readable_until',
    ], array_keys($summary));
    $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
    $this->identity->principal = new Principal('https://identity.example.test', 'person-one', FALSE);
    $this->assertSummaryDenied($id);
    $this->identity->principal = new Principal('https://identity.example.test', 'rebound-person');
    $this->assertSummaryDenied($id);
    $this->identity->principal = new Principal('https://identity.example.test', 'person-one');
    $grant = $this->container->get('agency_staff.store')->find('agency_entitlement', ['relationship_uuid' => $id]);
    $grant->set('readable_until', time() - 1)->save();
    $this->assertSummaryDenied($id);
    [$second] = $this->accepted('no-summary');
    $this->config('agency.settings')->clear('policy.summary_window')->save();
    $stopped = $commands->withdrawRelationship($second, [], 'stop-without-summary', 0);
    $this->assertSame('committed', $stopped['outcome']);
    $this->assertNull($stopped['receipt']['readable_until']);
    $this->assertSame($denied, $reads->forActor($second));
    $this->assertSame($denied, $reads->summary($second));
  }

  /**
   * Local intent survives missing keys, then drains once via the public logger.
   */
  public function testEvidenceDeliveryAndPrivacy(): void {
    [$id] = $this->accepted();
    $this->installChain();
    $outbox = $this->container->get('agency_staff.outbox');
    $this->assertSame(0, $outbox->drain());
    $this->assertTrue($outbox->pending($id));
    $this->signingKey();
    $this->assertSame(3, $outbox->drain());
    $this->assertFalse($outbox->pending($id));
    $this->assertSame(0, $outbox->drain());
    $database = $this->container->get('database');
    $rows = $database->select('audit_chain_log', 'l')->fields('l')->execute()->fetchAll();
    $this->assertCount(3, $rows);
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
    foreach ($database->select('agency_staff_outbox', 'o')->fields('o', [
      'metadata',
    ])->execute()->fetchCol() as $metadata) {
      $this->assertSame([
        'event_id',
        'relationship_id',
        'subject_hash',
        'state',
        'waiting_on',
        'effective_at',
      ], array_keys(json_decode($metadata, TRUE)));
      foreach (['person@example.test', 'Choose whether', 'identity.example.test', 'person-one'] as $forbidden) {
        $this->assertStringNotContainsString($forbidden, $metadata);
        $this->assertStringNotContainsString($forbidden, json_encode($rows));
      }
    }
  }

  /**
   * Failure of the delivery marker rolls the real append back as well.
   */
  public function testDeliveryMarkerFailure(): void {
    [$id] = $this->accepted();
    $this->installChain();
    $this->signingKey();
    $this->installFailureConstraint('agency_staff_outbox', 'delivered IS NOT NULL');
    try {
      $this->assertSame(0, $this->container->get('agency_staff.outbox')->drain());
      $this->assertSame(0, (int) $this->container->get('database')->select('audit_chain_log', 'l')->countQuery()->execute()->fetchField());
      $this->assertTrue($this->container->get('agency_staff.outbox')->pending($id));
    }
    finally {
      $this->removeFailureConstraint('agency_staff_outbox');
    }
    $this->assertSame(3, $this->container->get('agency_staff.outbox')->drain());
    $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
  }

  /**
   * A summary grant failure releases its savepoint without undoing Stop.
   */
  public function testSummarySavepointFailure(): void {
    [$id] = $this->accepted();
    $this->installFailureConstraint('agency_entitlement', "status = 'summary_only'");
    try {
      $result = $this->container->get('agency_staff.commands')->withdrawRelationship($id, [], 'stop-grant-failure', 0);
      $this->assertSame('committed', $result['outcome'], json_encode($result));
      $this->assertNull($result['receipt']['readable_until']);
      $denied = ['outcome' => 'refused', 'reason' => 'not_found'];
      $this->assertSame($denied, $this->container->get('agency_staff.reads')->forActor($id));
      $this->assertSame($denied, $this->container->get('agency_staff.reads')->summary($id));
      $this->assertSame(1, (int) $this->container->get('database')->select('agency_staff_outbox', 'o')->condition('operation', 'withdrawRelationship')->countQuery()->execute()->fetchField());
    }
    finally {
      $this->removeFailureConstraint('agency_entitlement');
    }
  }

  /**
   * Injects a database write failure using synthetic test-only SQL.
   */
  private function installFailureConstraint(string $table, string $condition): void {
    $database = $this->container->get('database');
    $this->assertContains($database->driver(), ['pgsql', 'mysql']);
    $name = 'agency_failure_' . substr(hash('sha256', $database->getPrefix()), 0, 12);
    $database->query('ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $name . ' CHECK (NOT (' . $condition . '))');
  }

  /**
   * Removes only the deliberately installed test fault.
   */
  private function removeFailureConstraint(string $table): void {
    $database = $this->container->get('database');
    $name = 'agency_failure_' . substr(hash('sha256', $database->getPrefix()), 0, 12);
    $clause = $database->driver() === 'mysql' ? ' DROP CHECK ' : ' DROP CONSTRAINT ';
    $database->query('ALTER TABLE {' . $table . '}' . $clause . $name);
  }

  /**
   * Two independent processes cannot deliver one queued intent twice.
   */
  public function testConcurrentDrainersCommit(): void {
    $this->concurrentWriters('outbox', FALSE);
  }

  /**
   * A competing drainer recovers the rolled-back append and marker together.
   */
  public function testConcurrentDrainersRollback(): void {
    $this->concurrentWriters('outbox', TRUE);
  }

  /**
   * The domain mutex blocks the second independent connection until commit.
   */
  public function testConcurrentDomainCommit(): void {
    $this->concurrentWriters('domain', FALSE);
  }

  /**
   * The domain mutex also releases correctly after transaction rollback.
   */
  public function testConcurrentDomainRollback(): void {
    $this->concurrentWriters('domain', TRUE);
  }

  /**
   * Claims and appends exercise actual database locks, not mocked persistence.
   */
  private function concurrentWriters(string $mode, bool $rollback): void {
    $database = $this->container->get('database');
    $this->assertContains($database->driver(), [
      'pgsql',
      'mysql',
    ], 'Concurrency proof requires a supported database engine.');
    if ($mode === 'outbox') {
      $this->installChain();
      $this->signingKey();
      $transaction = $database->startTransaction();
      $this->container->get('agency_staff.outbox')->recordRequired('respond', '11111111-1111-4111-8111-111111111111', str_repeat('a', 64), 'exploring', 'agency');
      $transaction->commitOrRelease();
    }
    $prefix = sys_get_temp_dir() . '/agency-concurrency-' . bin2hex(random_bytes(8));
    $ready = $prefix . '-ready';
    $release = $prefix . '-release';
    $started = $prefix . '-started';
    $started_second = $prefix . '-second';
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $fixture = dirname(__DIR__, 2) . '/fixtures/discovery-worker.php';
    $first = new Process([PHP_BINARY, $fixture, $this->root, $vendor]);
    $second = new Process([PHP_BINARY, $fixture, $this->root, $vendor]);
    $base = [
      'mode' => $mode,
      'database' => $database->getConnectionOptions(),
      'namespaces' => [
        'Drupal\\agency_staff\\' => dirname((new \ReflectionClass(DomainStore::class))->getFileName(), 2),
        'Drupal\\audit_chain\\' => dirname((new \ReflectionClass(AuditChainLogger::class))->getFileName()),
        'Drupal\\key\\' => dirname((new \ReflectionClass(Key::class))->getFileName(), 2),
        'Drupal\\encrypt\\' => dirname((new \ReflectionClass(EncryptServiceInterface::class))->getFileName()),
      ],
    ];
    $first->setInput(json_encode($base + [
      'started' => $started,
      'ready' => $ready,
      'release' => $release,
      'rollback' => $rollback,
    ], JSON_THROW_ON_ERROR));
    $second->setInput(json_encode($base + ['started' => $started_second], JSON_THROW_ON_ERROR));
    $first->setTimeout(30);
    $second->setTimeout(30);
    try {
      $first->start();
      $this->waitForWriter($first, $ready);
      $second->start();
      $this->waitForWriter($second, $started_second);
      usleep(300000);
      $this->assertTrue($second->isRunning(), 'The second connection must wait for the first transaction.');
    }
    finally {
      file_put_contents($release, 'release');
      foreach ([$first, $second] as $process) {
        if ($process->isStarted()) {
          $process->wait();
        }
      }
      foreach ([$ready, $release, $started, $started_second] as $path) {
        if (is_file($path)) {
          unlink($path);
        }
      }
    }
    $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput() . $first->getOutput());
    $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput() . $second->getOutput());
    if ($mode === 'outbox') {
      $this->assertSame(1, (int) $database->select('audit_chain_log', 'l')->countQuery()->execute()->fetchField());
      $this->assertSame(1, (int) $database->select('agency_staff_outbox', 'o')->isNotNull('delivered')->countQuery()->execute()->fetchField());
      $this->assertSame(0, $this->container->get('agency_staff.outbox')->drain());
      $this->assertTrue($this->container->get('audit_chain.logger')->verify()['ok']);
    }
    else {
      $this->assertSame($rollback ? 'refused' : 'committed', json_decode($first->getOutput(), TRUE)['outcome']);
      $this->assertSame('committed', json_decode($second->getOutput(), TRUE)['outcome']);
    }
  }

  /**
   * Requires a worker checkpoint without masking process failure as a timeout.
   */
  private function waitForWriter(Process $process, string $path): void {
    $deadline = microtime(TRUE) + 10;
    while (!is_file($path)) {
      if (!$process->isRunning() || microtime(TRUE) >= $deadline) {
        $this->fail('Writer did not reach its checkpoint: ' . $process->getErrorOutput() . $process->getOutput());
      }
      usleep(20000);
    }
  }

  /**
   * Installs the public logger's schema without invoking any delivery provider.
   */
  private function installChain(): void {
    $this->installSchema('audit_chain', [
      'audit_chain_log',
      'audit_chain_mutex',
      'audit_chain_recovery',
      'audit_chain_checkpoint',
      'audit_chain_witness_receipt',
    ]);
    $this->container->get('database')->insert('audit_chain_mutex')->fields(['id' => 1, 'locked' => 1])->execute();
  }

  /**
   * Configures only synthetic signing material in the disposable database.
   */
  private function signingKey(): void {
    Key::create([
      'id' => 'discovery_test',
      'label' => 'Synthetic Discovery test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-discovery-test-key'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'discovery_test')->save();
  }

  /**
   * Calendar arithmetic never silently overflows a month or accepts zero.
   */
  public function testPolicyCalendarRules(): void {
    $this->assertSame(strtotime('2027-02-28T12:00:00Z'), Policy::addPeriod('P1M', strtotime('2027-01-31T12:00:00Z')));
    $this->assertSame(strtotime('2025-02-28T12:00:00Z'), Policy::addPeriod('P1Y', strtotime('2024-02-29T12:00:00Z')));
    $this->assertSame(101, Policy::addPeriod('PT0.5S', 100));
    $this->expectException(Refusal::class);
    Policy::addPeriod('PT0S', 100);
  }

  /**
   * Creates and accepts a synthetic relationship through the real commands.
   */
  private function accepted(string $suffix = ''): array {
    $start = $this->container->get('agency_staff.commands')->startDiscovery($this->input(), 'start-intent' . $suffix);
    $this->assertSame('committed', $start['outcome'], json_encode($start));
    $id = $start['receipt']['relationship_id'];
    $issued = $this->container->get('agency_staff.invitations')->issue($id, 'issue-intent', 1);
    $this->assertSame('committed', $issued['outcome'], json_encode($issued));
    $accepted = $this->container->get('agency_staff.invitations')->accept($issued['invitation']['token'], 'accept-intent');
    $this->assertSame('committed', $accepted['outcome'], json_encode($accepted));
    return [$id, $accepted['workspace']['version']];
  }

  /**
   * Rechecks a terminal capability after an external identity/storage change.
   */
  private function assertSummaryDenied(string $id): void {
    $actual = $this->container->get('agency_staff.reads')->summary($id);
    $this->assertSame(['outcome' => 'refused', 'reason' => 'not_found'], $actual);
  }

  /**
   * Supplies bounded synthetic input with an optional participant action.
   */
  private function input(): array {
    return [
      'display_name' => 'Example Person',
      'contact' => ['kind' => 'email', 'value' => 'person@example.test'],
      'owner_contact' => ['kind' => 'url', 'value' => 'https://example.test/help'],
      'next_action' => [
        'summary' => 'Choose whether to talk.',
        'why_it_helps' => 'Learn what happens next.',
        'side' => 'participant',
        'expected_by' => time() + 3600,
        'optional' => TRUE,
      ],
    ];
  }

}
