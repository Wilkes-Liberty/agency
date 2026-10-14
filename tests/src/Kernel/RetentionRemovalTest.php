<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel;

use Drupal\agency_staff\Entity\InternalRecord;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\agency_staff\Identity\Principal;
use Drupal\agency_staff\Storage\DomainStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\agency\Fixtures\DiscoveryIdentityFixture;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Process\Process;

/**
 * Exercises the removal failures declared before the deletion implementation.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
class RetentionRemovalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'key', 'encrypt',
    'audit_chain', 'field_guard', 'agency', 'agency_staff',
  ];

  /**
   * Deterministic synthetic clock.
   */
  private int $now = 1700000000;

  /**
   * Trusted test-only identity boundary.
   */
  private DiscoveryIdentityFixture $identity;

  /**
   * Explicitly authorized non-administrator.
   */
  private User $operator;

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
    $database = $this->container->get('database');
    $database->insert('agency_staff_mutex')->fields(['id' => 1, 'value' => 0])->execute();
    $this->installConfig(['system', 'field', 'user', 'audit_chain']);
    $this->config('agency.settings')->setData([
      'display_name' => 'Example Studio',
      'policy' => [
        'invitation_validity' => 'P7D',
        'acknowledgement_period' => 'P1D',
        'follow_up_period' => 'P3D',
        'summary_window' => 'PT12H',
        'retention_after_close' => 'P1D',
        'retained_information_statement' => 'Synthetic test policy.',
        'fallback_contact_route' => ['kind' => 'url', 'value' => 'https://example.test/help'],
        'close_reasons' => [['id' => 'not_now', 'label' => 'Not now', 'message' => 'No next step.']],
      ],
    ])->save();
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'retention_operator',
      'label' => 'Retention operator',
      'permissions' => ['remove expired agency discovery', 'coordinate agency discovery'],
    ])->save();
    $this->operator = User::create([
      'name' => 'synthetic-retention-operator',
      'status' => 1,
      'roles' => ['retention_operator'],
    ]);
    $this->operator->save();
    $this->container->get('current_user')->setAccount($this->operator);
    $this->identity = new DiscoveryIdentityFixture();
    $this->container->set('agency_staff.identity', $this->identity);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getRequestTime')->willReturn($this->now);
    $this->container->set('datetime.time', $clock);
    $this->container->get('request_stack')->getCurrentRequest()->setMethod('POST');
    Database::addConnectionInfo('retention_verifier', 'default', $database->getConnectionOptions());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Database::removeConnection('retention_verifier');
    parent::tearDown();
  }

  /**
   * Deletes owned history atomically and retains a minimal replay receipt.
   */
  public function testCompleteRemovalAndAuthorizedReplay(): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $before = $this->businessHash();
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('prepared', $prepared['outcome']);
    $this->assertSame($before, $this->businessHash());
    $this->assertDoesNotMatchRegularExpression('/Synthetic personal|person-one|identity.example|token-value/', json_encode($prepared));
    $result = $service->remove($record->uuid(), $prepared['plan_sha256'], 'remove-exact-1');
    $this->assertSame('committed', $result['outcome']);
    $this->assertTrue($result['receipt']['removed']);
    foreach ([...DomainStore::TYPES, 'agency_discovery_revision'] as $table) {
      $this->assertSame(0, $this->countRows($table), $table);
    }
    $this->assertSame(1, $this->countRows('agency_staff_intent'));
    $this->assertSame(1, $this->countRows('agency_staff_outbox', ['operation' => 'removeExpiredDiscovery']));
    $this->assertSame($result, $service->remove($record->uuid(), $prepared['plan_sha256'], 'remove-exact-1'));
    $this->assertSame('refused', $service->remove($record->uuid(), $prepared['plan_sha256'], 'different-key')['outcome']);
    $this->identity->eligible = FALSE;
    $this->assertSame('not_found', $service->remove($record->uuid(), $prepared['plan_sha256'], 'remove-exact-1')['reason']);
  }

  /**
   * Current explicit authority is required before inventory and receipt reads.
   */
  public function testAuthorizationAndStalePlans(): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $role = Role::load('retention_operator');
    $role->revokePermission('remove expired agency discovery')->grantPermission('maintain agency discovery retention')->save();
    $before = $this->businessHash();
    $this->assertSame('not_found', $service->prepare($record->uuid())['reason']);
    $this->assertSame($before, $this->businessHash());
    $role->grantPermission('remove expired agency discovery')->save();
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('prepared', $prepared['outcome']);
    $this->operator->block()->save();
    $this->assertSame('not_found', $service->remove($record->uuid(), $prepared['plan_sha256'], 'blocked-operator')['reason']);
    $this->operator->activate()->save();
    $this->config('agency.settings')->set('policy.retention_after_close', 'PT24H')->save();
    $this->assertSame('plan_changed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'changed-policy')['reason']);
    $prepared = $service->prepare($record->uuid());
    $record->setDetails(['terminal_at' => $this->now - 86400, 'note' => 'Changed synthetic note.']);
    $record->setNewRevision(TRUE);
    $record->save();
    $this->assertSame('plan_changed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'changed-revision')['reason']);
    $this->assertSame(1, $this->countRows('agency_discovery'));
  }

  /**
   * State, history and exact deadlines are checked before preparing removal.
   */
  public function testDeadlineAndUnknownHistory(): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $this->now--;
    $this->assertSame('not_due', $service->prepare($record->uuid())['reason']);
    $this->now++;
    $this->assertSame('prepared', $service->prepare($record->uuid())['outcome']);
    foreach (['exploring', 'moved_forward'] as $state) {
      $record->set('state', $state)->save();
      $this->assertSame('not_due', $service->prepare($record->uuid())['reason']);
    }
    $record->set('state', 'closed');
    foreach ([NULL, '1699913600', $this->now + 1] as $terminal) {
      $record->setDetails(['terminal_at' => $terminal])->save();
      $this->assertSame('review_required', $service->prepare($record->uuid())['reason']);
    }
    $this->assertSame(1, $this->countRows('agency_discovery'));
  }

  /**
   * A historical reference protects shared identity and person data.
   */
  public function testHistoricalReferenceAndChangedSharing(): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    $other = $record->createDuplicate();
    $other->set('state', 'exploring')->save();
    $other->setNewRevision(TRUE);
    $other->set('person_uuid', '11111111-1111-4111-8111-111111111111');
    $other->set('principal_uuid', '22222222-2222-4222-8222-222222222222');
    $other->save();
    $this->assertSame('plan_changed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'new-reference')['reason']);
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('committed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'shared-remove')['outcome']);
    $this->assertSame(1, $this->countRows('agency_person'));
    $this->assertSame(1, $this->countRows('agency_principal'));
    $this->assertSame(1, $this->countRows('agency_discovery'));
    $this->assertSame(2, $this->countRows('agency_discovery_revision'));
  }

  /**
   * Removing personal fields must not reactivate an inactive principal key.
   */
  public function testInactiveBindingKeepsDenial(): void {
    $record = $this->terminal();
    $store = $this->container->get('agency_staff.store');
    $principal = $store->find('agency_principal', ['uuid' => $record->scalar('principal_uuid')]);
    $principal->set('status', 'inactive')->save();
    $key = $principal->scalar('principal_key');
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('committed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'inactive-remove')['outcome']);
    $retained = $store->find('agency_principal', ['principal_key' => $key]);
    $this->assertSame('inactive', $retained->scalar('status'));
    foreach (['issuer', 'subject', 'person_uuid'] as $field) {
      $this->assertSame('', $retained->scalar($field));
    }
    $this->assertSame(0, $this->countRows('agency_person'));
    $this->assertSame('not_found', $this->container->get('agency_staff.commands')
      ->startDiscovery(['contact' => ['kind' => 'email', 'value' => 'synthetic@example.test']], 'inactive-reinvite')['reason']);
  }

  /**
   * A malformed active binding also carries a denial that must not disappear.
   */
  public function testMalformedActiveBindingRequiresReview(): void {
    $record = $this->terminal();
    $principal = $this->container->get('agency_staff.store')->find('agency_principal', ['uuid' => $record->scalar('principal_uuid')]);
    $service = $this->container->get('agency_staff.retention_removal');
    $commands = $this->container->get('agency_staff.commands');
    foreach (['', 'another-subject'] as $subject) {
      $principal->set('subject', $subject)->save();
      $input = ['contact' => ['kind' => 'email', 'value' => 'synthetic@example.test']];
      $this->assertSame('not_found', $commands->startDiscovery($input, 'mismatch-before')['reason']);
      $this->assertSame('review_required', $service->prepare($record->uuid())['reason']);
      $this->assertSame('review_required', $service->remove($record->uuid(), str_repeat('a', 64), 'mismatch-remove')['reason']);
      $this->assertSame('not_found', $commands->startDiscovery($input, 'mismatch-after')['reason']);
      $this->assertSame(1, $this->countRows('agency_principal'));
    }
  }

  /**
   * Orphan children, including null relationships, preserve their principal.
   */
  public function testOrphanChildAndOtherPrincipalSharing(): void {
    $record = $this->terminal();
    $store = $this->container->get('agency_staff.store');
    $orphan = $store->create('agency_invitation', [
      'principal_uuid' => $record->scalar('principal_uuid'),
      'status' => 'revoked',
      'token_hash' => hash('sha256', 'synthetic-orphan'),
    ]);
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('preserve', $prepared['principal_action']);
    $this->assertSame('preserve', $prepared['person_action']);
    $orphan->delete();
    $store->create('agency_principal', [
      'principal_key' => str_repeat('b', 64),
      'person_uuid' => $record->scalar('person_uuid'),
      'status' => 'inactive',
    ]);
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('delete', $prepared['principal_action']);
    $this->assertSame('preserve', $prepared['person_action']);
    $this->assertSame('committed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'other-binding')['outcome']);
    $this->assertSame(1, $this->countRows('agency_person'));
    $this->assertSame(1, $this->countRows('agency_principal'));
  }

  /**
   * Too many owned children cannot yield a partial removal plan.
   */
  public function testInventoryOverflowRequiresReview(): void {
    $record = $this->terminal();
    $store = $this->container->get('agency_staff.store');
    for ($i = 0; $i < 100; $i++) {
      $store->create('agency_invitation', [
        'relationship_uuid' => $record->uuid(),
        'principal_uuid' => $record->scalar('principal_uuid'),
        'status' => 'revoked',
        'token_hash' => hash('sha256', 'synthetic-overflow-' . $i),
      ]);
    }
    $before = $this->businessHash();
    $this->assertSame('review_required', $this->container->get('agency_staff.retention_removal')->prepare($record->uuid())['reason']);
    $this->assertSame($before, $this->businessHash());
  }

  /**
   * Real invitation and start-command retries cannot restore removed records.
   */
  public function testOldCommandAndInvitationRetriesStayClosed(): void {
    $commands = $this->container->get('agency_staff.commands');
    $invitations = $this->container->get('agency_staff.invitations');
    $input = [
      'display_name' => 'Synthetic person',
      'contact' => ['kind' => 'email', 'value' => 'synthetic@example.test'],
      'owner_contact' => ['kind' => 'url', 'value' => 'https://example.test/help'],
      'next_action' => [
        'summary' => 'Choose whether to talk.',
        'why_it_helps' => 'Learn the next step.',
        'side' => 'participant',
        'expected_by' => $this->now + 3600,
        'optional' => TRUE,
      ],
    ];
    $start = $commands->startDiscovery($input, 'actual-start');
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    $issued = $invitations->issue($id, 'actual-issue', 1);
    $this->assertSame('committed', $issued['outcome']);
    $accepted = $invitations->accept($issued['invitation']['token'], 'actual-accept');
    $this->assertSame('committed', $accepted['outcome']);
    $closed = $commands->close($id, [
      'category' => 'not_now',
      'message' => 'Synthetic completion.',
      'contact' => $input['owner_contact'],
    ], 'actual-close', $accepted['version']);
    $this->assertSame('committed', $closed['outcome']);
    $this->now += 86400;
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($id);
    $this->assertSame('committed', $service->remove($id, $prepared['plan_sha256'], 'actual-remove')['outcome']);
    $this->assertSame('not_found', $commands->startDiscovery($input, 'actual-start')['reason']);
    $this->assertSame('invitation_closed', $invitations->accept($issued['invitation']['token'], 'actual-accept')['reason']);
    $this->assertSame(0, $this->countRows('agency_discovery'));
    $this->assertSame(0, $this->countRows('agency_person'));
  }

  /**
   * An evidence persistence error rolls back deletion on a second connection.
   */
  public function testEvidenceFailureRollsBackRemoval(): void {
    $this->failedPersistence('agency_staff_outbox', "operation <> 'removeExpiredDiscovery'");
  }

  /**
   * A receipt persistence error also rolls back both deletion and evidence.
   */
  public function testReceiptFailureRollsBackRemoval(): void {
    $this->failedPersistence('agency_staff_intent', "receipt NOT LIKE '%\"removed\":true%'");
  }

  /**
   * A rollback observation cannot turn its root callback into success.
   */
  public function testFailureCallbackPrecedesObservation(): void {
    $database = $this->container->get('database');
    foreach ([FALSE, TRUE] as $refusal) {
      $callbacks = [];
      $result = $this->container->get('agency_staff.store')->transaction(static function () use ($database, &$callbacks, $refusal): array {
        $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
          $callbacks[] = $success;
        });
        if ($refusal) {
          throw new Refusal('not_found');
        }
        throw new \RuntimeException('Synthetic failure before evidence observation.');
      });
      $this->assertSame('refused', $result['outcome']);
      $this->assertSame([FALSE], $callbacks);
      $this->assertFalse($database->inTransaction());
    }
  }

  /**
   * Discarding an application response does not create another removal.
   */
  public function testLostApplicationResponse(): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    // Deliberately discard the response after a real commit. This models the
    // application response being lost, not a PDO COMMIT acknowledgement fault.
    $service->remove($record->uuid(), $prepared['plan_sha256'], 'lost-response');
    $database = Database::getConnection('default', 'retention_verifier');
    $receipt = json_decode($database->select('agency_staff_intent', 'i')->fields('i', ['receipt'])->execute()->fetchField(), TRUE, 16, JSON_THROW_ON_ERROR);
    $this->assertFalse($this->container->get('database')->inTransaction());
    $before = $this->businessHash();
    $this->assertSame(['outcome' => 'committed', 'receipt' => $receipt], $service->remove($record->uuid(), $prepared['plan_sha256'], 'lost-response'));
    $this->assertSame($before, $this->businessHash());
    $this->assertSame(0, $this->countRows('agency_discovery'));
    $this->assertSame(1, $this->countRows('agency_staff_intent'));
    $this->assertSame(1, $this->countRows('agency_staff_outbox', ['operation' => 'removeExpiredDiscovery']));
  }

  /**
   * A committed reference arriving while removal waits invalidates its plan.
   */
  public function testConcurrentReferenceCommit(): void {
    $this->concurrentWriter(FALSE);
  }

  /**
   * A rolled-back reference cannot become a reason to retain unshared data.
   */
  public function testConcurrentReferenceRollback(): void {
    $this->concurrentWriter(TRUE);
  }

  /**
   * A longer policy committed while removal waits prevents early deletion.
   */
  public function testConcurrentPolicyCommit(): void {
    $this->concurrentWriter(FALSE, TRUE);
  }

  /**
   * Waits for a real writer, with the blocked removal observed in the database.
   */
  private function concurrentWriter(bool $rollback, bool $policy = FALSE): void {
    $record = $this->terminal();
    $store = $this->container->get('agency_staff.store');
    $other = $store->create('agency_discovery', [
      'person_uuid' => '11111111-1111-4111-8111-111111111111',
      'principal_uuid' => '22222222-2222-4222-8222-222222222222',
      'owner_uid' => $this->operator->id(),
      'state' => 'exploring',
      'version' => 1,
    ]);
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    $this->assertSame('prepared', $prepared['outcome']);
    $database = $this->container->get('database');
    $parent = (int) $database->query($database->driver() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchField();
    $marker = sys_get_temp_dir() . '/agency-removal-' . bin2hex(random_bytes(8));
    $vendor = dirname($this->root) . '/vendor';
    if (!is_file($vendor . '/autoload.php')) {
      $vendor = $this->root . '/vendor';
    }
    $worker = new Process([
      PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/discovery-worker.php', $this->root, $vendor,
    ]);
    $worker->setTimeout(25);
    $worker->setInput(json_encode([
      'mode' => $policy ? 'retention_policy' : 'retention_reference',
      'database' => $database->getConnectionOptions(),
      'namespaces' => ['Drupal\\agency_staff\\' => dirname((new \ReflectionClass(DomainStore::class))->getFileName(), 2)],
      'started' => $marker . '-started',
      'ready' => $marker . '-ready',
      'observed' => $marker . '-observed',
      'parent_id' => $parent,
      'other_id' => $other->id(),
      'person' => $record->scalar('person_uuid'),
      'principal' => $record->scalar('principal_uuid'),
      'rollback' => $rollback,
    ], JSON_THROW_ON_ERROR));
    try {
      $worker->start();
      $deadline = microtime(TRUE) + 10;
      while (!is_file($marker . '-ready')) {
        if (!$worker->isRunning() || microtime(TRUE) >= $deadline) {
          $this->fail('Reference writer did not acquire the mutex: ' . $worker->getErrorOutput() . $worker->getOutput());
        }
        usleep(20000);
      }
      $result = $service->remove($record->uuid(), $prepared['plan_sha256'], 'concurrent-removal');
      $worker->wait();
      $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
      $this->assertFileExists($marker . '-observed', 'The writer must observe removal waiting on its database fence.');
      $output = json_decode($worker->getOutput(), TRUE, 16, JSON_THROW_ON_ERROR);
      $this->assertSame($rollback ? 'refused' : 'committed', $output['outcome']);
      if ($rollback) {
        $this->assertSame('committed', $result['outcome']);
        $this->assertSame(0, $this->countRows('agency_person'));
        $this->assertSame(0, $this->countRows('agency_principal'));
      }
      elseif ($policy) {
        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(2, $this->countRows('agency_discovery'));
        $this->container->get('cache.config')->deleteAll();
        $this->assertSame('not_due', $service->prepare($record->uuid())['reason']);
      }
      else {
        $this->assertSame('plan_changed', $result['reason']);
        $prepared = $service->prepare($record->uuid());
        $this->assertSame('preserve', $prepared['principal_action']);
        $this->assertSame('preserve', $prepared['person_action']);
        $this->assertSame('committed', $service->remove($record->uuid(), $prepared['plan_sha256'], 'fresh-shared-plan')['outcome']);
        $this->assertSame(1, $this->countRows('agency_person'));
        $this->assertSame(1, $this->countRows('agency_principal'));
      }
    }
    finally {
      $worker->stop();
      foreach (['started', 'ready', 'observed'] as $suffix) {
        if (is_file($marker . '-' . $suffix)) {
          unlink($marker . '-' . $suffix);
        }
      }
    }
  }

  /**
   * Injects a real database refusal after the entity deletion statements.
   */
  private function failedPersistence(string $table, string $check): void {
    $record = $this->terminal();
    $service = $this->container->get('agency_staff.retention_removal');
    $prepared = $service->prepare($record->uuid());
    $this->container->get('database')->query('ALTER TABLE {' . $table . '} ADD CONSTRAINT retention_test_refusal CHECK (' . $check . ')');
    $before = $this->businessHash();
    $this->assertSame('refused', $service->remove($record->uuid(), $prepared['plan_sha256'], 'failed-persistence')['outcome']);
    $this->assertSame($before, $this->businessHash());
    $this->assertSame(0, $this->countRows('agency_staff_outbox', ['operation' => 'removeExpiredDiscovery']));
    $this->assertSame(0, $this->countRows('agency_staff_intent'));
  }

  /**
   * Seeds synthetic records and two complete relationship revisions.
   */
  protected function terminal(): InternalRecord {
    $store = $this->container->get('agency_staff.store');
    $person = $store->create('agency_person', [
      'display_name' => 'Synthetic personal name',
      'contact_kind' => 'email',
      'contact_value' => 'synthetic@example.test',
    ]);
    $identity = new Principal('https://identity.example.test', 'person-one');
    $binding = $store->create('agency_principal', [
      'principal_key' => $identity->key(),
      'issuer' => $identity->issuer,
      'subject' => $identity->subject,
      'person_uuid' => $person->uuid(),
      'status' => 'active',
    ]);
    $record = $store->create('agency_discovery', [
      'person_uuid' => $person->uuid(),
      'principal_uuid' => $binding->uuid(),
      'owner_uid' => $this->operator->id(),
      'state' => 'exploring',
      'waiting_on' => 'agency',
      'version' => 1,
      'details' => json_encode(['note' => 'Synthetic personal conversation.']),
    ]);
    $record->setNewRevision(TRUE);
    $record->set('state', 'closed')->set('version', 2);
    $record->setDetails(['terminal_at' => $this->now - 86400, 'note' => 'Synthetic personal close note.'])->save();
    $store->create('agency_invitation', [
      'relationship_uuid' => $record->uuid(),
      'principal_uuid' => $binding->uuid(),
      'token_hash' => hash('sha256', 'synthetic-token-value'),
      'status' => 'consumed',
      'expires' => $this->now - 86400,
      'delivery_state' => 'recorded',
    ]);
    $store->create('agency_entitlement', [
      'relationship_uuid' => $record->uuid(),
      'principal_uuid' => $binding->uuid(),
      'status' => 'summary_only',
      'version' => 2,
      'readable_until' => $this->now - 1,
      'details' => '{}',
    ]);
    return $record;
  }

  /**
   * Reads committed results from an independent database connection.
   */
  protected function countRows(string $table, array $conditions = []): int {
    $query = Database::getConnection('default', 'retention_verifier')->select($table, 't');
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Compares personal/history rows without logging their data.
   */
  protected function businessHash(): string {
    $rows = [];
    foreach ([...DomainStore::TYPES, 'agency_discovery_revision'] as $table) {
      $query = Database::getConnection('default', 'retention_verifier')->select($table, 't')->fields('t');
      $query->orderBy($table === 'agency_discovery_revision' ? 'revision_id' : 'id');
      $result = $query->execute();
      while ($row = $result->fetchAssoc()) {
        $rows[$table][] = $row;
      }
    }
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
  }

}
