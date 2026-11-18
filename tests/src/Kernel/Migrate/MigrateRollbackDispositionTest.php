<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Drupal\agency_migrate\AgencyMigrateServiceProvider;
use Drupal\agency_migrate\AuthorityRegister;
use Drupal\agency_migrate\RollbackDispositionRegistry;
use Drupal\agency_migrate\RollbackService;
use Drupal\agency_migrate_disposition_test\LocalDisposition;
use Drupal\agency_migrate_rollback_test\RollbackTestStorage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Proves optional resource reversal with actual transactional storage.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateRollbackDispositionTest extends MigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_migrate_operation_test',
    'agency_migrate_rollback_test',
    'agency_migrate_disposition_test',
  ];

  private const DOMAIN = 'synthetic';
  private const PROVIDER = 'synthetic.disposition';
  private const REASON = 'Return sample';
  private const BINDING = 'agency_migrate_disposition_binding';
  private const RECEIPT = 'agency_migrate_rollback_receipt';
  private const HOST_RECEIPT = 'agency_migrate_test_disposition';
  private const RESOURCE = 'agency_migrate_test_resource';
  private const AUTHORITY = 'agency_migrate_authority_state';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    LocalDisposition::$instances = 0;
    $this->installSchema('agency_migrate_disposition_test', [self::RESOURCE, self::HOST_RECEIPT]);
  }

  /**
   * Declared resources require explicit enrollment and fenced qualification.
   */
  public function testDeclaredResourcesBlockUnenrolledCutover(): void {
    $authority = $this->authority();
    $authority->register(self::DOMAIN, 'Register sample');
    $authority->transition(self::DOMAIN, 'shadow', 'Prepare sample');
    $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'agency', 'Promote sample'), 'rollback_unqualified');
    $this->assertSame('shadow', $authority->get(self::DOMAIN));
    $this->assertSame(0, LocalDisposition::$instances);
    $this->assertSame([], $this->provider()->calls);

    $account = $this->container->get('current_user');
    $account->setAccount($this->author);
    $this->assertRefused(fn () => $authority->requireDisposition(self::DOMAIN, self::PROVIDER), 'forbidden');
    $account->setAccount($this->operator);
    $this->assertRefused(fn () => $authority->requireDisposition(self::DOMAIN, 'missing.provider'), 'rollback_unqualified');
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->assertRefused(fn () => $authority->requireDisposition(self::DOMAIN, self::PROVIDER), 'writer_transaction_owned');
    }
    finally {
      $transaction->rollBack();
    }

    $authority->requireDisposition(self::DOMAIN, self::PROVIDER);
    $before = $this->rows(self::AUTHORITY);
    $authority->requireDisposition(self::DOMAIN, self::PROVIDER);
    $this->assertSame($before, $this->rows(self::AUTHORITY));
    $this->assertSame(2, (int) $before[0]['disposition_required_after']);
    foreach (['', 'other.provider'] as $invalid) {
      $this->assertRefused(fn () => $authority->requireDisposition(self::DOMAIN, $invalid), 'rollback_unqualified');
    }
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'agency', 'Ambient cutover'), 'writer_transaction_owned');
      $this->assertSame([], $this->provider()->calls);
      $this->assertSame([], $this->rows(self::BINDING));
    }
    finally {
      $transaction->rollBack();
    }

    $this->provider()->fault = 'cutover';
    $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'agency', 'Promote sample'), 'rollback_unqualified');
    $this->assertSame('shadow', $authority->get(self::DOMAIN));
    $this->assertSame([], $this->rows(self::BINDING));
    $this->provider()->fault = '';
    $authority->transition(self::DOMAIN, 'agency', 'Promote sample');
    $this->assertSame([['cutover', TRUE], ['cutover', TRUE]], $this->provider()->calls);
    $this->assertSame(self::PROVIDER, $this->rows(self::BINDING)[0]['provider']);
    $this->assertRefused(fn () => $authority->requireDisposition(self::DOMAIN, self::PROVIDER), 'rollback_unqualified');
  }

  /**
   * Zero entity replay still processes a fresh, fully fenced resource plan.
   */
  public function testGrantOnlyRollbackRequiresExplicitGeneration(): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $rollback = $this->rollback();
    $before = $this->rows(self::RESOURCE);
    $preview = $rollback->rollback(self::DOMAIN, self::REASON);
    $this->assertSame(0, $preview['pending']);
    $this->assertSame(1, $preview['disposition_pending']);
    $this->assertSame($generation, $preview['generation']);
    $this->assertSame($before, $this->rows(self::RESOURCE));
    $this->assertSame([], $this->rows(self::HOST_RECEIPT));
    $this->assertRefused(fn () => $rollback->rollback(self::DOMAIN, self::REASON, TRUE), 'rollback_generation_required');

    $this->resource($generation, 2);
    $result = $rollback->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertSame([
      'domain' => self::DOMAIN,
      'replayed' => 0,
      'authority' => 'legacy',
      'status' => 'completed',
      'generation' => $generation + 1,
    ], $result);
    $this->assertSame(['legacy', 'legacy'], array_column($this->rows(self::RESOURCE), 'authority'));
    $stored = $this->rows(self::RECEIPT)[0];
    $host = $this->rows(self::HOST_RECEIPT)[0];
    $this->assertSame(2, (int) $host['changed']);
    $this->assertSame(self::PROVIDER, $stored['disposition_provider']);
    $this->assertSame($host['digest'], $stored['disposition_digest']);

    // A live counter can change without modifying the immutable host proof.
    $this->resource($generation, 3);
    $this->assertSame($result, $rollback->rollback(self::DOMAIN, self::REASON, TRUE, $generation));
    $this->assertSame([
      ['cutover', TRUE], ['plan', FALSE], ['plan', TRUE], ['plan', TRUE], ['apply', TRUE], ['verify', FALSE],
    ], $this->provider()->calls);
    $this->assertCount(1, $this->rows(self::HOST_RECEIPT));
  }

  /**
   * Faults roll back any attempted entity and resource writes together.
   *
   * @dataProvider dispositionFaults
   */
  #[DataProvider('dispositionFaults')]
  public function testDispositionFailurePreservesBothStores(string $fault, string $reason): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $writer = $this->container->get('agency_migrate.post_cutover_writer');
    $record = $writer->create(self::DOMAIN, $generation, $this->container->get('uuid')->generate(), 'agency_migrate_agency_item', [
      'title' => 'Agency journal record',
    ]);
    $id = (int) $record['entity_id'];
    $this->provider()->expectedLegacyId = $id;
    $this->provider()->fault = $fault;
    $before = $this->rows(self::RESOURCE);
    $outbox = $this->rows('agency_migrate_outbox');
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), $reason);
    $this->assertSame($before, $this->rows(self::RESOURCE));
    $this->assertSame($outbox, $this->rows('agency_migrate_outbox'));
    $this->assertSame('agency', $this->authority()->get(self::DOMAIN));
    $this->assertSame([], $this->rows(self::RECEIPT));
    $this->assertSame([], $this->rows(self::HOST_RECEIPT));
    $this->assertCount(1, $this->container->get('agency_migrate.writes')->pending(self::DOMAIN));
    $legacy = $this->container->get('entity_type.manager')->getStorage('agency_migrate_legacy_item');
    $this->assertNull($legacy->loadUnchanged($id));
    if ($fault === 'plan') {
      $this->assertSame([], RollbackTestStorage::$effects);
    }

    $this->provider()->fault = '';
    $result = $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertSame(1, $result['replayed']);
    $this->assertSame('Agency journal record', $this->source($id)->label());
    $this->assertSame('legacy', $this->rows(self::RESOURCE)[0]['authority']);
  }

  /**
   * Covers preflight, partial effect, persistence readback and malformed proof.
   */
  public static function dispositionFaults(): array {
    return [
      'observational preflight' => ['plan', 'rollback_unqualified'],
      'after resource update' => ['partial', 'rollback_failed'],
      'failed host readback' => ['readback', 'rollback_failed'],
      'malformed receipt digest' => ['digest', 'rollback_unqualified'],
    ];
  }

  /**
   * A committed but unacknowledged reversal cannot run resource effects twice.
   */
  public function testUncertainCommitRecoversFromImmutableProof(): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $this->provider()->fault = 'commit';
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_commit_uncertain');
    $this->provider()->fault = '';
    $this->assertFalse($this->container->get('database')->inTransaction());
    $this->assertSame('legacy', $this->authority()->get(self::DOMAIN));
    $this->assertSame('legacy', $this->rows(self::RESOURCE)[0]['authority']);
    $this->assertSame('completed', $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation)['status']);
    $this->assertSame([['cutover', TRUE], ['plan', TRUE], ['apply', TRUE], ['verify', FALSE]], $this->provider()->calls);
    $this->assertCount(1, $this->rows(self::HOST_RECEIPT));
    $this->assertCount(1, $this->rows(self::RECEIPT));
  }

  /**
   * Cutover does not stamp a binding in a replacement transaction.
   */
  public function testProviderCannotReplaceCutoverTransaction(): void {
    $authority = $this->authority();
    $authority->register(self::DOMAIN, 'Register sample');
    $authority->requireDisposition(self::DOMAIN, self::PROVIDER);
    $authority->transition(self::DOMAIN, 'shadow', 'Prepare sample');
    $this->provider()->fault = 'replace_cutover';
    try {
      $this->assertRefused(fn () => $authority->transition(self::DOMAIN, 'agency', 'Promote sample'), 'rollback_unqualified');
      $this->assertTrue($this->container->get('database')->inTransaction());
      $this->assertSame('shadow', $authority->get(self::DOMAIN));
      $this->assertSame([], $this->rows(self::BINDING));
    }
    finally {
      $this->provider()->discardReplacement();
    }
  }

  /**
   * An early host commit cannot lead to a later seal or authority advance.
   */
  public function testProviderCannotReplaceRollbackTransaction(): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $this->provider()->fault = 'replace_apply';
    try {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_commit_uncertain');
      $this->assertTrue($this->container->get('database')->inTransaction());
      $this->assertSame('agency', $this->authority()->get(self::DOMAIN));
      $this->assertSame([], $this->rows(self::RECEIPT));
    }
    finally {
      $this->provider()->discardReplacement();
    }
    // The coerced early SQL commit cannot be undone by the coordinator.
    $this->assertCount(1, $this->rows(self::HOST_RECEIPT));
    $this->assertSame('agency', $this->authority()->get(self::DOMAIN));
  }

  /**
   * Runtime service removal cannot erase the persisted need for the provider.
   */
  public function testRemovedProviderCannotBypassRollbackOrRecovery(): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $unconfigured = $this->unconfiguredRollback();
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $unconfigured->rollback(self::DOMAIN, self::REASON, $execute, $generation), 'rollback_unqualified');
    }
    $this->assertSame([['cutover', TRUE]], $this->provider()->calls);
    $this->assertSame('agency', $this->rows(self::RESOURCE)[0]['authority']);
    $this->assertSame('agency', $this->authority()->get(self::DOMAIN));
    $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->assertRefused(fn () => $unconfigured->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertCount(1, $this->rows(self::HOST_RECEIPT));
  }

  /**
   * Missing active binding or partial enrollment blocks all effects.
   *
   * @dataProvider controlFaults
   */
  #[DataProvider('controlFaults')]
  public function testCorruptActiveControlStateRefuses(string $fault): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $database = $this->container->get('database');
    if ($fault === 'binding') {
      $database->delete(self::BINDING)->execute();
    }
    else {
      $database->update(self::AUTHORITY)->fields(['disposition_required_after' => NULL])->execute();
    }
    foreach ([FALSE, TRUE] as $execute) {
      $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, $execute, $generation), 'rollback_unqualified');
    }
    $this->assertSame([['cutover', TRUE]], $this->provider()->calls);
    $this->assertSame('agency', $this->rows(self::AUTHORITY)[0]['state']);
    $this->assertSame('agency', $this->rows(self::RESOURCE)[0]['authority']);
    $this->assertSame([], $this->rows(self::RECEIPT));
  }

  /**
   * Lists active-generation control record faults.
   */
  public static function controlFaults(): array {
    return ['missing binding' => ['binding'], 'half enrollment' => ['enrollment']];
  }

  /**
   * Recovery requires an exact complete receipt and current generation pair.
   *
   * @dataProvider retainedProofFaults
   */
  #[DataProvider('retainedProofFaults')]
  public function testCorruptRetainedProofCannotBeConfirmed(string $table, string $field, mixed $value): void {
    $generation = $this->prepare();
    $this->resource($generation, 1);
    $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation);
    $this->container->get('database')->update($table)->fields([$field => $value])->execute();
    $this->assertRefused(fn () => $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, $generation), 'rollback_unqualified');
    $this->assertSame('legacy', $this->rows(self::RESOURCE)[0]['authority']);
    $this->assertCount(1, $this->rows(self::HOST_RECEIPT));
  }

  /**
   * Lists missing or mismatched independent receipt evidence.
   */
  public static function retainedProofFaults(): array {
    return [
      'missing provider' => [self::RECEIPT, 'disposition_provider', NULL],
      'missing digest' => [self::RECEIPT, 'disposition_digest', NULL],
      'wrong retained binding' => [self::BINDING, 'provider', 'other.provider'],
      'wrong host proof' => [self::HOST_RECEIPT, 'digest', str_repeat('0', 64)],
    ];
  }

  /**
   * An upgrade and later enrollment preserve the historical receipt.
   */
  public function testOldReceiptRemainsRecoverableAfterUpdateAndEnrollment(): void {
    $old_authority = $this->unconfiguredAuthority();
    $old_authority->register(self::DOMAIN, 'Register sample');
    $old_authority->transition(self::DOMAIN, 'shadow', 'Prepare sample');
    $old_authority->transition(self::DOMAIN, 'agency', 'Promote sample');
    $this->assertSame([], $this->rows(self::BINDING));
    $old_result = $this->unconfiguredRollback()->rollback(self::DOMAIN, self::REASON, TRUE, 3);
    $receipt = $this->rows(self::RECEIPT);
    $authority = $this->rows(self::AUTHORITY);
    $schema = $this->container->get('database')->schema();
    $schema->dropTable(self::BINDING);
    $schema->dropField(self::RECEIPT, 'disposition_provider');
    $schema->dropField(self::RECEIPT, 'disposition_digest');
    $schema->dropField(self::AUTHORITY, 'disposition_provider');
    $schema->dropField(self::AUTHORITY, 'disposition_required_after');
    $this->container->get('module_handler')->loadInclude('agency_migrate', 'install');
    \agency_migrate_update_10006();
    \agency_migrate_update_10006();
    $this->assertEquals($receipt, $this->rows(self::RECEIPT));
    $this->assertEquals($authority, $this->rows(self::AUTHORITY));
    $this->assertSame([], $this->rows(self::BINDING));
    $this->authority()->requireDisposition(self::DOMAIN, self::PROVIDER);
    $this->assertSame($old_result, $this->rollback()->rollback(self::DOMAIN, self::REASON, TRUE, 3));
    $this->assertSame([], $this->provider()->calls);
  }

  /**
   * Provider IDs and domain declarations must be unambiguous.
   */
  public function testRegistryRejectsDuplicateAndIncompleteDeclarations(): void {
    $registry = new RollbackDispositionRegistry();
    $provider = fn (): LocalDisposition => $this->provider();
    $registry->addProvider($provider, self::PROVIDER, self::DOMAIN);
    $this->assertRefused(fn () => $registry->addProvider($provider, self::PROVIDER, 'other'), 'rollback_unqualified');
    $this->assertRefused(fn () => $registry->addProvider($provider, 'other.provider', self::DOMAIN), 'rollback_unqualified');
    $this->assertRefused(fn () => $registry->addProvider($provider), 'rollback_unqualified');
    $this->assertRefused(fn () => $registry->resolve('unknown', self::PROVIDER), 'rollback_unqualified');
  }

  /**
   * A tagged service of the wrong type cannot enter the serving registry.
   */
  public function testCollectorRefusesIncorrectProviderType(): void {
    $container = new ContainerBuilder();
    $container->register('agency_migrate.rollback_dispositions', RollbackDispositionRegistry::class);
    $container->register('invalid_provider', \stdClass::class)->addTag('agency_migrate.rollback_disposition');
    $this->expectException(LogicException::class);
    (new AgencyMigrateServiceProvider())->process($container);
  }

  /**
   * Enrolls and cuts over an empty entity domain on the real authority path.
   */
  private function prepare(): int {
    $authority = $this->authority();
    $authority->register(self::DOMAIN, 'Register sample');
    $authority->requireDisposition(self::DOMAIN, self::PROVIDER);
    $authority->transition(self::DOMAIN, 'shadow', 'Prepare sample');
    $authority->transition(self::DOMAIN, 'agency', 'Promote sample');
    return $authority->status()[self::DOMAIN]['version'];
  }

  /**
   * Records one post-cutover resource on the persisted fixture relation.
   */
  private function resource(int $generation, int $id): void {
    $this->container->get('database')->insert(self::RESOURCE)->fields([
      'domain' => self::DOMAIN,
      'generation' => $generation,
      'id' => $id,
      'authority' => 'agency',
    ])->execute();
  }

  /**
   * Reads persisted fixture state.
   */
  private function rows(string $table): array {
    $rows = [];
    $query = $this->container->get('database')->select($table, 'r')->fields('r');
    if ($table === self::RESOURCE) {
      $query->orderBy('id');
    }
    $result = $query->execute();
    while ($row = $result->fetchAssoc()) {
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Returns the serving participant.
   */
  private function provider(): LocalDisposition {
    return $this->container->get('agency_migrate_disposition_test.provider');
  }

  /**
   * Returns the serving authority service.
   */
  private function authority(): AuthorityRegister {
    return $this->container->get('agency_migrate.authority');
  }

  /**
   * Returns the serving rollback coordinator.
   */
  private function rollback(): RollbackService {
    return $this->container->get('agency_migrate.rollback');
  }

  /**
   * Mimics an older or desynchronized container with no declared participant.
   */
  private function unconfiguredAuthority(): AuthorityRegister {
    return new AuthorityRegister(
      $this->container->get('current_user'),
      $this->container->get('agency_migrate.outbox'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
      $this->container->get('agency_migrate.host_writer_fence'),
      new RollbackDispositionRegistry(),
    );
  }

  /**
   * Runs the real coordinator with an authority service lacking registration.
   */
  private function unconfiguredRollback(): RollbackService {
    return new RollbackService(
      $this->container->get('plugin.manager.agency_migrate.copier'),
      $this->container->get('agency_migrate.rollback_replay_store'),
      $this->unconfiguredAuthority(),
      $this->container->get('current_user'),
      $this->container->get('agency_migrate.reconciler'),
      $this->container->get('agency_migrate.rollback_planner'),
      $this->container->get('database'),
      $this->container->get('agency_migrate.outbox'),
    );
  }

}
