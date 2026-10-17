<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Kernel;

use Drupal\agency_host\Identity\CleanOriginPlan;
use Drupal\agency_host\Identity\CleanOriginQualifier;
use Drupal\agency_host\Identity\OriginLedgerInterface;
use Drupal\agency_host\Identity\CleanOriginReceipt;
use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\agency_host\Drush\Commands\AgencyOriginCommands;
use Drupal\user\UserInterface;
use Firebase\JWT\JWT;
use Drupal\agency_host\Identity\JwksAccessTokenValidator;
use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Drush\Config\DrushConfig;
use Drush\Log\DrushLoggerManager;
use Drush\Runtime\ServiceManager;
use Drupal\agency_host\Drush\Commands\AgencyReadinessCommands;
use Drupal\agency_host\Drush\Commands\AgencyEvidenceCommands;
use Drupal\agency_host\EvidenceWorker;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\AbstractLogger;
use Drupal\agency_host\Delivery\DeliverySuppression;
use Drupal\agency_host\Delivery\DeliverySuppressionInterface;
use Drupal\agency_host\Delivery\InvitationMailInterface;
use Drupal\agency_host\Form\InvitationDeliveryForm;
use Drupal\agency_host\Delivery\InvitationMail;
use Drupal\agency_host\Delivery\PrivateSmtp;
use Drupal\agency_host\Delivery\SmtpHandoffFactory;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\agency_host\Delivery\DeliveryConfiguration;
use Drupal\agency_host\Delivery\InvitationDelivery;
use Drupal\agency_host\Delivery\DeliveryLedger;
use Drupal\agency_host\Delivery\DatabaseDeliveryAudit;
use Drupal\agency_host\Console\ConsoleReviewStore;
use Drupal\agency_host\Console\ConsoleAccess;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\SessionHandler;
use Drupal\agency_host\Form\DiscoveryHeldForm;
use Drupal\Core\KeyValueStore\DatabaseStorageExpirable;
use Drupal\Core\KeyValueStore\DatabaseStorage as KeyValueDatabaseStorage;
use Drupal\agency_host\Console\ConsoleApplication;
use Drupal\agency_host\Console\ConsoleStateStore;
use Drupal\agency_host\Console\StrictConsoleStorage;
use Drupal\agency_staff\Exception\Refusal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\agency_host\Form\DiscoveryCommandForm;
use Drupal\Core\Form\FormState;
use Drupal\agency_host\Console\ConsoleFieldInventory;
use Drupal\agency_host\Form\DiscoveryPrepareForm;
use Drupal\agency_host\Controller\DiscoveryConsoleController;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\openid_connect\Entity\OpenIDConnectClientEntity;
use Drupal\agency_staff\EvidenceOutbox;
use Drupal\agency_host\EvidenceHealthMonitor;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\agency_host\Identity\PostgresQualificationLatch;
use Drupal\externalauth\Authmap;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\agency_host\Controller\CoordinatorDiscoveryController;
use Drupal\agency_host\Controller\ParticipantDiscoveryController;
use Drupal\agency_host\Identity\AgencyIdentityProvider;
use Drupal\agency_host\Identity\BindingHistory;
use Drupal\agency_host\Identity\QualificationLatchInterface;
use Drupal\agency_host\Identity\TrackedAuthmap;
use Drupal\agency_host\Identity\AccessTokenValidatorInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exercises actual host services and Agency persistence with synthetic IdP I/O.
 */
#[Group('agency_host')]
#[RunTestsInSeparateProcesses]
final class DiscoveryHostTest extends KernelTestBase {

  use SimpleOauthTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'image',
    'path_alias', 'entity', 'field_guard', 'key', 'encrypt', 'audit_chain',
    'externalauth', 'consumers', 'serialization', 'simple_oauth', 'openid_connect',
  ];

  /**
   * Initial qualification preserves all old rows and cannot activate access.
   */
  public function testCleanOriginQualificationAndCompletedRetry(): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $old = $database->select('agency_host_binding_coverage', 'c')->fields('c')->execute()->fetchAssoc();
    $report = $qualifier->qualify($bytes);
    $this->assertSame('completed', $report['outcome']);
    $this->assertTrue($report['current_qualification_ready']);
    $this->assertFalse($report['activation_performed']);
    $this->assertFalse($this->config('agency_host.settings')->get('enabled'));
    $this->assertSame($old, $database->select('agency_host_binding_coverage', 'c')->fields('c')->condition('scope', $old['scope'])->execute()->fetchAssoc());
    $this->assertSame(2, (int) $database->select('agency_host_binding_coverage', 'c')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
    $this->assertSame('already_completed', $qualifier->qualify($bytes)['outcome']);
    $this->container->get('agency_host.qualification_latch')->invalidate($report['epoch_sha256']);
    $retry = $qualifier->qualify($bytes);
    $this->assertSame('already_completed', $retry['outcome']);
    $this->assertFalse($retry['current_qualification_ready']);
    $this->assertSame(1, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * Active old epochs cannot be quietly migrated by an initial-origin command.
   */
  public function testCleanOriginQualificationRefusesActiveOldEpoch(): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification(FALSE);
    $this->assertOriginQualificationRefused($qualifier, $bytes);
    $database = $this->container->get('database');
    $this->assertSame(0, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $database->select('agency_host_binding_invalid', 'i')->countQuery()->execute()->fetchField());
  }

  /**
   * Raw committed changes after review invalidate the approved plan.
   */
  public function testCleanOriginQualificationRefusesStalePlan(): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $database->insert('authmap')->fields([
      'uid' => 99999,
      'provider' => 'unrelated-provider',
      'authname' => 'synthetic-change-after-review',
    ])->execute();
    $this->assertOriginQualificationRefused($qualifier, $bytes);
    $this->assertSame(0, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * A raw writer keeps its transaction; bootstrap refuses before reserving.
   */
  #[DataProvider('originLockTables')]
  public function testCleanOriginQualificationRefusesConcurrentWriter(string $table): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $key = 'origin_qualification_other_writer';
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    try {
      $other = Database::getConnection('default', $key);
      $transaction = $other->startTransaction();
      $other->query('LOCK TABLE {' . $table . '} IN ROW EXCLUSIVE MODE');
      $this->assertOriginQualificationRefused($qualifier, $bytes);
      $this->assertTrue($other->inTransaction());
      $transaction->commitOrRelease();
      $this->assertSame(0, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
      $this->assertSame(0, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
    }
    finally {
      Database::removeConnection($key);
    }
  }

  /**
   * Covers the known authmap-before-mutex inversion and independent config saves.
   */
  public static function originLockTables(): array {
    return [['authmap'], ['config'], ['agency_host_binding_mutex']];
  }

  /**
   * A primary insertion failure retains independently consumed origin and guard.
   */
  #[DataProvider('originPrimaryFaults')]
  public function testCleanOriginQualificationPrimaryRollback(bool $deferred): void {
    $database = $this->container->get('database');
    $real = $this->container->get('agency_host.origin_ledger');
    $seam = $this->createMock(OriginLedgerInterface::class);
    $seam->method('find')->willReturnCallback($real->find(...));
    $callbacks = [];
    $seam->method('reserve')->willReturnCallback(static function (array $record) use ($database, $real, &$callbacks): void {
      $database->transactionManager()->addPostTransactionCallback(static function (bool $success) use (&$callbacks): void {
        $callbacks[] = $success;
      });
      $real->reserve($record);
    });
    $this->container->set('agency_host.origin_ledger', $seam);
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $function = 'origin_coverage_failure_' . bin2hex(random_bytes(8));
    $database->query("CREATE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic coverage failure''; END'", [], ['allow_delimiter_in_query' => TRUE]);
    $database->query($deferred
      ? "CREATE CONSTRAINT TRIGGER origin_coverage_failure AFTER INSERT ON {agency_host_binding_coverage} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION $function()"
      : "CREATE TRIGGER origin_coverage_failure BEFORE INSERT ON {agency_host_binding_coverage} FOR EACH ROW EXECUTE FUNCTION $function()");
    try {
      $this->assertOriginQualificationRefused($qualifier, $bytes);
      $this->assertSame([FALSE], $callbacks);
      $this->assertSame(1, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
      $this->assertSame(1, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
      $this->assertSame(1, (int) $database->select('agency_host_binding_coverage', 'c')->countQuery()->execute()->fetchField());
      $database->query('DROP TRIGGER origin_coverage_failure ON {agency_host_binding_coverage}');
      $this->assertOriginQualificationRefused($qualifier, $bytes);
      $this->assertFalse($this->container->get('agency_host.binding_history')->qualificationReady());
    }
    finally {
      $database->query('DROP TRIGGER IF EXISTS origin_coverage_failure ON {agency_host_binding_coverage}');
      $database->query("DROP FUNCTION $function()");
    }
  }

  /**
   * An insert failure and a commit-time constraint failure have the same hold.
   */
  public static function originPrimaryFaults(): array {
    return [[FALSE], [TRUE]];
  }

  /**
   * Lost final acknowledgement is reconciled, not a reason to requalify.
   */
  public function testCleanOriginQualificationLostFinalAcknowledgement(): void {
    $real = $this->container->get('agency_host.origin_ledger');
    $seam = $this->createMock(OriginLedgerInterface::class);
    $seam->method('find')->willReturnCallback($real->find(...));
    $seam->method('readCommitted')->willReturnCallback($real->readCommitted(...));
    $seam->method('reserve')->willReturnCallback($real->reserve(...));
    $seam->method('complete')->willReturnCallback(static function (array $record, int $completed) use ($real): void {
      $real->complete($record, $completed);
      throw new \RuntimeException('Synthetic lost final acknowledgement.');
    });
    $this->container->set('agency_host.origin_ledger', $seam);
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $report = $qualifier->qualify($bytes);
    $this->assertSame('completed', $report['outcome']);
    $this->assertTrue($report['current_qualification_ready']);
    $this->assertSame('already_completed', $qualifier->qualify($bytes)['outcome']);
  }

  /**
   * A fresh read cannot see a completion still uncommitted on the ledger session.
   */
  public function testCleanOriginQualificationUncertainTransactionReadback(): void {
    $real = $this->container->get('agency_host.origin_ledger');
    $seam = $this->createMock(OriginLedgerInterface::class);
    $seam->method('find')->willReturnCallback($real->find(...));
    $seam->method('reserve')->willReturnCallback($real->reserve(...));
    $seam->method('readCommitted')->willReturnCallback($real->readCommitted(...));
    $uncertain = NULL;
    $seam->method('complete')->willReturnCallback(function (array $record, int $completed) use ($real, &$uncertain): void {
      $connection = (new \ReflectionProperty($real, 'connection'))->getValue($real);
      $uncertain = $connection->startTransaction();
      $connection->update('agency_host_binding_origin')->fields(['state' => 'completed', 'completed' => $completed])
        ->condition('issuer_key', $record['issuer_key'])->execute();
      $connection->delete('agency_host_binding_guard')->condition('id', $record['guard'])->execute();
      $this->assertSame('completed', $real->find($record['issuer_key'])['state']);
      throw new \RuntimeException('Synthetic unresolved transaction.');
    });
    $this->container->set('agency_host.origin_ledger', $seam);
    try {
      [$qualifier, $bytes] = $this->cleanOriginQualification();
      $report = $qualifier->qualify($bytes);
      $this->assertSame('held', $report['outcome']);
      $this->assertFalse($report['current_qualification_ready']);
      $this->assertFalse($report['activation_performed']);
    }
    finally {
      $uncertain?->rollBack();
    }
  }

  /**
   * Unavailable fresh readback reports uncertainty, never a manufactured denial.
   */
  public function testCleanOriginQualificationUnknownOutcome(): void {
    $real = $this->container->get('agency_host.origin_ledger');
    $seam = $this->createMock(OriginLedgerInterface::class);
    $seam->method('find')->willReturnCallback($real->find(...));
    $seam->method('reserve')->willReturnCallback($real->reserve(...));
    $seam->method('complete')->willThrowException(new \RuntimeException('Synthetic completion outage.'));
    $seam->method('readCommitted')->willThrowException(new \RuntimeException('Synthetic readback outage.'));
    $this->container->set('agency_host.origin_ledger', $seam);
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $this->assertSame(['outcome' => 'outcome_unknown', 'activation_performed' => FALSE], $qualifier->qualify($bytes));
    $this->assertFalse($this->container->get('agency_host.binding_history')->qualificationReady());
  }

  /**
   * Independent reservation and completion fail atomically under storage faults.
   */
  #[DataProvider('originLedgerFaults')]
  public function testCleanOriginQualificationLedgerFault(string $table, string $operation, bool $reserved, bool $deferred): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $function = 'origin_fault_' . bin2hex(random_bytes(8));
    $database->query("CREATE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic origin fault''; END'", [], ['allow_delimiter_in_query' => TRUE]);
    $database->query($deferred
      ? "CREATE CONSTRAINT TRIGGER origin_fault AFTER $operation ON {{$table}} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION $function()"
      : "CREATE TRIGGER origin_fault BEFORE $operation ON {{$table}} FOR EACH ROW EXECUTE FUNCTION $function()");
    try {
      if ($reserved) {
        $report = $qualifier->qualify($bytes);
        $this->assertSame('held', $report['outcome']);
        $this->assertFalse($report['current_qualification_ready']);
      }
      else {
        $this->assertOriginQualificationRefused($qualifier, $bytes);
      }
      $this->assertSame((int) $reserved, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
      $this->assertSame((int) $reserved, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
      $this->assertSame(1 + (int) $reserved, (int) $database->select('agency_host_binding_coverage', 'c')->countQuery()->execute()->fetchField());
    }
    finally {
      $database->query("DROP TRIGGER origin_fault ON {{$table}}");
      $database->query("DROP FUNCTION $function()");
    }
  }

  /**
   * Both halves of each independently committed operation must be all-or-none.
   */
  public static function originLedgerFaults(): array {
    return [
      ['agency_host_binding_origin', 'INSERT', FALSE, FALSE],
      ['agency_host_binding_guard', 'INSERT', FALSE, FALSE],
      ['agency_host_binding_origin', 'UPDATE', TRUE, FALSE],
      ['agency_host_binding_guard', 'DELETE', TRUE, FALSE],
      ['agency_host_binding_origin', 'INSERT', FALSE, TRUE],
      ['agency_host_binding_guard', 'INSERT', FALSE, TRUE],
      ['agency_host_binding_origin', 'UPDATE', TRUE, TRUE],
      ['agency_host_binding_guard', 'DELETE', TRUE, TRUE],
    ];
  }

  /**
   * A permanently consumed issuer cannot be recycled by changing version or salt.
   */
  public function testCleanOriginQualificationRefusesReusedIssuer(): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $report = $qualifier->qualify($bytes);
    $this->container->get('agency_host.qualification_latch')->invalidate($report['epoch_sha256']);
    $identity = Settings::get('agency_host_identity');
    $identity['binding_version'] = 'synthetic-second-version';
    new Settings(array_replace(Settings::getAll(), [
      'agency_host_identity' => $identity,
      'hash_salt' => 'synthetic-changed-salt',
    ]));
    [$planner, $revised] = $this->cleanOriginPlanner();
    $approval = Settings::get('agency_host_origin_approval');
    $approval['plan_sha256'] = $planner->report($revised)['plan_sha256'];
    new Settings(array_replace(Settings::getAll(), ['agency_host_origin_approval' => $approval]));
    $this->assertOriginQualificationRefused($qualifier, $revised);
    $this->assertSame(1, (int) $this->container->get('database')->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
  }

  /**
   * The same incarnation under a different issuer is still a consumed origin.
   */
  public function testCleanOriginQualificationRefusesReusedIncarnation(): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $report = $qualifier->qualify($bytes);
    $this->container->get('agency_host.qualification_latch')->invalidate($report['epoch_sha256']);
    [$planner, $revised] = $this->cleanOriginPlanner('https://identity.example.test/realms/renamed-origin');
    $approval = Settings::get('agency_host_origin_approval');
    $approval['plan_sha256'] = $planner->report($revised)['plan_sha256'];
    new Settings(array_replace(Settings::getAll(), ['agency_host_origin_approval' => $approval]));
    $this->assertOriginQualificationRefused($qualifier, $revised);
    $this->assertSame(1, (int) $this->container->get('database')->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
    $this->assertFalse($this->container->get('agency_host.binding_history')->qualificationReady());
  }

  /**
   * The first fenced snapshot includes a commit between mutex and authmap locks.
   */
  #[DataProvider('originForeignWriteWindows')]
  public function testCleanOriginQualificationForeignCommit(string $window): void {
    [, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $key = 'origin_fence_foreign_commit';
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    try {
      $other = Database::getConnection('default', $key);
      $changed = FALSE;
      $change = static function () use ($other, &$changed): void {
        $other->insert('authmap')->fields([
          'uid' => 99999,
          'provider' => 'unrelated-provider',
          'authname' => 'synthetic-fence-race',
        ])->execute();
        $changed = TRUE;
      };
      $intercept = $this->createMock(Connection::class);
      foreach (['inTransaction', 'prepareStatement', 'select', 'insert'] as $method) {
        $intercept->method($method)->willReturnCallback($database->$method(...));
      }
      $starts = 0;
      $intercept->method('startTransaction')->willReturnCallback(static function () use ($database, $window, $change, &$starts) {
        $starts++;
        if ($window === 'after-publication' && $starts === 2) {
          $change();
        }
        return $database->startTransaction();
      });
      $intercept->method('query')->willReturnCallback(function ($sql, $args = [], $options = []) use ($database, $window, $change, &$changed) {
        $result = $database->query($sql, $args, $options);
        if (str_starts_with($sql, 'LOCK TABLE {agency_host_binding_mutex}')) {
          if ($window === 'between-locks' && !$changed) {
            $change();
          }
          $this->assertSame('5s', $database->query('SHOW statement_timeout')->fetchField());
        }
        return $result;
      });
      $qualifier = new CleanOriginQualifier(
        $intercept, new Settings(Settings::getAll()), $this->container->get('agency_host.clean_origin_plan'),
        $this->container->get('agency_host.origin_ledger'), $this->container->get('agency_host.binding_history'),
        $this->container->get('update.update_hook_registry'), $this->container->get('datetime.time'),
      );
      if ($window === 'between-locks') {
        $this->assertOriginQualificationRefused($qualifier, $bytes);
      }
      else {
        $report = $qualifier->qualify($bytes);
        $this->assertSame('held', $report['outcome']);
        $this->assertFalse($report['current_qualification_ready']);
      }
      $this->assertTrue($changed);
      $this->assertSame((int) ($window === 'after-publication'), (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
      $this->assertFalse($this->config('agency_host.settings')->get('enabled'));
    }
    finally {
      Database::removeConnection($key);
    }
  }

  /**
   * Both pre-publication freshness and post-commit drift must fail closed.
   */
  public static function originForeignWriteWindows(): array {
    return [['between-locks'], ['after-publication']];
  }

  /**
   * Qualification is discovered through Drush but cannot bypass protected input.
   */
  public function testCleanOriginQualificationCommandBoundary(): void {
    [, $bytes] = $this->cleanOriginQualification();
    $handler = AgencyOriginCommands::create($this->container);
    $handler->setLogger(new DrushLoggerManager());
    $output = new BufferedOutput();
    $handler->setOutput($output);
    $input = new ArrayInput([]);
    $handler->setInput($input);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handler);
    $commands = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'agency:origin-qualify'));
    $this->assertCount(1, $commands);
    $this->assertSame(1, $commands[0]->run($input, $output));
    $file = tempnam(sys_get_temp_dir(), 'agency-origin-synthetic-');
    try {
      file_put_contents($file, $bytes);
      chmod($file, 0644);
      new Settings(array_replace(Settings::getAll(), ['agency_host_origin_receipt_file' => realpath($file)]));
      $this->assertSame(1, $commands[0]->run($input, $output));
      chmod($file, 0600);
      clearstatcache(TRUE, $file);
      if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->assertSame(0, $commands[0]->run($input, $output));
        $report = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $report['outcome']);
        $this->assertFalse($report['activation_performed']);
        $this->container->get('agency_host.qualification_latch')->invalidate($report['epoch_sha256']);
        $this->assertSame(1, $commands[0]->run($input, $output));
        $report = json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('already_completed', $report['outcome']);
        $this->assertFalse($report['current_qualification_ready']);
      }
      else {
        $this->assertSame(1, $commands[0]->run($input, $output));
        $this->assertSame('', $output->fetch());
      }
      $this->assertFalse($this->config('agency_host.settings')->get('enabled'));
    }
    finally {
      unlink($file);
    }
  }

  /**
   * Missing approval, active entry and unreviewed schemas cannot consume origins.
   */
  #[DataProvider('originExecutionRefusals')]
  public function testCleanOriginQualificationExecutionRefusal(string $fault): void {
    [$qualifier, $bytes] = $this->cleanOriginQualification();
    $database = $this->container->get('database');
    $transaction = NULL;
    if ($fault === 'plan') {
      $approval = Settings::get('agency_host_origin_approval');
      $approval['plan_sha256'] = str_repeat('0', 64);
      new Settings(array_replace(Settings::getAll(), ['agency_host_origin_approval' => $approval]));
    }
    elseif ($fault === 'entry') {
      $this->config('agency_host.settings')->set('enabled', TRUE)->save();
    }
    elseif ($fault === 'schema') {
      $this->container->get('update.update_hook_registry')->setInstalledVersion('agency_host', 10004);
    }
    else {
      $transaction = $database->startTransaction();
    }
    try {
      $this->assertOriginQualificationRefused($qualifier, $bytes);
      $this->assertSame(0, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
    }
    finally {
      $transaction?->rollBack();
    }
  }

  /**
   * Each execution precondition is independently required.
   */
  public static function originExecutionRefusals(): array {
    return [['plan'], ['entry'], ['schema'], ['nested']];
  }

  /**
   * Installing the ledger cannot retroactively certify retained identity history.
   */
  public function testCleanOriginQualificationSchemaUpgradePreservesHistory(): void {
    $database = $this->container->get('database');
    $updates = $this->container->get('update.update_hook_registry');
    $updates->setInstalledVersion('agency_host', 10002);
    $history = $this->container->get('agency_host.binding_history');
    $database->update('agency_host_binding_coverage')->fields(['fingerprint' => $history->fingerprint()])->execute();
    $this->assertTrue($history->qualificationReady());
    $old = $database->select('agency_host_binding_coverage', 'c')->fields('c')->execute()->fetchAllAssoc('scope');
    $database->schema()->dropTable('agency_host_binding_origin');
    \agency_host_update_10003();
    $updates->setInstalledVersion('agency_host', 10003);
    \agency_host_update_10003();
    $this->assertEquals($old, $database->select('agency_host_binding_coverage', 'c')->fields('c')->execute()->fetchAllAssoc('scope'));
    $this->assertSame(0, (int) $database->select('agency_host_binding_origin', 'o')->countQuery()->execute()->fetchField());
    $this->assertFalse($history->qualificationReady());
  }

  /**
   * A pinned synthetic initial origin is separate from all old retained history.
   */
  private function cleanOriginQualification(bool $invalidateOld = TRUE): array {
    $database = $this->container->get('database');
    if ($invalidateOld) {
      $old = $database->select('agency_host_binding_coverage', 'c')->fields('c', ['epoch'])->execute()->fetchField();
      $this->container->get('agency_host.qualification_latch')->invalidate($old);
    }
    [$planner, $bytes] = $this->cleanOriginPlanner('https://identity.example.test/realms/fresh-origin');
    $plan = $planner->report($bytes);
    $approval = Settings::get('agency_host_origin_approval');
    $approval['plan_sha256'] = $plan['plan_sha256'];
    new Settings(array_replace(Settings::getAll(), ['agency_host_origin_approval' => $approval]));
    return [$this->container->get('agency_host.clean_origin_qualifier'), $bytes];
  }

  /**
   * Refusal never includes identity, receipt or storage details.
   */
  private function assertOriginQualificationRefused(object $qualifier, string $bytes): void {
    try {
      $qualifier->qualify($bytes);
      $this->fail('Unsafe origin qualification was accepted.');
    }
    catch (IdentityDirectoryUnavailable $error) {
      $this->assertSame('Clean-origin qualification is unavailable.', $error->getMessage());
    }
  }

  /**
   * The plan refuses missing approval, active entry and a shared identity realm.
   */
  public function testCleanOriginPlanRefusals(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $this->assertFalse($planner->report($bytes)['qualification_performed']);
    foreach ([TRUE, NULL] as $enabled) {
      $this->config('agency_host.settings')->set('enabled', $enabled)->save();
      $this->assertOriginPlanRefused($planner, $bytes);
    }
    $this->config('agency_host.settings')->set('enabled', FALSE)->save();
    $this->config('openid_connect.client.client_portal')->set('plugin', 'generic')->set('settings.issuer_url', self::ISSUER)->save();
    $this->assertOriginPlanRefused($planner, $bytes);
  }

  /**
   * Complete snapshots include orphan/unrelated mappings and retained history.
   */
  public function testCleanOriginPlanRetainsAndBoundsState(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $database = $this->container->get('database');
    $before = $planner->report($bytes);
    $database->insert('authmap')->fields([
      'uid' => 99999,
      'provider' => 'unrelated-provider',
      'authname' => 'private-synthetic-subject',
    ])->execute();
    $after = $planner->report($bytes);
    $this->assertNotSame($before['plan_sha256'], $after['plan_sha256']);
    $this->assertSame(1, $after['tables']['authmap']['rows']);
    $this->assertStringNotContainsString('private-synthetic-subject', json_encode($after));
    $this->assertStringNotContainsString('fixture-only', json_encode($after));
    $this->assertSame(1, $after['tables']['agency_host_binding_coverage']['rows']);
    $this->assertSame($after, $planner->report($bytes));
    $this->assertFalse($this->container->get('agency_host.binding_history')->qualificationReady());
    $this->assertSame(0, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
    $transaction = $database->startTransaction();
    $this->assertOriginPlanRefused($planner, $bytes);
    $transaction->rollBack();
  }

  /**
   * A second connection's committed history change invalidates the plan digest.
   */
  public function testCleanOriginPlanObservesIndependentChanges(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $before = $planner->report($bytes);
    $key = 'origin_plan_second_connection';
    Database::addConnectionInfo($key, 'default', $this->container->get('database')->getConnectionOptions());
    try {
      $other = Database::getConnection('default', $key);
      $other->insert('agency_host_binding_invalid')->fields(['epoch' => str_repeat('a', 64)])->execute();
      $after = $planner->report($bytes);
      $this->assertNotSame($before['plan_sha256'], $after['plan_sha256']);
      $this->assertSame(1, $after['tables']['agency_host_binding_invalid']['rows']);
      $this->assertSame($before['tables']['agency_host_binding_coverage'], $after['tables']['agency_host_binding_coverage']);
    }
    finally {
      Database::removeConnection($key);
    }
  }

  /**
   * Equivalent ordinary issuers cannot bypass the dedicated-origin boundary.
   */
  public function testCleanOriginPlanNormalizedSharedIssuer(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    foreach ([self::ISSUER . '/', ' ' . self::ISSUER . '/ '] as $issuer) {
      $this->config('agency_host.ordinary_tokens')->set('paladin_issuer', $issuer)->save();
      $this->assertOriginPlanRefused($planner, $bytes);
    }
  }

  /**
   * Ordinary authority drift is material even when it remains independently scoped.
   */
  public function testCleanOriginPlanOrdinaryConfigurationDrift(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $this->config('agency_host.ordinary_tokens')->set('paladin_issuer', 'https://identity.example.test/realms/ordinary')->save();
    $before = $planner->report($bytes);
    $this->config('agency_host.ordinary_tokens')->set('paladin_audience', 'changed-ordinary-audience')->save();
    $this->assertNotSame($before['plan_sha256'], $planner->report($bytes)['plan_sha256']);
  }

  /**
   * Endpoint-only clients and the separate MCP authority cannot share the realm.
   */
  public function testCleanOriginPlanOtherAuthorityConsumers(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $this->config('openid_connect.client.generic_synthetic')->set('plugin', 'generic')->set('settings.token_endpoint', self::ISSUER . '/protocol/openid-connect/token')->save();
    $this->assertOriginPlanRefused($planner, $bytes);
    $this->config('openid_connect.client.generic_synthetic')->delete();
    new Settings(array_replace(Settings::getAll(), ['ordinary_agent_bearer' => ['issuer' => self::ISSUER . '/']]));
    $this->assertOriginPlanRefused($planner, $bytes);
    new Settings(array_replace(Settings::getAll(), ['ordinary_agent_bearer' => ['introspection_url' => self::ISSUER . '/protocol/openid-connect/token/introspect']]));
    $this->assertOriginPlanRefused($planner, $bytes);
    new Settings(array_replace(Settings::getAll(), ['ordinary_agent_bearer' => ['issuer' => 'https://identity.example.test/realms/staff']]));
    $before = $planner->report($bytes);
    new Settings(array_replace(Settings::getAll(), [
      'ordinary_agent_bearer' => [
        'issuer' => 'https://identity.example.test/realms/staff',
        'audience' => 'changed-mcp-audience',
      ],
    ]));
    $this->assertNotSame($before['plan_sha256'], $planner->report($bytes)['plan_sha256']);
  }

  /**
   * Equivalent endpoint spellings still share the dedicated realm.
   */
  public function testCleanOriginPlanEquivalentEndpointSpellings(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $spellings = [
      'https://IDENTITY.example.test/realms/example'
      . '/protocol/openid-connect/token',
      'https://identity.example.test:443/realms/example'
      . '/protocol/openid-connect/token',
      'HTTPS://identity.example.test/realms/example'
      . '/protocol/openid-connect/token',
    ];
    foreach ($spellings as $endpoint) {
      $this->config('openid_connect.client.generic_synthetic')
        ->set('plugin', 'generic')
        ->set('settings.token_endpoint', $endpoint)
        ->save();
      $this->assertOriginPlanRefused($planner, $bytes);
    }
    $this->config('openid_connect.client.generic_synthetic')->delete();
    $shared = 'https://IDENTITY.example.test:443/realms/example'
      . '/protocol/openid-connect/token/introspect';
    new Settings(array_replace(Settings::getAll(), [
      'ordinary_agent_bearer' => ['introspection_url' => $shared],
    ]));
    $this->assertOriginPlanRefused($planner, $bytes);
    $separate = 'https://identity.example.test:8443/realms/example'
      . '/protocol/openid-connect/token/introspect';
    new Settings(array_replace(Settings::getAll(), [
      'ordinary_agent_bearer' => ['introspection_url' => $separate],
    ]));
    $this->assertFalse($planner->report($bytes)['qualification_performed']);
  }

  /**
   * A commit during a report cannot split its repeatable-read table snapshot.
   */
  public function testCleanOriginPlanConcurrentSnapshot(): void {
    [, $bytes] = $this->cleanOriginPlanner();
    $database = $this->container->get('database');
    $key = 'origin_plan_concurrent_connection';
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    try {
      $other = Database::getConnection('default', $key);
      $intercept = $this->createMock(Connection::class);
      foreach (['driver', 'inTransaction', 'startTransaction', 'query', 'prepareStatement'] as $method) {
        $intercept->method($method)->willReturnCallback($database->$method(...));
      }
      $changed = FALSE;
      $intercept->method('select')->willReturnCallback(function ($table, $alias, $options = []) use ($database, $other, &$changed) {
        if ($table === 'agency_host_binding_association' && !$changed) {
          $other->insert('agency_host_binding_invalid')->fields(['epoch' => str_repeat('b', 64)])->execute();
          $changed = TRUE;
        }
        return $database->select($table, $alias, $options);
      });
      $settings = new Settings(Settings::getAll());
      $planner = new CleanOriginPlan(
        $intercept, $settings, $this->container->get('config.factory'), new DatabaseStorage($database, 'config'),
        $this->container->get('agency_host.binding_history'),
        $this->container->get('agency_host.identity_authority'),
        $this->container->get('agency_host.idp_directory'),
        new CleanOriginReceipt($settings, $this->container->get('datetime.time')),
      );
      $report = $planner->report($bytes);
      $this->assertTrue($changed);
      $this->assertSame(0, $report['tables']['agency_host_binding_invalid']['rows']);
      $later = $planner->report($bytes);
      $this->assertSame(1, $later['tables']['agency_host_binding_invalid']['rows']);
      $this->assertNotSame($report['plan_sha256'], $later['plan_sha256']);
    }
    finally {
      Database::removeConnection($key);
    }
  }

  /**
   * The CLI discovers the service but rejects unprotected receipt files.
   */
  public function testCleanOriginPlanCommandBoundary(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $class = AgencyOriginCommands::class;
    $manager = new ServiceManager($this->classLoader, new DrushConfig(), new NullLogger());
    $path = DRUPAL_ROOT . '/' . $this->container->get('module_handler')->getModule('agency_host')->getPath() . '/src/Drush';
    $this->assertContains($class, $manager->discoverModuleCommands([$path], 'Drupal\\agency_host\\Drush'));
    $handler = $class::create($this->container);
    $handler->setLogger(new DrushLoggerManager());
    $output = new BufferedOutput();
    $handler->setOutput($output);
    $input = new ArrayInput([]);
    $handler->setInput($input);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handler);
    $commands = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'agency:origin-plan'));
    $this->assertCount(1, $commands);
    $this->assertSame(1, $commands[0]->run($input, $output));
    $file = tempnam(sys_get_temp_dir(), 'agency-origin-synthetic-');
    try {
      file_put_contents($file, $bytes);
      chmod($file, 0644);
      new Settings(array_replace(Settings::getAll(), ['agency_host_origin_receipt_file' => realpath($file)]));
      $this->assertSame(1, $commands[0]->run($input, $output));
      chmod($file, 0600);
      clearstatcache(TRUE, $file);
      if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->assertSame(0, $commands[0]->run($input, $output));
        $this->assertSame($planner->report($bytes), json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR));
      }
      else {
        $this->assertSame(1, $commands[0]->run($input, $output));
        $this->assertSame('', $output->fetch());
      }
    }
    finally {
      unlink($file);
    }
  }

  /**
   * Oversized retained state is a refusal, not a plausible partial snapshot.
   */
  public function testCleanOriginPlanRejectsOversizedSnapshot(): void {
    [$planner, $bytes] = $this->cleanOriginPlanner();
    $database = $this->container->get('database');
    $database->query('INSERT INTO {agency_host_binding_invalid} (epoch) SELECT repeat(md5(i::text), 2) FROM generate_series(1, 100001) AS i');
    $this->assertOriginPlanRefused($planner, $bytes);
    $this->assertSame(100001, (int) $database->select('agency_host_binding_invalid', 'i')->countQuery()->execute()->fetchField());
    $this->assertFalse($this->container->get('agency_host.binding_history')->qualificationReady());
  }

  /**
   * Makes a synthetic pinned receipt while preserving the old coverage record.
   */
  private function cleanOriginPlanner(?string $issuer = NULL): array {
    $this->config('agency_host.settings')->set('enabled', FALSE)->save();
    $identity = Settings::get('agency_host_identity');
    $identity['binding_mode'] = 'unbound_only';
    if ($issuer !== NULL) {
      $identity['issuer'] = $issuer;
      $identity['realm'] = basename(parse_url($issuer, PHP_URL_PATH));
      $identity['token_url'] = $issuer . '/protocol/openid-connect/token';
      $this->config('agency_host.identity_tokens')->set('paladin_issuer', $issuer)->save();
    }
    new Settings(array_replace(Settings::getAll(), ['agency_host_identity' => $identity]));
    $history = $this->container->get('agency_host.binding_history');
    $receipt = [
      'format' => 'agency-clean-origin-v1',
      'issuer' => $identity['issuer'],
      'binding_version' => $identity['binding_version'],
      'host_fingerprint' => $history->fingerprint(),
      'created_at' => $this->now - 60,
      'contained_from' => $this->now - 120,
      'expires_at' => $this->now + 600,
    ];
    foreach (['target_sha256', 'realm_incarnation_sha256', 'creation_record_sha256',
      'containment_record_sha256', 'execution_go_sha256', 'operator_sha256', 'ui_composition_sha256',
    ] as $key) {
      $receipt[$key] = hash('sha256', 'synthetic-' . $key);
    }
    ksort($receipt);
    $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    new Settings(array_replace(Settings::getAll(), [
      'agency_host_origin_approval' => [
        'receipt_sha256' => hash('sha256', $bytes),
        'target_sha256' => $receipt['target_sha256'],
        'execution_go_sha256' => $receipt['execution_go_sha256'],
      ],
    ]));
    $planner = $this->container->get('agency_host.clean_origin_plan');
    return [$planner, $bytes];
  }

  /**
   * Neither refusal details nor database failures may disclose private inputs.
   */
  private function assertOriginPlanRefused(CleanOriginPlan $planner, string $bytes): void {
    try {
      $planner->report($bytes);
      $this->fail('Unsafe origin plan was accepted.');
    }
    catch (IdentityDirectoryUnavailable $error) {
      $this->assertSame('Clean-origin plan is unavailable.', $error->getMessage());
    }
  }

  /**
   * Real signed proofs cannot cross the dedicated and ordinary authorities.
   */
  public function testDedicatedAuthorityProofs(): void {
    $portal = 'https://identity.example.test/realms/portal';
    $agency = 'https://identity.example.test/realms/agency';
    $this->config('agency_host.ordinary_tokens')
      ->set('paladin_issuer', $portal)->set('paladin_audience', 'portal-api')->save();
    $this->config('agency_host.identity_tokens')
      ->set('paladin_issuer', $agency)->set('paladin_audience', 'agency-api')->save();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwks = [
      'keys' => [[
        'kty' => 'RSA',
        'kid' => 'synthetic',
        'alg' => 'RS256',
        'use' => 'sig',
        'n' => $encode($details['rsa']['n']),
        'e' => $encode($details['rsa']['e']),
      ],
      ],
    ];
    $http = new Client([
      'handler' => static function (RequestInterface $request) use ($jwks) {
        $url = (string) $request->getUri();
        $body = str_ends_with($url, '/.well-known/openid-configuration')
        ? ['jwks_uri' => substr($url, 0, -strlen('/.well-known/openid-configuration')) . '/keys'] : $jwks;
        return Create::promiseFor(new Response(200, [], json_encode($body)));
      },
    ]);
    $make = fn(string $name) => new JwksAccessTokenValidator(
      $http, $this->container->get('cache.default'), $this->container->get('datetime.time'),
      new NullLogger(), $this->container->get('config.factory'), $name,
    );
    $ordinary = $make('agency_host.ordinary_tokens');
    $dedicated = $make('agency_host.identity_tokens');
    $proof = static fn(array $claims): string => JWT::encode($claims, $key, 'RS256', 'synthetic');
    $claims = ['iss' => $agency, 'aud' => 'agency-api', 'sub' => 'synthetic-person', 'exp' => time() + 600];
    $agency_proof = $proof($claims);
    $portal_proof = $proof(array_replace($claims, ['iss' => $portal, 'aud' => 'portal-api']));
    $this->assertSame('synthetic-person', $ordinary->validate($portal_proof));
    $this->assertNull($ordinary->validate($agency_proof));
    $this->assertSame('synthetic-person', $dedicated->validate($agency_proof));
    $this->assertNull($dedicated->validate($portal_proof));
    foreach ([['aud' => 'portal-api'], ['sub' => ''], ['exp' => time() - 600]] as $change) {
      $this->assertNull($dedicated->validate($proof(array_replace($claims, $change))));
    }
    $other_key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $this->assertNull($dedicated->validate(JWT::encode($claims, $other_key, 'RS256', 'synthetic')));
    $this->config('agency_host.identity_tokens')->set('paladin_issuer', '')->save();
    $this->assertFalse($dedicated->isConfigured());
    $this->assertNull($dedicated->validate($portal_proof));
    $this->assertSame('synthetic-person', $ordinary->validate($portal_proof));
  }

  /**
   * Clean-origin subjects cannot inherit matching Drupal account bindings.
   */
  #[DataProvider('dedicatedBindingProviders')]
  public function testDedicatedAuthorityUnboundOnly(string $provider, bool $orphan): void {
    $identity = Settings::get('agency_host_identity');
    $identity['binding_mode'] = 'unbound_only';
    new Settings(array_replace(Settings::getAll(), ['agency_host_identity' => $identity]));
    $history = $this->container->get('agency_host.binding_history');
    // This known-empty fixture has no historical identities to migrate.
    $this->container->get('database')->update('agency_host_binding_coverage')
      ->fields(['fingerprint' => $history->fingerprint()])->execute();
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    if ($orphan) {
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn(900001);
    }
    else {
      $user = User::create(['name' => 'synthetic-collision', 'status' => 1]);
      $user->save();
    }
    $this->container->get('externalauth.authmap')->save($user, $provider, 'person-one');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
    $intended = $this->container->get('agency_host.identity')->withIntendedSubject(
      'person-one', $this->input()['contact'], static fn(): array => ['outcome' => 'unexpected']);
    $this->assertSame('refused', $intended['outcome']);
    $this->container->get('externalauth.authmap')->delete((int) $user->id(), $provider);
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Both covered authmap writers can collide with an isolated subject.
   */
  public static function dedicatedBindingProviders(): array {
    return [
      ['openid_connect.client_portal', FALSE], ['openid_connect.sign_in_with_keycloak', FALSE],
      ['openid_connect.client_portal', TRUE], ['openid_connect.sign_in_with_keycloak', TRUE],
    ];
  }

  /**
   * Restoring authority configuration cannot revive a drifted history epoch.
   */
  #[DataProvider('dedicatedAuthorityChanges')]
  public function testDedicatedAuthorityDrift(string $field, string $changed): void {
    $this->useRealAgencyProofs();
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $config = $this->config('agency_host.identity_tokens');
    $original = $config->get();
    $config->set($field, $changed)->save();
    $this->assertSame('refused', $this->payload($controller->workspace($id, $request))['outcome']);
    $config->setData($original)->save();
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason'] ?? NULL);
  }

  /**
   * Valid authority, a removed audience and a new issuer all invalidate history.
   */
  public static function dedicatedAuthorityChanges(): array {
    return [
      ['paladin_audience', 'changed-synthetic-audience'],
      ['paladin_audience', ''],
      ['paladin_issuer', 'https://identity.example.test/realms/replacement'],
    ];
  }

  /**
   * Actual container services retain separate participant and portal authority.
   */
  public function testDedicatedAuthorityContainerBoundary(): void {
    $this->useRealAgencyProofs();
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $proof = $request->headers->get(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER);
    $ordinary = new JwksAccessTokenValidator(
      $this->container->get('http_client'), $this->container->get('cache.default'),
      $this->container->get('datetime.time'), new NullLogger(),
      $this->container->get('config.factory'), 'agency_host.ordinary_tokens',
    );
    $dedicated = $this->container->get('agency_host.access_token_validator');
    $this->assertInstanceOf(JwksAccessTokenValidator::class, $dedicated);
    $this->assertNotSame($ordinary, $dedicated);
    $this->assertSame('person-one', $dedicated->validate($proof));
    $this->assertNull($ordinary->validate($proof));
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $portal = ($this->realProofSigner)('person-one', [
      'iss' => 'https://identity.example.test/realms/portal',
      'aud' => 'portal-api',
    ]);
    $this->assertSame('person-one', $ordinary->validate($portal));
    $this->assertNull($dedicated->validate($portal));
    $before = $this->lookups;
    $request->headers->set(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, $portal);
    $this->assertSame('refused', $this->payload($controller->workspace($id, $request))['outcome']);
    $this->assertSame($before, $this->lookups);
  }

  /**
   * Optional real signed proof factory for controller-level authority checks.
   */
  private ?\Closure $realProofSigner = NULL;

  /**
   * Uses real service wiring with synthetic JWKS and existing directory I/O.
   */
  private function useRealAgencyProofs(): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwks = [
      'keys' => [[
        'kty' => 'RSA',
        'kid' => 'synthetic',
        'alg' => 'RS256',
        'use' => 'sig',
        'n' => $encode($details['rsa']['n']),
        'e' => $encode($details['rsa']['e']),
      ],
      ],
    ];
    $directory = $this->container->get('http_client');
    $this->container->set('http_client', new Client([
      'handler' => static function (RequestInterface $request) use ($jwks, $directory) {
        $url = (string) $request->getUri();
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
          return Create::promiseFor(new Response(200, [], json_encode(['jwks_uri' => substr($url, 0, -strlen('/.well-known/openid-configuration')) . '/keys'])));
        }
        if (str_ends_with($url, '/keys')) {
          return Create::promiseFor(new Response(200, [], json_encode($jwks)));
        }
        return $directory->sendAsync($request);
      },
    ]));
    $this->config('agency_host.ordinary_tokens')->set('paladin_issuer', 'https://identity.example.test/realms/portal')
      ->set('paladin_audience', 'portal-api')->save();
    $this->container->set('agency_host.access_token_validator', NULL);
    $this->realProofSigner = static fn(string $subject, array $changes = []): string => JWT::encode(array_replace([
      'iss' => self::ISSUER,
      'aud' => 'synthetic-agency',
      'sub' => $subject,
      'exp' => time() + 600,
    ], $changes), $key, 'RS256', 'synthetic');
  }

  /**
   * Unknown or missing binding semantics cannot pass either identity path.
   */
  #[DataProvider('dedicatedInvalidModes')]
  public function testDedicatedAuthorityInvalidMode(?string $mode): void {
    [$id] = $this->accepted();
    $identity = Settings::get('agency_host_identity');
    $identity['binding_mode'] = $mode;
    new Settings(array_replace(Settings::getAll(), ['agency_host_identity' => $identity]));
    $request = $this->request('person-one', 'GET');
    $result = $this->payload(ParticipantDiscoveryController::create($this->container)->workspace($id, $request));
    $this->assertSame('refused', $result['outcome']);
    $intended = $this->container->get('agency_host.identity')->withIntendedSubject(
      'person-one', $this->input()['contact'], static fn(): array => ['outcome' => 'unexpected']);
    $this->assertSame('refused', $intended['outcome']);
    $checks = $this->container->get('agency_host.runtime_readiness')->report()['checks'];
    $this->assertFalse($checks['identity_issuer_alignment']);
  }

  /**
   * Missing and misspelled modes must not select compatible binding behavior.
   */
  public static function dedicatedInvalidModes(): array {
    return [[NULL], ['unbound-onli']];
  }

  private const ISSUER = 'https://identity.example.test/realms/example';
  /**
   * Fresh synthetic human coordinator.
   */
  private User $coordinator;
  /**
   * Mutable authoritative readback fixtures.
   */
  private array $people = [];
  /**
   * Simulated readback failure.
   */
  private bool $outage = FALSE;
  /**
   * Synthetic failure at the public outbox connection boundary.
   */
  private bool $recordingFailure = FALSE;
  /**
   * Readback count for authorization-order assertions.
   */
  private int $lookups = 0;
  /**
   * Versioned synthetic host seed.
   */
  private array $seed;
  /**
   * Controlled public clock for expiry qualification.
   */
  private int $now;

  /**
   * Installs only synthetic records, policies and remote responses.
   */
  protected function setUp(): void {
    parent::setUp();
    // Kernel's memory key/value factories do not create Core database stores.
    $schema = $this->container->get('database')->schema();
    foreach ([
      'key_value' => KeyValueDatabaseStorage::class,
      'key_value_expire' => DatabaseStorageExpirable::class,
    ] as $table => $store_class) {
      if (!$schema->tableExists($table)) {
        $schema->createTable($table, $store_class::schemaDefinition());
      }
    }
    $seed = file_get_contents(__DIR__ . '/../../fixtures/synthetic-host.json');
    $this->seed = json_decode((string) $seed, TRUE, 8, JSON_THROW_ON_ERROR);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('path_alias');
    $this->installSchema('externalauth', ['authmap']);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('externalauth', 8104);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('openid_connect', 30007);
    $this->container->get('update.update_hook_registry')->setInstalledVersion('chronicle_agency', 10003);
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])
      ->execute();
    $this->installConfig(['system', 'user', 'field', 'audit_chain']);
    $this->container->get('module_installer')->install(['agency', 'agency_staff', 'agency_host'], FALSE);
    $this->container = \Drupal::getContainer();
    $this->now = time();
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getCurrentMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $clock->method('getRequestMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $this->container->set('datetime.time', $clock);
    $database = $this->container->get('database');
    $recordingConnection = $this->createMock(Connection::class);
    $recordingConnection->method('inTransaction')->willReturnCallback($database->inTransaction(...));
    $recordingConnection->method('startTransaction')->willReturnCallback($database->startTransaction(...));
    $recordingConnection->method('select')->willReturnCallback($database->select(...));
    $recordingConnection->method('update')->willReturnCallback($database->update(...));
    $recordingConnection->method('insert')->willReturnCallback(function ($table, array $options = []) use ($database) {
      if ($this->recordingFailure) {
        throw new \RuntimeException('Synthetic recording connection failure.');
      }
      return $database->insert($table, $options);
    });
    $this->container->set('agency_staff.outbox', new EvidenceOutbox($recordingConnection,
      $this->container->get('audit_chain.logger'), $this->container->get('uuid'), $this->container->get('datetime.time')));
    $this->config('agency_host.identity_tokens')->set('paladin_issuer', self::ISSUER)
      ->set('paladin_audience', 'synthetic-agency')
      ->set('issuer', self::ISSUER)->set('audience', 'synthetic-agency')->save();
    $this->config('agency_host.settings')
      ->set('binding_providers', [
        'openid_connect.sign_in_with_keycloak',
        'openid_connect.client_portal',
      ])
      ->set('contained_openid_clients', ['client_portal'])
      ->set('extra_containment_config', ['agency_host.ordinary_tokens'])
      ->set('extra_containment_settings', ['ordinary_agent_bearer'])
      ->set('source_digest_modules', ['agency_host'])
      ->save();
    $this->config('agency.settings')->setData($this->seed['tenant'])->save();
    $settings = new Settings(Settings::getAll() + [
      'agency_host_identity' => [
        'issuer' => self::ISSUER,
        'binding_version' => 'synthetic-origin-v1',
        'binding_mode' => 'mapped_or_unbound',
        'token_url' => self::ISSUER . '/protocol/openid-connect/token',
        'admin_base_url' => 'https://admin.example.test',
        'realm' => 'example',
        'client_id' => 'synthetic-readback',
        'client_secret' => 'fixture-only',
      ],
    ]);
    $this->container->set('settings', $settings);
    if ($this->container->get('database')->getConnectionOptions()['driver'] !== 'pgsql') {
      // SQLite cannot independently commit while the Agency writer is active.
      // This seam exercises decisions; PostgreSQL proves durability.
      $this->container->set('agency_host.qualification_latch', new class() implements QualificationLatchInterface {
        /**
         * Invalidated synthetic epochs.
         *
         * @var array<string, bool>
         */
        private array $invalid = [];

        /**
         * Outstanding test-only verification guards.
         */
        private array $guards = [];

        /**
         * {@inheritdoc}
         */
        public function arm(string $epoch): string {
          $guard = bin2hex(random_bytes(32));
          $this->guards[$guard] = $epoch;
          return $guard;
        }

        /**
         * {@inheritdoc}
         */
        public function resolve(string $guard): void {
          unset($this->guards[$guard]);
        }

        /**
         * {@inheritdoc}
         */
        public function invalidate(string $epoch): void {
          $this->invalid[$epoch] = TRUE;
        }

        /**
         * {@inheritdoc}
         */
        public function invalidated(string $epoch): bool {
          return isset($this->invalid[$epoch]) || in_array($epoch, $this->guards, TRUE);
        }

      });
    }
    // This fixture starts with an empty mapping history, unlike an upgrade.
    $history = $this->container->get('agency_host.binding_history');
    $this->assertSame([], $history->mappings());
    $this->container->get('database')->insert('agency_host_binding_coverage')->fields([
      'scope' => $history->scope(),
      'fingerprint' => $history->fingerprint(),
      'epoch' => bin2hex(random_bytes(32)),
      'provenance' => 'Kernel fixture: known empty origin',
      'qualified' => 1,
    ])->execute();
    $validator = $this->createMock(AccessTokenValidatorInterface::class);
    $validator->method('isConfigured')->willReturn(TRUE);
    $validator->method('validate')->willReturnCallback(static function (string $proof): ?string {
      $parts = explode('.', $proof);
      return json_decode((string) base64_decode($parts[1] ?? ''), TRUE)['sub'] ?? NULL;
    });
    $this->container->set('agency_host.access_token_validator', $validator);
    foreach ($this->seed['subjects'] as $person) {
      $this->people[$person['id']] = $person;
    }
    $this->container->set('http_client', new Client([
      'handler' => function (RequestInterface $request) {
        if ($request->getMethod() === 'POST') {
          return Create::promiseFor(new Response(200, [], '{"access_token":"fixture"}'));
        }
        $this->lookups++;
        $subject = basename($request->getUri()->getPath());
        if ($subject === 'users') {
          parse_str($request->getUri()->getQuery(), $query);
          $records = array_values(array_filter($this->people, static fn(array $person): bool => ($person['email'] ?? '') === ($query['email'] ?? NULL)));
          return Create::promiseFor(new Response($this->outage ? 503 : 200, [], json_encode(array_slice($records, 0, 2))));
        }

        return Create::promiseFor(new Response($this->outage ? 503 : (isset($this->people[$subject]) ? 200 : 404), [], json_encode($this->people[$subject] ?? [])));
      },
    ]));
    User::create(['name' => 'reserved', 'status' => 1])->save();
    Role::create([
      'id' => 'employee',
      'label' => 'Employee',
      'permissions' => [],
    ])->save();
    Role::create([
      'id' => 'coordinator',
      'label' => 'Coordinator',
      'permissions' => [
        'coordinate agency discovery',
        'suspend agency discovery access',
      ],
    ])->save();
    $this->coordinator = User::create([
      'name' => 'coordinator',
      'status' => 1,
      'roles' => ['employee', 'coordinator'],
    ]);
    $this->coordinator->save();
    $this->container->get('current_user')->setAccount($this->coordinator);
  }

  /**
   * The eligible coordinator can reach a private rendered queue route.
   */
  public function testConsoleQueueRendersWithoutExposingNotes(): void {
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('agency_host.console');
    $this->assertSame('/admin/agency/discovery/console', $route->getPath());
    $this->assertSame(['GET'], $route->getMethods());
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $controller = DiscoveryConsoleController::create($this->container);
    $build = $controller->queue($request);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('No conversations are assigned to you', $html);
    $this->assertStringContainsString('Prepare a conversation', $html);
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertStringNotContainsString('suspendAccess', $html);
  }

  /**
   * Readiness cannot qualify history, call the IdP, or expose private values.
   */
  public function testRuntimeReadinessIsReadOnlyAndRefusesMissingConfiguration(): void {
    $database = $this->container->get('database');
    $snapshot = function () use ($database): array {
      $result = [];
      foreach (['mutex', 'coverage', 'history', 'association', 'invalid', 'guard'] as $suffix) {
        $result[$suffix] = $database->select('agency_host_binding_' . $suffix, 't')
          ->fields('t')->execute()->fetchAll();
      }
      return $result;
    };
    $before = $snapshot();
    $lookups = $this->lookups;
    $readiness = $this->container->get('agency_host.runtime_readiness');
    $report = $readiness->report();
    $this->assertFalse($report['configuration_ready']);
    $this->assertFalse($report['checks']['invitation_custody']);
    $this->assertFalse($report['checks']['evidence_alert_configuration']);
    $this->assertTrue($report['checks']['binding_history']);
    $this->assertSame($report, $readiness->report());
    $this->assertEquals($before, $snapshot());
    $this->assertSame($lookups, $this->lookups);
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('fixture-only', $encoded);
    $this->assertStringNotContainsString(self::ISSUER, $encoded);
    $database->delete('agency_host_binding_coverage')->execute();
    $this->assertFalse($readiness->report()['checks']['binding_history']);
    $this->assertSame(0, (int) $database->select('agency_host_binding_coverage', 'c')->countQuery()->execute()->fetchField());
  }

  /**
   * A retained verification guard cannot be resolved by a readiness check.
   */
  public function testRuntimeReadinessPreservesAnUnresolvedHistoryGuard(): void {
    $database = $this->container->get('database');
    $epoch = $database->select('agency_host_binding_coverage', 'c')->fields('c', ['epoch'])
      ->execute()->fetchField();
    $latch = $this->container->get('agency_host.qualification_latch');
    $guard = $latch->arm($epoch);
    $report = $this->container->get('agency_host.runtime_readiness')->report();
    $this->assertFalse($report['checks']['binding_history']);
    $this->assertTrue($latch->invalidated($epoch));
    $latch->resolve($guard);
    $this->assertTrue($this->container->get('agency_host.runtime_readiness')->report()['checks']['binding_history']);
  }

  /**
   * Complete configuration passes before participant entry is enabled.
   */
  public function testRuntimeReadinessAcceptsCompleteConfiguration(): void {
    $this->config('agency.settings')->set('langcode', 'en')->save();
    $this->config('agency_host.settings')->set('enabled', FALSE)
      ->set('bff_client_id', 'synthetic-bff')->save();
    $settings = new Settings(array_replace(Settings::getAll(), [
      'agency_host_invitation_origin' => 'https://participants.example.test',
      'agency_host_invitation_delivery' => [
        'mode' => 'capture',
        'key_id' => 'synthetic',
        'keys' => ['synthetic' => base64_encode(str_repeat('s', 32))],
        'payload_retention_seconds' => 60,
      ],
      'agency_host_evidence_alert' => [
        'url' => 'https://alerts.example.test/agency',
        'token' => 'synthetic-alert-token',
        'after_seconds' => '60',
      ],
    ]));
    $this->container->set('settings', $settings);
    $lookups = $this->lookups;
    $report = $this->container->get('agency_host.runtime_readiness')->report();
    $violations = $this->container->get('config.typed')->createFromNameAndData('agency.settings', $this->config('agency.settings')->get())->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $this->assertTrue($report['checks']['postgresql_16']);
    $this->assertTrue($report['configuration_ready'], json_encode($report));
    $this->assertSame('configuration_and_retained_state_only', $report['scope']);
    $this->assertSame($lookups, $this->lookups);
    $this->assertStringNotContainsString('synthetic-alert-token', json_encode($report));
    $this->assertFalse($report['participant_channel_enabled']);
    $this->assertArrayNotHasKey('participant_channel_enabled', $report['checks']);
    $this->assertReadinessCommand(0, $report);
    $this->assertFalse($this->config('agency_host.settings')->get('enabled'));
    $this->config('agency_host.settings')->set('enabled', TRUE)->save();
    $enabled = $this->container->get('agency_host.runtime_readiness')->report();
    $this->assertTrue($enabled['participant_channel_enabled']);
    $this->assertTrue($enabled['configuration_ready']);
    $this->assertSame($report['checks'], $enabled['checks']);
    $this->config('agency.settings')->set('policy.close_reasons', [
      ['id' => 'same', 'label' => 'One', 'message' => 'One'],
      ['id' => 'same', 'label' => 'Two', 'message' => 'Two'],
    ])->save();
    $report = $this->container->get('agency_host.runtime_readiness')->report();
    $this->assertFalse($report['checks']['tenant_policy']);
    $this->assertReadinessCommand(1, $report);
  }

  /**
   * Exercises Drush discovery, autowiring and the command entry point.
   */
  private function assertReadinessCommand(int $status, array $report): void {
    $manager = new ServiceManager($this->classLoader, new DrushConfig(), new NullLogger());
    $path = DRUPAL_ROOT . '/' . $this->container->get('module_handler')->getModule('agency_host')->getPath() . '/src/Drush';
    $this->assertContains(AgencyReadinessCommands::class, $manager->discoverModuleCommands([$path], 'Drupal\\agency_host\\Drush'));
    $handler = AgencyReadinessCommands::create($this->container);
    $output = new BufferedOutput();
    $handler->setOutput($output);
    $input = new ArrayInput([]);
    $handler->setInput($input);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handler);
    $commands = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'agency:readiness'));
    $this->assertCount(1, $commands);
    $this->assertSame($status, $commands[0]->run($input, $output));
    $this->assertSame($report, json_decode($output->fetch(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Disabled delivery cannot prepare a relationship or reach mail transport.
   */
  public function testInvitationDeliveryRequiresExplicitCustodyConfiguration(): void {
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $delivery = $this->container->get('agency_host.invitation_delivery');
    $before = $this->container->get('agency_staff.reads')->queue();
    $lookups = $this->lookups;
    $this->assertSame(['outcome' => 'refused', 'reason' => 'delivery_disabled'],
      $delivery->prepare($request, [], $this->input(), 'disabled-delivery'));
    $this->assertSame($before, $this->container->get('agency_staff.reads')->queue());
    $this->assertSame($lookups, $this->lookups);
  }

  /**
   * Captured approval dispatches once, and an ambiguous handoff never retries.
   */
  public function testInvitationDeliveryFreezesApprovalAndFencesUnknown(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'delivery-prepare');
    $this->assertSame('committed', $prepared['outcome'], json_encode($prepared));
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'delivery-issue', 1);
    $this->assertSame('committed', $captured['outcome'], json_encode($captured));
    $delivery_id = $captured['delivery_id'];
    $preview = $delivery->preview($request, $delivery_id);
    $this->assertSame('available', $preview['outcome'], json_encode($preview));
    $this->assertSame('person-one@example.test', $preview['delivery']['recipient']);
    $this->assertStringContainsString('Sign in to view your invitation', $preview['delivery']['body']);
    $this->assertCount(0, $mail->messages);
    $this->assertSame('preview_changed', $delivery->dispatch($request, $delivery_id, str_repeat('0', 64), 'delivery-approval')['reason']);
    $this->assertCount(0, $mail->messages);
    $mail->outcome = 'unknown';
    $result = $delivery->dispatch($request, $delivery_id, $preview['delivery']['fingerprint'], 'delivery-approval');
    $this->assertSame('unknown', $result['outcome'], json_encode($result));
    $this->assertCount(1, $mail->messages);
    $again = $delivery->dispatch($request, $delivery_id, $preview['delivery']['fingerprint'], 'delivery-approval');
    $this->assertSame('unknown', $again['outcome']);
    $this->assertCount(1, $mail->messages);
    $this->assertSame('unknown', $delivery->dispatch($request, $delivery_id, $preview['delivery']['fingerprint'], 'different-approval')['outcome']);
    $this->assertCount(1, $mail->messages);
    $this->assertSame('open', $this->container->get('agency_staff.invitations')->inspect($captured['invitation']['token'])['status']);
    $this->assertCount(2, User::loadMultiple(), 'Delivery never provisions a participant account.');
  }

  /**
   * Authority, generation and contact changes invalidate earlier send approval.
   */
  public function testInvitationDeliveryRevalidatesAndRefusesLegacyMapping(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $legacy = $this->container->get('agency_host.identity')->withIntendedSubject('person-one', $this->input()['contact'],
      fn(): array => $this->container->get('agency_staff.commands')->startDiscovery($this->input(), 'legacy-delivery'));
    $this->assertSame('delivery_unmapped', $delivery->capture($request, $legacy['receipt']['relationship_id'], 'issue', 'legacy-delivery-issue', 1)['reason']);
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'delivery-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'delivery-issue', 1);
    $preview = $delivery->preview($request, $captured['delivery_id']);
    $this->people['person-one']['enabled'] = FALSE;
    $this->assertSame('identity_not_found', $delivery->dispatch($request, $captured['delivery_id'], $preview['delivery']['fingerprint'], 'delivery-deactivated')['reason']);
    $this->assertCount(0, $mail->messages);
    $this->people['person-one']['enabled'] = TRUE;
    $revoked = $this->container->get('agency_staff.invitations')->revoke($id, 'delivery-revoke', 2);
    $this->assertSame('committed', $revoked['outcome']);
    $this->assertSame('not_found', $delivery->dispatch($request, $captured['delivery_id'], $preview['delivery']['fingerprint'], 'delivery-revoked')['reason']);
    $this->assertCount(0, $mail->messages);
  }

  /**
   * A lost advisory lease cannot revive an already claimed delivery attempt.
   */
  public function testInvitationDeliveryStaleReviewCannotDispatchTwice(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'race-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'race-issue', 1);
    $delivery_id = $captured['delivery_id'];
    $fingerprint = $delivery->preview($request, $delivery_id)['delivery']['fingerprint'];
    $other = $delivery;
    $connection_key = NULL;
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] === 'pgsql') {
      $connection_key = 'agency_delivery_race_' . bin2hex(random_bytes(12));
      Database::addConnectionInfo($connection_key, 'default', $database->getConnectionOptions());
      $second_database = Database::getConnection('default', $connection_key);
      $this->assertNotSame($database->query('SELECT pg_backend_pid()')->fetchField(), $second_database->query('SELECT pg_backend_pid()')->fetchField());
      $audit = new DatabaseDeliveryAudit($second_database, $this->container->get('datetime.time'));
      $ledger = new DeliveryLedger($second_database,
        $this->container->get('agency_host.delivery_configuration'), $this->container->get('datetime.time'), $audit);
      $other = new InvitationDelivery(
        $this->container->get('agency_host.console'), $this->container->get('agency_host.console_selection'),
        $this->container->get('agency_host.identity'), $this->container->get('agency_staff.commands'),
        $this->container->get('agency_staff.reads'), $this->container->get('agency_staff.invitations'),
        $this->container->get('agency_host.delivery_configuration'), $ledger, $mail,
        $this->container->get('agency_host.delivery_suppression'), $this->container->get('current_user'), $this->container->get('lock'),
        $this->container->get('agency_staff.access'), $this->container->get('entity_type.manager'), $this->container->get('config.factory'));
    }
    $mail->beforePrepare = function () use ($other, $request, $delivery_id, $fingerprint): void {
      // Pause caller one, lose its lease, and let the independent writer finish.
      $this->container->get('lock')->releaseAll();
      $second = $other->dispatch($request, $delivery_id, $fingerprint, 'race-second');
      $this->assertSame('test_captured', $second['outcome']);
    };
    try {
      $first = $delivery->dispatch($request, $delivery_id, $fingerprint, 'race-first');
      $this->assertSame('refused', $first['outcome']);
      $this->assertCount(1, $mail->messages, 'A stale review cannot reopen the committed attempt fence.');
    }
    finally {
      if ($connection_key !== NULL) {
        Database::removeConnection($connection_key);
      }
    }
  }

  /**
   * Lost custody never reconstructs a token or authorizes an automatic resend.
   */
  public function testInvitationDeliveryLostCaptureAndRetention(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'capture-gap-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $committed = $this->container->get('agency_staff.invitations')->issue($id, 'capture-gap-issue', 1);
    $this->assertSame('committed', $committed['outcome']);
    $gap = $delivery->capture($request, $id, 'issue', 'capture-gap-issue', 1);
    $this->assertSame('capture_unavailable', $gap['delivery_state']);
    $this->assertArrayNotHasKey('invitation', $gap);
    $this->assertSame('capture_unavailable', $delivery->preview($request, $gap['delivery_id'])['outcome']);
    $this->assertSame('capture_unavailable', $delivery->dispatch($request, $gap['delivery_id'], str_repeat('0', 64), 'gap-send')['outcome']);
    $this->assertCount(0, $mail->messages);
    $this->assertSame('open', $this->container->get('agency_staff.invitations')->inspect($committed['invitation']['token'])['status']);
    $settings = Settings::get('agency_host_invitation_delivery');
    unset($settings['payload_retention_seconds']);
    new Settings(array_replace(Settings::getAll(), ['agency_host_invitation_delivery' => $settings]));
    $this->assertSame('delivery_disabled', $delivery->preview($request, $gap['delivery_id'])['reason']);
  }

  /**
   * Tokens stay encrypted; swapped ciphertext cannot be used for another row.
   */
  public function testInvitationDeliveryRejectsCustodySubstitutionAndLateDeactivation(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'custody-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'custody-issue', 1);
    $first = $captured['delivery_id'];
    $again = $delivery->capture($request, $id, 'issue', 'custody-issue', 1);
    $this->assertArrayNotHasKey('invitation', $again, 'Only an authorized preview can recover captured token content.');
    $preview = $delivery->preview($request, $first);
    $mail->beforePrepare = function (): void {
      $this->people['person-one']['enabled'] = FALSE;
    };
    $result = $delivery->dispatch($request, $first, $preview['delivery']['fingerprint'], 'late-deactivation');
    $this->assertSame('identity_not_found', $result['reason']);
    $this->assertCount(0, $mail->messages);
    $this->people['person-one']['enabled'] = TRUE;
    $second = $delivery->capture($request, $id, 'resend', 'custody-resend', 2)['delivery_id'];
    $ledger = $this->container->get('agency_host.delivery_ledger');
    $row = $ledger->read($first);
    $this->assertStringNotContainsString($captured['invitation']['token'], $row['payload']);
    $this->assertStringNotContainsString('person-one@example.test', $row['payload']);
    $this->container->get('database')->update('agency_host_delivery')->fields(['payload' => $row['payload']])->condition('id', $second)->execute();
    $this->assertSame('delivery_unavailable', $delivery->preview($request, $second)['reason']);
    $this->assertCount(0, $mail->messages);
  }

  /**
   * Late identity loss must not expose a newly formatted private preview.
   */
  public function testInvitationDeliveryLateDeactivationHidesPreview(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'late-preview-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'late-preview-issue', 1);
    $mail->beforePrepare = function (): void {
      $this->people['person-one']['enabled'] = FALSE;
    };
    $result = $delivery->preview($request, $captured['delivery_id']);
    $this->assertSame('refused', $result['outcome']);
    $this->assertArrayNotHasKey('delivery', $result);
    $this->assertStringNotContainsString($captured['invitation']['token'], json_encode($result));
    $this->assertCount(0, $mail->messages);
  }

  /**
   * Removing approval during formatting cannot authorize a late handoff.
   */
  public function testInvitationDeliveryFreshApprovalPermissionBeforeHandoff(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'permission-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'permission-issue', 1);
    $preview = $delivery->preview($request, $captured['delivery_id']);
    $before = $this->container->get('agency_staff.reads')->queue();
    $mail->beforePrepare = function (): void {
      $fresh = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($this->coordinator->id());
      $fresh->removeRole('delivery_reviewer')->save();
      // Deliberately retain the current request's earlier account snapshot.
      $this->assertTrue($this->container->get('current_user')->hasPermission(InvitationDelivery::PERMISSION));
      $this->assertTrue($this->container->get('agency_host.identity')->coordinatorEligible($fresh));
    };
    $result = $delivery->dispatch($request, $captured['delivery_id'], $preview['delivery']['fingerprint'], 'permission-approval');
    $this->assertSame('refused', $result['outcome']);
    $this->assertSame('not_found', $result['reason']);
    $this->assertCount(0, $mail->messages);
    $this->assertSame('captured', $this->container->get('agency_host.delivery_ledger')->read($captured['delivery_id'])['state']);
    $this->assertSame($before, $this->container->get('agency_staff.reads')->queue());
  }

  /**
   * An external role permission change cannot survive cached configuration.
   */
  public function testInvitationDeliveryFreshRoleConfigurationBeforeHandoff(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'permission-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'permission-issue', 1);
    $preview = $delivery->preview($request, $captured['delivery_id']);
    $before = $this->container->get('agency_staff.reads')->queue();
    $mail->beforePrepare = function (): void {
      $storage = $this->container->get('config.storage');
      $role = $storage->read('user.role.delivery_reviewer');
      $role['permissions'] = array_values(array_diff($role['permissions'], [InvitationDelivery::PERMISSION]));
      // Model a different process: persist config without clearing this request.
      $storage->write('user.role.delivery_reviewer', $role);
      $this->assertTrue($this->container->get('current_user')->hasPermission(InvitationDelivery::PERMISSION));
      $this->assertTrue($this->container->get('agency_host.identity')->coordinatorEligible($this->coordinator));
    };
    $result = $delivery->dispatch($request, $captured['delivery_id'], $preview['delivery']['fingerprint'], 'permission-approval');
    $this->assertSame('refused', $result['outcome']);
    $this->assertSame('not_found', $result['reason']);
    $this->assertCount(0, $mail->messages);
    $this->assertSame('captured', $this->container->get('agency_host.delivery_ledger')->read($captured['delivery_id'])['state']);
    $this->assertSame($before, $this->container->get('agency_staff.reads')->queue());
  }

  /**
   * The qualified existing SMTP plugin produces exactly the approved envelope.
   */
  public function testInvitationDeliveryActualSmtpEnvelopeWithoutNetwork(): void {
    $this->deliveryFixture();
    $this->enableModules(['smtp']);
    $this->installConfig(['smtp']);
    $routing = [
      'smtp_on' => TRUE,
      'smtp_host' => 'smtp.example.test',
      'smtp_hostbackup' => '',
      'smtp_port' => '587',
      'smtp_protocol' => 'tls',
      'smtp_autotls' => FALSE,
      'smtp_timeout' => 10,
      'smtp_from' => 'agency@example.test',
      'smtp_fromname' => 'Synthetic agency',
      'smtp_client_hostname' => '',
      'smtp_client_helo' => '',
      'smtp_allowhtml' => FALSE,
      'smtp_reroute_address' => '',
      'smtp_debugging' => FALSE,
      'smtp_keepalive' => FALSE,
    ];
    $this->config('smtp.settings')->setData($routing + ['smtp_username' => '', 'smtp_password' => ''])->save();
    $this->config('system.mail')->set('interface.agency_host', 'SMTPMailSystem')->save();
    $settings = Settings::get('agency_host_invitation_delivery');
    $settings['mode'] = 'smtp';
    $settings['deployment_gate'] = TRUE;
    $settings['smtp_qualification'] = DeliveryConfiguration::fingerprint($routing + [
      'smtp_queue' => NULL,
      'handoff_class' => PrivateSmtp::class,
    ]);
    $settings['sender'] = [
      'email' => 'agency@example.test',
      'name' => 'Synthetic agency',
      'reply_to' => 'support@example.test',
    ];
    new Settings(array_replace(Settings::getAll(), ['agency_host_invitation_delivery' => $settings]));
    $real = $this->container->get('plugin.manager.mail');
    $this->assertSame(PrivateSmtp::class, get_class((new SmtpHandoffFactory($this->container))->create()));
    $smtp = CapturedSmtp::create($this->container, [], 'SMTPMailSystem', []);
    $manager = $this->createMock(MailManagerInterface::class);
    $manager->method('mail')->willReturnCallback($real->mail(...));
    $manager->method('getInstance')->willReturnCallback($real->getInstance(...));
    $manager->expects($this->never())->method('createInstance');
    $factory = $this->createMock(SmtpHandoffFactory::class);
    $factory->method('create')->willReturn($smtp);
    $adapter = new InvitationMail($manager, $this->container->get('config.factory'), new DeliveryConfiguration(), $factory);
    $envelope = $adapter->prepare([
      'recipient' => 'person-one@example.test',
      'subject' => 'Invitation to explore working together',
      'body' => 'Sign in to view your invitation: https://participant.example.test/en/invite/synthetic',
    ]);
    $this->assertCount(0, $smtp->captured, 'Preview uses format only.');
    $this->assertSame('accepted', $adapter->send($envelope));
    $actual = $smtp->captured[0];
    $this->assertSame($envelope['from'], $actual['from']);
    $this->assertSame($envelope['from_name'], $actual['name']);
    $this->assertSame($envelope['from'], $actual['sender']);
    $this->assertSame([['person-one@example.test', '']], $actual['to']);
    $this->assertSame(['support@example.test' => ['support@example.test', '']], $actual['reply']);
    $this->assertSame([], $actual['cc']);
    $this->assertSame([], $actual['bcc']);
    $this->assertSame([], $actual['attachments']);
    $this->assertSame($envelope['subject'], $actual['subject']);
    $this->assertSame($envelope['body'], $actual['body']);
    $this->assertSame('smtp.example.test;', $actual['host']);
    $this->assertSame('tls', $actual['secure']);
    $this->assertSame([], $actual['options'], 'Default PHPMailer certificate verification is retained.');
    // A provider can echo a bearer in ErrorInfo even when debugging is off.
    $logger = new class() extends AbstractLogger {
      /**
       * Observed logger context, including provider-controlled text.
       */
      public array $entries = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->entries[] = [$message, $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
    $smtp->failureMailer = new class() extends PHPMailer {
      /**
       * Number of attempted handoffs.
       */
      public int $attempts = 0;

      /**
       * Number of transport closes.
       */
      public int $closes = 0;

      /**
       * {@inheritdoc}
       */
      public function send() {
        $this->attempts++;
        $this->ErrorInfo = 'synthetic-bearer-must-not-enter-logs';
        throw new \RuntimeException($this->ErrorInfo);
      }

      /**
       * {@inheritdoc}
       */
      public function smtpClose() {
        $this->closes++;
      }

    };
    $this->assertSame('unknown', $adapter->send($envelope));
    $this->assertSame($envelope['body'], $smtp->failureMailer->Body);
    $this->assertSame(1, $smtp->failureMailer->attempts);
    $this->assertStringNotContainsString('synthetic-bearer-must-not-enter-logs', json_encode($logger->entries));
    $this->assertSame(1, $smtp->failureMailer->closes);
    $this->config('smtp.settings')->set('smtp_hostbackup', 'backup.example.test')->save();
    try {
      $adapter->send($envelope);
      $this->fail('A changed backup route must never dispatch.');
    }
    catch (Refusal $error) {
      $this->assertSame('delivery_transport_unavailable', $error->reason);
    }
    $this->assertCount(1, $smtp->captured);
  }

  /**
   * Missing or malformed support produces recovery without private content.
   */
  public function testInvitationDeliveryMissingSupportRecovery(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'support-prepare');
    $captured = $delivery->capture($request, $prepared['receipt']['relationship_id'], 'issue', 'support-issue', 1);
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    foreach ([NULL, ['kind' => 'url']] as $support) {
      $config = $this->config('agency.settings');
      ($support === NULL ? $config->clear('policy.fallback_contact_route') : $config->set('policy.fallback_contact_route', $support))->save();
      $form = $this->container->get('form_builder')->getForm(InvitationDeliveryForm::class, $captured['delivery_id']);
      $this->assertArrayNotHasKey('recipient', $form);
      $this->assertArrayNotHasKey('body', $form);
      $this->assertArrayNotHasKey('approve', $form);
      $this->assertSame('status', $form['outcome']['#attributes']['role']);
      $this->assertSame('true', $form['outcome']['#attributes']['data-agency-focus']);
      $html = (string) $this->container->get('renderer')->renderRoot($form);
      $this->assertStringContainsString('Return to conversations', $html);
      $this->assertStringContainsString('support information could not be verified', $html);
      $this->assertStringNotContainsString($captured['invitation']['token'], $html);
    }
    $this->assertCount(0, $mail->messages);
  }

  /**
   * Known safe support routes are actionable; unsafe schemes remain plain text.
   */
  public function testInvitationDeliveryTypedSupportLinks(): void {
    [, $request] = $this->deliveryFixture();
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    foreach ([
      ['url', 'https://example.test/help', 'https://example.test/help'],
      ['email', 'support+review@example.test', 'mailto:support%2Breview%40example.test'],
      ['phone', '+1 (202) 555-0100', 'tel:+12025550100'],
      ['url', 'javascript:alert(1)', NULL],
      ['chat', '@synthetic', NULL],
    ] as [$kind, $value, $href]) {
      $this->config('agency.settings')->set('policy.fallback_contact_route', ['kind' => $kind, 'value' => $value])->save();
      $form = $this->container->get('form_builder')->getForm(InvitationDeliveryForm::class, str_repeat('a', 64));
      $html = (string) $this->container->get('renderer')->renderRoot($form['support']);
      if ($href !== NULL) {
        $this->assertStringContainsString('href="' . $href . '"', $html);
      }
      else {
        $this->assertStringNotContainsString('<a ', $html);
      }
    }
  }

  /**
   * Real Form API rejects missing/invalid CSRF and implicit approval controls.
   */
  public function testInvitationDeliveryProtectedApprovalForm(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'form-delivery-prepare');
    $captured = $delivery->capture($request, $prepared['receipt']['relationship_id'], 'issue', 'form-delivery-issue', 1);
    $id = $captured['delivery_id'];
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    $builder = $this->container->get('form_builder');
    $class = InvitationDeliveryForm::class;
    $initial = $builder->getForm($class, $id);
    $this->assertFalse($initial['approve']['#default_value']);
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'preview_fingerprint' => $initial['preview_fingerprint']['#value'],
      'approval_key' => $initial['approval_key']['#value'],
      'approve' => '1',
      'authorize_delivery' => (string) $initial['actions']['authorize']['#value'],
    ];
    $valid_token = $this->container->get('csrf_token')->get($initial['#token']);
    $before = $this->container->get('agency_staff.reads')->queue();
    foreach (['missing', 'invalid', 'no-button', 'unchecked'] as $case) {
      $input = $post;
      if ($case !== 'missing') {
        $input['form_token'] = $case === 'invalid' ? 'invalid-csrf' : $valid_token;
      }
      if ($case === 'no-button') {
        unset($input['authorize_delivery']);
      }
      if ($case === 'unchecked') {
        unset($input['approve']);
      }
      $request->setMethod('POST');
      $request->request->replace($input);
      $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
      $state->addBuildInfo('args', [$id]);
      $form = $builder->buildForm($class, $state);
      $this->assertNotEmpty($state->getErrors(), $case);
      $this->assertFalse($state->isCached());
      $this->assertCount(0, $mail->messages, $case);
      $this->assertSame('captured', $this->container->get('agency_host.delivery_ledger')->read($id)['state']);
      $this->assertSame($before, $this->container->get('agency_staff.reads')->queue());
      $state->clearErrors();
    }
    $request->request->replace($post + ['form_token' => $valid_token]);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id]);
    $form = $builder->buildForm($class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('test_captured', $state->get('delivery_result')['outcome']);
    $this->assertCount(1, $mail->messages);
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringContainsString('nothing sent', $html);
    $this->assertStringNotContainsString($captured['invitation']['token'], $html);
    $this->assertNull($builder->getCache($form['#build_id'], new FormState()));
  }

  /**
   * The installed public suppression policy denies consent and outage states.
   */
  public function testInvitationDeliveryUsesAuthoritativeSuppression(): void {
    $this->enableModules(['postmark_webhooks']);
    $this->installConfig(['postmark_webhooks']);
    $this->installSchema('postmark_webhooks', ['postmark_suppression']);
    $policy = new DeliverySuppression($this->container->get('postmark_webhooks.suppression_policy'));
    $this->assertTrue($policy->permits('person-one@example.test'));
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'SubscriptionChange',
      'suppress_sending' => TRUE,
      'suppression_reason' => 'ManualSuppression',
      'recipient' => 'person-one@example.test',
      'created' => $this->now,
      'event_key' => 'synthetic-consent-evidence',
    ]);
    $this->assertFalse($policy->permits('person-one@example.test'));
    $this->assertTrue($policy->permits('person-two@example.test'));
    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $this->assertFalse($policy->permits('person-two@example.test'));
    $this->assertFalse((new DeliverySuppression())->permits('person-two@example.test'));
  }

  /**
   * Fresh verified contact is explicit; legacy stored contact is never inferred.
   */
  public function testInvitationDeliveryReusedPrincipalAndReassignment(): void {
    [$delivery, $request, $mail] = $this->deliveryFixture();
    $old = $this->container->get('agency_host.identity')->withIntendedSubject('person-one', $this->input()['contact'],
      fn(): array => $this->container->get('agency_staff.commands')->startDiscovery($this->input(), 'old-contact-preparation'));
    $this->assertSame('committed', $old['outcome']);
    $this->people['person-one']['email'] = 'changed-person@example.test';
    $selection = $this->container->get('agency_host.console_selection')->find('changed-person@example.test')['selection'];
    $input = $this->input();
    $input['contact']['value'] = 'changed-person@example.test';
    $prepared = $delivery->prepare($request, $selection, $input, 'fresh-contact-preparation');
    $id = $prepared['receipt']['relationship_id'];
    $captured = $delivery->capture($request, $id, 'issue', 'fresh-contact-issue', 1);
    $this->assertSame('changed-person@example.test', $delivery->preview($request, $captured['delivery_id'])['delivery']['recipient']);
    $replacement = User::create([
      'name' => 'delivery-replacement',
      'status' => 1,
      'roles' => $this->coordinator->getRoles(TRUE),
    ]);
    $replacement->save();
    $moved = $this->container->get('agency_staff.commands')->setNextAction($id, [
      'owner' => [
        'uid' => (int) $replacement->id(),
        'contact' => ['kind' => 'email', 'value' => 'other-owner@example.test'],
      ],
    ], 'delivery-reassign', 2);
    $this->assertSame('committed', $moved['outcome']);
    $this->assertSame('not_found', $delivery->prepare($request, $selection, $input, 'fresh-contact-preparation')['reason']);
    $this->assertSame('not_found', $delivery->preview($request, $captured['delivery_id'])['reason']);
    $this->assertCount(0, $mail->messages);
  }

  /**
   * A proven generation conflict is retained separately from uncertain issuance.
   */
  public function testInvitationDeliveryRetainsDefinitiveIssuanceRefusal(): void {
    [$delivery, $request, $mail, $selection] = $this->deliveryFixture();
    $prepared = $delivery->prepare($request, $selection, $this->input(), 'refusal-prepare');
    $id = $prepared['receipt']['relationship_id'];
    $refused = $delivery->capture($request, $id, 'issue', 'refusal-issue', 999);
    $this->assertSame('conflict', $refused['outcome']);
    $this->assertSame('issuance_refused', $delivery->preview($request, $refused['delivery_id'])['outcome']);
    $this->assertSame('conflict', $delivery->capture($request, $id, 'issue', 'refusal-issue', 999)['outcome']);
    $this->assertSame('idempotency_conflict', $delivery->capture($request, $id, 'issue', 'refusal-issue', 1)['reason']);
    $this->assertCount(0, $mail->messages);
  }

  /**
   * Supplies isolated capture I/O; no SMTP connection or live secret exists.
   */
  private function deliveryFixture(): array {
    $this->installEntitySchema('oauth2_token');
    $role = Role::create(['id' => 'delivery_reviewer', 'label' => 'Synthetic delivery reviewer']);
    $role->grantPermission('approve agency discovery invitation delivery')->save();
    $this->coordinator->addRole('delivery_reviewer')->save();
    $this->container->get('current_user')->setAccount($this->coordinator);
    new Settings(array_replace(Settings::getAll(), [
      'agency_host_invitation_origin' => 'https://participant.example.test',
      'agency_host_invitation_delivery' => [
        'mode' => 'capture',
        'key_id' => 'synthetic',
        'keys' => ['synthetic' => base64_encode(str_repeat('x', 32))],
        'payload_retention_seconds' => 3600,
      ],
    ]));
    $mail = new class() implements InvitationMailInterface {
      /**
       * Synthetic transport calls.
       */
      public array $messages = [];
      /**
       * Controlled synthetic transport outcome.
       */
      public string $outcome = 'captured';
      /**
       * A one-shot barrier before the format callback returns.
       */
      public ?\Closure $beforePrepare = NULL;

      /**
       * {@inheritdoc}
       */
      public function prepare(array $content): array {
        if ($this->beforePrepare !== NULL) {
          $callback = $this->beforePrepare;
          $this->beforePrepare = NULL;
          $callback();
        }
        return $content + ['plugin' => 'synthetic-capture', 'send' => TRUE];
      }

      /**
       * {@inheritdoc}
       */
      public function send(array $envelope): string {
        $this->messages[] = $envelope;
        return $this->outcome;
      }

    };
    $this->container->set('agency_host.invitation_mail', $mail);
    $this->container->set('agency_host.delivery_suppression', new class() implements DeliverySuppressionInterface {

      /**
       * {@inheritdoc}
       */
      public function permits(string $recipient): bool {
        return TRUE;
      }

    });
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $selection = $this->container->get('agency_host.console_selection')->find('person-one@example.test')['selection'];
    return [$this->container->get('agency_host.invitation_delivery'), $request, $mail, $selection];
  }

  /**
   * Requestless route checks fail closed without an argument-resolution error.
   */
  public function testConsoleNamedRouteAccessWithoutCurrentRequest(): void {
    $this->container->get('router.builder')->rebuild();
    $stack = $this->container->get('request_stack');
    while ($stack->getCurrentRequest() !== NULL) {
      $stack->pop();
    }

    $result = $this->container->get('access_manager')->checkNamedRoute('agency_host.console', [], $this->coordinator, TRUE);
    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Named route checks use current cookie authority; explicit requests win.
   */
  public function testConsoleNamedRouteAccessUsesCurrentRequest(): void {
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $manager = $this->container->get('access_manager');
    $result = $manager->checkNamedRoute('agency_host.console', [], $this->coordinator, TRUE);
    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());

    $this->session($request);
    $result = $manager->checkNamedRoute('agency_host.console', [], $this->coordinator, TRUE);
    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());

    $explicit = clone $request;
    $explicit->headers->set('Authorization', 'Bearer synthetic-mixed-credential');
    $result = $this->container->get('agency_host.console_access')->access($explicit);
    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Private browser forms require same-origin metadata for an opaque origin.
   */
  public function testConsolePrivateFormOriginBoundary(): void {
    foreach ([
      ['https://example.test', NULL, 'POST', TRUE],
      [NULL, NULL, 'POST', TRUE],
      ['null', 'same-origin', 'POST', TRUE],
      ['null', NULL, 'POST', FALSE],
      ['null', 'same-site', 'POST', FALSE],
      ['null', 'cross-site', 'POST', FALSE],
      ['null', 'none', 'POST', FALSE],
      ['https://other.example.test', 'same-origin', 'POST', FALSE],
      ['', 'same-origin', 'POST', FALSE],
      ['null', 'same-origin', 'PUT', FALSE],
    ] as [$origin, $site, $method, $allowed]) {
      $request = $this->request(NULL, $method);
      $this->session($request);
      if ($origin === NULL) {
        $request->headers->remove('Origin');
      }
      else {
        $request->headers->set('Origin', $origin);
      }
      if ($site !== NULL) {
        $request->headers->set('Sec-Fetch-Site', $site);
      }
      $access = ConsoleAccess::create($this->container);
      $this->assertSame($allowed, $access->access($request)->isAllowed(), json_encode([$origin, $site, $method]));
      if ($allowed) {
        $request->headers->set('Authorization', 'Bearer synthetic-mixed-credential');
        $this->assertFalse($access->access($request)->isAllowed());
        $request->headers->remove('Authorization');
        $request->headers->set(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, 'synthetic-participant-proof');
        $this->assertFalse($access->access($request)->isAllowed());
      }
    }
  }

  /**
   * Overlapping Prepare captures keep one exact request and committed receipt.
   */
  public function testConsolePrepareCaptureRetainsCanonicalKey(): void {
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $handler = DiscoveryPrepareForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $state->setValues(['email' => $this->people['person-one']['email']]);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'find']);
    $this->submitConsole($handler, $form, $state);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'confirm']);
    $this->submitConsole($handler, $form, $state);
    $this->assertTrue($state->get('confirmed'));
    $form = $handler->buildForm([], $state);
    $deadline = $this->now + 7200;
    $state->setValues([
      'email' => $this->people['person-one']['email'],
      'display_name' => 'Example prepared person',
      'owner_contact' => 'owner@example.test',
      'summary' => 'Choose a conversation',
      'why_it_helps' => 'Understand the next step',
      'side' => 'agency',
      'expected_by' => DrupalDateTime::createFromTimestamp($deadline),
    ])->setTriggeringElement(['#name' => 'prepare']);
    // Stage the first request immediately after capture and before attempted().
    $first = [
      'key' => str_repeat('a', 48),
      'selection' => $state->get('selection'),
      'input' => [
        'display_name' => 'Example prepared person',
        'contact' => ['kind' => 'email', 'value' => $this->people['person-one']['email']],
        'owner_contact' => ['kind' => 'email', 'value' => 'owner@example.test'],
        'next_action' => [
          'summary' => 'Choose a conversation',
          'why_it_helps' => 'Understand the next step',
          'expected_by' => $deadline,
          'optional' => TRUE,
          'side' => 'agency',
        ],
      ],
    ];
    $reviews = $this->container->get('agency_host.console_reviews');
    $nonce = $reviews->capture($request, 'prepare', 'prepare', $first, [
      'display_name' => $first['input']['display_name'],
      'agency_name' => $this->container->get('agency_staff.policy')->name(),
    ]);
    // Actual rendered-button handler for the second otherwise identical request.
    $this->submitConsole($handler, $form, $state);
    $second = $state->get('intent');
    $second_result = $state->get('result');
    $this->assertSame($nonce, $second['review_nonce']);
    $before_count = count($this->container->get('agency_staff.reads')->queue()['contexts']);
    $build = $handler->buildForm([], $state);
    $outcome = array_intersect_key($build, array_flip(['result_heading', 'result']));
    $html = (string) $this->container->get('renderer')->renderRoot($outcome);
    // Resume the first request with its original exact captured command.
    $canonical = $first + ['review_nonce' => $nonce, 'command' => 'prepare'];
    $reviews->attempted($request, $nonce);
    $lookup = $this->container->get('agency_host.console_selection');
    $result = $this->container->get('agency_host.console')->intent($request, 'prepare', $canonical,
      fn(): array => $lookup->apply($first['selection'], $first['input']['contact']['value'],
        fn(): array => $this->container->get('agency_staff.commands')->startDiscovery($first['input'], $first['key'])));
    $result = $reviews->result($request, $nonce, $result);
    $after_count = count($this->container->get('agency_staff.reads')->queue()['contexts']);
    $this->assertSame('committed', $result['outcome']);
    $this->assertSame('committed', $second_result['outcome']);
    $this->assertSame($second_result['receipt'], $result['receipt']);
    $this->assertSame(1, $before_count);
    $this->assertSame(1, $after_count);
    $this->assertStringNotContainsString("This request wasn&#039;t saved", $html);
    $this->assertSame($first['key'], $second['key'], 'A reused review nonce must keep its canonical command key.');
  }

  /**
   * Invitation review is labelled as a preview and never issues on GET.
   */
  public function testConsoleInvitationPreviewAndActionAge(): void {
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'console-preview-start',
    ]);
    $this->session($request);
    $start = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    $request->request->replace([]);
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $timestamp = $baseline[0]['last_agency_action_at'];
    $controller = DiscoveryConsoleController::create($this->container);
    $copy = $this->container->get('agency_host.console_copy');
    $dates = $this->container->get('date.formatter');
    foreach ([0 => '0 seconds', 59 => '59 seconds', 60 => '1 minute', 3600 => '1 hour', 86400 => '1 day'] as $elapsed => $expected) {
      $this->now = $timestamp + $elapsed;
      $build = $controller->queue($request);
      $this->assertSame($copy->text('action.previewInvitation'), $build['rows'][0]['actions']['issue']['#title']);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      $this->assertStringContainsString($expected . ' ago', $html);
      $this->assertStringContainsString($dates->format($timestamp, 'custom', 'Y-m-d H:i T'), $html);
    }
    // A future observation must not be invented as a negative elapsed period.
    $this->now = $timestamp - 1;
    $build = $controller->queue($request);
    $this->assertStringNotContainsString('ago', $build['rows'][0]['fields']['colLastAction']['text']['#plain_text']);
    $this->now = $timestamp;
    $builder = $this->container->get('form_builder');
    for ($load = 0; $load < 2; $load++) {
      $form = $builder->getForm(DiscoveryCommandForm::class, $id, 'issue');
      $this->assertSame($copy->text('action.issueInvitation'), (string) $form['actions']['submit']['#value']);
      $html = (string) $this->container->get('renderer')->renderRoot($form);
      $this->assertStringContainsString('This is a preview of the invitation', $html);
      $this->assertStringContainsString('No invitation exists yet, and nothing is sent.', $html);
      $this->assertStringContainsString($baseline[0]['display_name'], $html);
      $this->assertStringContainsString($baseline[0]['next_action']['summary'], $html);
      $this->assertStringContainsString($copy->text('prepare.languageNote'), $html);
      $this->assertStringNotContainsString('/invitation/', $html);
      $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
    }
    // A retained preview must not claim absence after another request issued.
    $issued = $this->container->get('agency_staff.invitations')->issue($id, 'other-console-issue', $baseline[0]['version']);
    $this->assertSame('committed', $issued['outcome']);
    $request->query->set('review', $form['review_nonce']['#value']);
    $stale = $builder->getForm(DiscoveryCommandForm::class, $id, 'issue');
    $html = (string) $this->container->get('renderer')->renderRoot($stale);
    $this->assertStringNotContainsString('No invitation exists yet', $html);
    $this->assertArrayNotHasKey('invitation_link', $stale);
  }

  /**
   * Form rendering separates contact lookup from explicit preparation.
   */
  public function testConsolePrepareRendersNoSubjectInput(): void {
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $form = $this->container->get('form_builder')->getForm(DiscoveryPrepareForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringContainsString('Find sign-in', $html);
    $this->assertStringContainsString('Find and confirm a sign-in', $html);
    $this->assertStringNotContainsString('name="intended_subject"', $html);
    $this->assertStringNotContainsString('name="subject"', $html);
    $this->assertStringNotContainsString('name="language"', $html);
    $this->assertSame([], $this->container->get('agency_staff.reads')->queue()['contexts']);
    // A newly introduced data collection cannot silently bypass the inventory.
    $form['unexpected'] = [
      '#input' => TRUE,
      '#type' => 'textfield',
      '#name' => 'undocumented_personal_detail',
    ];
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Undeclared console input purpose: undocumented_personal_detail.');
    ConsoleFieldInventory::check($form, new FormState());
  }

  /**
   * Review does not mutate and confirmation uses the visibly frozen draft.
   */
  public function testConsoleReviewFreezesDisplayedDraftAndRetainsReceipt(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $handler = DiscoveryCommandForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $state->setValues([
      'summary' => 'Review this exact draft',
      'why_it_helps' => 'Confirm the next conversation',
      'expected_by' => DrupalDateTime::createFromTimestamp(time() + 7200),
      'side' => 'agency',
    ]);
    $state->setTriggeringElement(['#name' => 'retry']);
    $this->submitConsole($handler, $form, $state);
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
    $state->setUserInput(['summary' => 'Tampered after review']);
    $review = $handler->buildForm([], $state, $id, 'setNextAction');
    $this->assertTrue($review['summary']['#disabled']);
    $this->assertSame('Review this exact draft', $review['summary']['#default_value']);
    $this->assertArrayNotHasKey('apply', $review['actions']);
    $this->assertArrayNotHasKey('summary', $state->getUserInput());
    $this->assertStringNotContainsString('{name}', $review['review']['text']['#plain_text']);
    $review_display = ['current' => $review['review'], 'deadline' => $review['review_deadline'] ?? []];
    $review_html = (string) $this->container->get('renderer')->renderRoot($review_display);
    $this->assertTrue(str_contains($review_html, $this->container->get('date.formatter')->format($baseline[0]['next_action']['expected_by'], 'custom', 'Y-m-d H:i T')), 'The current obligation includes its actual deadline.');
    $state->setValue('summary', 'Tampered after review');
    $this->submitConsole($handler, $review, $state);
    $this->assertSame('committed', $state->get('result')['outcome'], json_encode($state->get('result')));
    $after = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->assertSame($version + 1, $after[0]['version']);
    $this->assertSame('Review this exact draft', $after[0]['next_action']['summary']);
    $saved_build = $handler->buildForm([], $state, $id, 'setNextAction');
    $saved_html = (string) $this->container->get('renderer')->renderRoot($saved_build);
    $this->assertTrue(str_contains($saved_html, $this->container->get('date.formatter')->format($after[0]['next_action']['expected_by'], 'custom', 'Y-m-d H:i T')), 'The saved receipt includes the committed deadline.');
    // A submitted Form API cache may be gone after a withheld response.
    $request->request->set('review_nonce', $state->get('review_nonce'));
    $restored = (new FormState())->setRequestMethod('POST');
    $handler->buildForm([], $restored, $id, 'setNextAction');
    $this->assertSame($state->get('result')['receipt'], $restored->get('result')['receipt']);
    $this->assertSame($state->get('intent')['key'], $restored->get('intent')['key']);
    $this->assertSame($after, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Read-only Find and confirmation do not require later business fields.
   */
  public function testConsoleLookupUsesEmailBeforeBusinessDetails(): void {
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $builder = $this->container->get('form_builder');
    $initial = $builder->getForm(DiscoveryPrepareForm::class);
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'email' => 'person-one@example.test',
      'find' => (string) $initial['find']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $found = $builder->buildForm(DiscoveryPrepareForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertArrayHasKey('confirm', $found['selection']);
    $this->assertFalse($state->get('confirmed'));
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
    unset($post['find']);
    $post['form_build_id'] = $found['#build_id'];
    $post['confirm'] = (string) $found['selection']['confirm']['#value'];
    $request->request->replace($post);
    $confirmed_state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $confirmed = $builder->buildForm(DiscoveryPrepareForm::class, $confirmed_state);
    $this->assertSame([], $confirmed_state->getErrors());
    $this->assertTrue($confirmed_state->get('confirmed'));
    $this->assertFalse($confirmed['actions']['prepare']['#disabled']);
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * A reused person keeps their public name in the saved Prepare response.
   */
  public function testConsolePrepareReceiptUsesSavedPersonAndDeadline(): void {
    $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $handler = DiscoveryPrepareForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $state->setValues(['email' => $this->people['person-one']['email']]);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'find']);
    $this->submitConsole($handler, $form, $state);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'confirm']);
    $this->submitConsole($handler, $form, $state);
    $form = $handler->buildForm([], $state);
    $deadline = $this->now + 7200;
    $state->setValues([
      'email' => $this->people['person-one']['email'],
      'display_name' => 'This must not rename the existing person',
      'owner_contact' => 'owner@example.test',
      'summary' => 'A second optional conversation',
      'why_it_helps' => 'Agree the next conversation',
      'side' => 'agency',
      'expected_by' => DrupalDateTime::createFromTimestamp($deadline),
    ])->setTriggeringElement(['#name' => 'prepare']);
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('committed', $state->get('result')['outcome']);
    $rows = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->assertCount(2, $rows);
    $saved = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== $baseline[0]['id']))[0];
    $this->assertSame($baseline[0]['display_name'], $saved['display_name']);
    $this->assertSame($deadline, $saved['next_action']['expected_by']);
    $build = $handler->buildForm([], $state);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertFalse(str_contains($html, 'This must not rename the existing person'), 'The receipt does not claim an ignored name was saved.');
    $this->assertTrue(str_contains($html, $saved['display_name']), 'The receipt names the authorized public person.');
    $this->assertTrue(str_contains($html, $this->container->get('date.formatter')->format($deadline, 'custom', 'Y-m-d H:i T')), 'The receipt includes the committed deadline.');
    $replacement = User::create([
      'name' => 'prepared-conversation-replacement',
      'status' => 1,
      'roles' => $this->coordinator->getRoles(TRUE),
    ]);
    $replacement->save();
    $moved = $this->container->get('agency_staff.commands')->setNextAction($saved['id'], [
      'owner' => [
        'uid' => (int) $replacement->id(),
        'contact' => ['kind' => 'email', 'value' => 'replacement@example.test'],
      ],
    ], 'move-prepared-conversation', $saved['version']);
    $this->assertSame('committed', $moved['outcome']);
    $generic = $handler->buildForm([], $state);
    $generic_html = (string) $this->container->get('renderer')->renderRoot($generic);
    $this->assertTrue(str_contains($generic_html, 'This request was saved'));
    $this->assertFalse(str_contains($generic_html, $saved['display_name']), 'A missing authorized row uses the generic saved receipt.');
    $this->assertFalse(str_contains($generic_html, 'This must not rename the existing person'));
  }

  /**
   * Every supported action renders through the enforced field inventory.
   */
  public function testConsoleAllActionFormsAndFreshAssignment(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $render = function (string $relationship, string $command): array {
      $request = $this->request(NULL, 'GET');
      $this->session($request);
      $form = $this->container->get('form_builder')->getForm(DiscoveryCommandForm::class, $relationship, $command);
      $html = (string) $this->container->get('renderer')->renderRoot($form);
      $this->assertStringNotContainsString('{name}', $html, $command);
      $this->assertStringNotContainsString('{agencyName}', $html, $command);
      $this->assertStringContainsString('type="submit"', $html, $command);
      return [$form, $html];
    };
    foreach (['setNextAction', 'pauseRelationship', 'close', 'moveForward', 'reassign'] as $command) {
      $render($id, $command);
    }
    $input = $this->input();
    $input['contact']['value'] = $this->people['person-two']['email'];
    $request = $this->request(NULL, 'POST', [
      'input' => $input,
      'intended_subject' => 'person-two',
      'key' => 'console-other-start',
    ]);
    $this->session($request);
    $start = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
    $this->assertSame('committed', $start['outcome']);
    $other = $start['receipt']['relationship_id'];
    $render($other, 'issue');
    $this->request(NULL, 'POST');
    $issued = $this->container->get('agency_staff.invitations')->issue($other, 'console-other-issue', 1);
    $this->assertSame('committed', $issued['outcome']);
    $render($other, 'resend');
    $render($other, 'revokeInvitation');
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $handler = DiscoveryCommandForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $state->setValues([
      'summary' => 'Private retained draft',
      'why_it_helps' => 'Current owner only',
      'expected_by' => DrupalDateTime::createFromTimestamp($this->now + 7200),
      'side' => 'agency',
    ])->setTriggeringElement(['#name' => 'retry']);
    $this->submitConsole($handler, $form, $state);
    $replacement = User::create([
      'name' => 'console-new-owner',
      'status' => 1,
      'roles' => [
        'employee',
        'coordinator',
      ],
    ]);
    $replacement->save();
    $changed = $this->container->get('agency_staff.commands')->setNextAction($id, [
      'owner' => [
        'uid' => (int) $replacement->id(),
        'contact' => [
          'kind' => 'email',
          'value' => 'new@example.test',
        ],
      ],
    ], 'console-change-owner', $version);
    $this->assertSame('committed', $changed['outcome']);
    $denied = $handler->buildForm([], $state, $id, 'setNextAction');
    $html = (string) $this->container->get('renderer')->renderRoot($denied);
    $this->assertStringNotContainsString('Private retained draft', $html);
    $this->assertArrayNotHasKey('summary', $denied);
    $this->assertArrayNotHasKey('actions', $denied);
    $handler->submitForm($denied, $state);
    $this->assertSame('not_found', $state->get('result')['reason']);
    $this->container->get('current_user')->setAccount($replacement);
    $row = $this->container->get('agency_staff.reads')->queue()['contexts'][0];
    $this->assertSame($changed['version'], $row['version']);
    $this->assertNotSame('Private retained draft', $row['next_action']['summary']);
  }

  /**
   * New hidden collection and command keys cannot bypass purpose review.
   */
  public function testConsolePurposeRefusesHiddenAndCommandKeys(): void {
    foreach (['hidden', 'textfield', 'submit'] as $type) {
      try {
        ConsoleFieldInventory::check([
          'new' => ['#input' => TRUE, '#type' => $type, '#name' => 'unreviewed_detail'],
        ], new FormState());
        $this->fail('An undeclared control was accepted.');
      }
      catch (\LogicException $error) {
        $this->assertStringContainsString('unreviewed_detail', $error->getMessage());
      }
    }
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Undeclared console command input: unreviewed_detail.');
    ConsoleFieldInventory::command('close', [
      'unreviewed_detail' => 'Must not be collected',
    ]);
  }

  /**
   * Preparation retains the same request across recording and selection expiry.
   */
  public function testConsolePrepareReconfirmsWithoutChangingFrozenRequest(): void {
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $handler = DiscoveryPrepareForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $state->setValues(['email' => $this->people['person-one']['email']]);
    $form = $handler->buildForm([], $state);
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->execute();
    $state->setTriggeringElement(['#name' => 'find']);
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('person-one', $state->get('selection')['subject']);
    $this->assertSame([], $this->container->get('agency_staff.reads')->queue()['contexts']);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'confirm']);
    $this->submitConsole($handler, $form, $state);
    $this->assertTrue($state->get('confirmed'));
    $form = $handler->buildForm([], $state);
    $state->setValues([
      'email' => $this->people['person-one']['email'],
      'display_name' => 'Original displayed person',
      'owner_contact' => 'owner@example.test',
      'summary' => 'Original first obligation',
      'why_it_helps' => 'Explain the next conversation',
      'side' => 'agency',
      'expected_by' => DrupalDateTime::createFromTimestamp($this->now + 7200),
    ])->setTriggeringElement(['#name' => 'prepare']);
    $this->recordingFailure = TRUE;
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('recording_unavailable', $state->get('result')['reason']);
    $key = $state->get('intent')['key'];
    $form = $handler->buildForm([], $state);
    $this->assertTrue($form['display_name']['#disabled']);
    $this->assertSame('Original first obligation', $form['summary']['#default_value']);
    $this->assertSame([], $this->container->get('agency_staff.reads')->queue()['contexts']);
    $this->recordingFailure = FALSE;
    $this->now += 301;
    $state->setValue('display_name', 'Must not silently replace the original')->setValue('email', 'other@example.test');
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('selection_lapsed', $state->get('result')['reason']);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'find']);
    $this->submitConsole($handler, $form, $state);
    $form = $handler->buildForm([], $state);
    $this->assertSame('person-one', $state->get('selection')['subject']);
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'confirm']);
    $this->submitConsole($handler, $form, $state);
    $this->assertTrue($state->get('confirmed'));
    $form = $handler->buildForm([], $state);
    $state->setTriggeringElement(['#name' => 'prepare']);
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('committed', $state->get('result')['outcome'], json_encode($state->get('result')));
    $this->assertSame($key, $state->get('intent')['key']);
    $rows = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->assertCount(1, $rows);
    $this->assertSame('Original displayed person', $rows[0]['display_name']);
    $this->assertSame('Original first obligation', $rows[0]['next_action']['summary']);
    $this->assertSame($accounts, $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * A stale confirmation requires a fresh explicit review before a new request.
   */
  public function testConsoleConflictReviewUsesFreshProjection(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $handler = DiscoveryCommandForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $state->setValues([
      'summary' => 'Original reviewed draft',
      'why_it_helps' => 'Explain what follows',
      'expected_by' => DrupalDateTime::createFromTimestamp($this->now + 7200),
      'side' => 'agency',
    ])->setTriggeringElement(['#name' => 'retry']);
    $this->submitConsole($handler, $form, $state);
    $other = $this->container->get('agency_staff.commands')->setNextAction($id, [
      'summary' => 'Changed by another request',
      'why_it_helps' => 'Make a new promise visible',
      'expected_by' => $this->now + 6000,
      'optional' => TRUE,
      'side' => 'agency',
    ], 'other-console-request', $version);
    $this->assertSame('committed', $other['outcome']);
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('conflict', $state->get('result')['outcome']);
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $this->assertArrayNotHasKey('apply', $form['actions']);
    $state->setTriggeringElement(['#name' => 'review']);
    $this->submitConsole($handler, $form, $state);
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $this->assertStringContainsString('Changed by another request', $form['review']['text']['#plain_text']);
    $this->assertSame($other['version'], $state->get('review')['version']);
    $this->assertArrayHasKey('apply', $form['actions']);
    $this->assertFalse($form['summary']['#disabled']);
    $this->assertSame('Original reviewed draft', $form['summary']['#default_value']);
    $state->setValue('summary', 'Explicitly revised after conflict')->setTriggeringElement([
      '#name' => 'apply',
    ]);
    $this->submitConsole($handler, $form, $state);
    $this->assertTrue($state->get('await_confirmation'));
    $this->assertSame($other['version'], $this->container->get('agency_staff.reads')->queue()['contexts'][0]['version']);
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $this->assertSame('Explicitly revised after conflict', $form['summary']['#default_value']);
    $state->setTriggeringElement(['#name' => 'retry']);
    $this->submitConsole($handler, $form, $state);
    $this->assertSame('committed', $state->get('result')['outcome'], json_encode($state->get('result')));
    $row = $this->container->get('agency_staff.reads')->queue()['contexts'][0];
    $this->assertSame($other['version'] + 1, $row['version']);
    $this->assertSame('Explicitly revised after conflict', $row['next_action']['summary']);
  }

  /**
   * Unknown writes remain guarded across reads; expiry release never replays.
   */
  public function testConsoleUnconfirmedGuardAndExplicitExpiry(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $app = $this->container->get('agency_host.console');
    $store = $this->container->get('agency_host.console_reviews');
    $baseline = $app->queue($request)['contexts'];
    $intent = ['input' => [], 'version' => $version, 'key' => 'unknown-before-domain'];
    $nonce = $store->capture($request, $id, 'resumeRelationship', $intent, $baseline[0]);
    $intent += ['review_nonce' => $nonce, 'command' => 'resumeRelationship'];
    $store->attempted($request, $nonce);
    $unknown = $app->intent($request, $id, $intent, static function (): array {
      throw new \RuntimeException('Synthetic response boundary unavailable.');
    });
    $store->result($request, $nonce, $unknown);
    $this->assertSame('uncertain', $unknown['outcome']);
    $this->assertTrue($unknown['unconfirmed']);
    $this->assertSame($baseline, $app->queue($request)['contexts']);
    $called = FALSE;
    $different = $intent;
    $different['key'] = 'do-not-apply-new-intent';
    $blocked = $app->intent($request, $id, $different, static function () use (&$called): array {
      $called = TRUE;
      return ['outcome' => 'committed'];
    });
    $this->assertSame('prior_unconfirmed', $blocked['reason']);
    $this->assertFalse($called);
    try {
      $store->acknowledge($request, $nonce, $id, 'resumeRelationship');
      $this->fail('Unknown result was acknowledged.');
    }
    catch (Refusal $error) {
      $this->assertSame('prior_unconfirmed', $error->reason);
    }
    try {
      $store->releaseExpired($request, $nonce, $id, 'resumeRelationship');
      $this->fail('A live request was released as expired.');
    }
    catch (Refusal $error) {
      $this->assertSame('not_found', $error->reason);
    }
    $this->now += 1801;
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    $request->query->set('review', $nonce);
    $expired = $this->container->get('form_builder')->getForm(DiscoveryCommandForm::class, $id, 'resumeRelationship');
    $expired_html = (string) $this->container->get('renderer')->renderRoot($expired);
    $this->assertStringContainsString($baseline[0]['display_name'], $expired_html);
    $store->releaseExpired($request, $nonce, $id, 'resumeRelationship');
    $this->assertNull($app->pending($request, $id));
    $this->assertSame($baseline, $app->queue($request)['contexts']);
    try {
      $store->read($request, $nonce, $id, 'resumeRelationship');
      $this->fail('Released stale review became usable again.');
    }
    catch (Refusal $error) {
      $this->assertSame('not_found', $error->reason);
    }
    $prepare_nonce = $store->capture($request, 'prepare', 'prepare', [
      'key' => 'expired-prepare-copy',
      'input' => ['display_name' => 'Expired private identity name'],
    ], ['display_name' => 'Expired private identity name']);
    $this->now += 1801;
    $request->query->set('review', $prepare_nonce);
    $expired_prepare = $this->container->get('form_builder')->getForm(DiscoveryPrepareForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($expired_prepare);
    $this->assertStringNotContainsString('Expired private identity name', $html);
    $this->assertStringContainsString('Check what happened before you make another change.', $html);
    $this->assertArrayHasKey('expired_continue', $expired_prepare['actions']);
  }

  /**
   * A valid compound date reaches review through actual Form API validation.
   */
  public function testConsoleDateReviewUsesCompoundWidget(): void {
    [$id] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $request->setMethod('POST');
    $request->request->replace([
      'form_id' => $form['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'summary' => 'Review this actual date',
      'why_it_helps' => 'Do not save before confirmation',
      'expected_by' => ['date' => gmdate('Y-m-d', $this->now + 172800), 'time' => '12:00:00'],
      'side' => 'agency',
      'retry' => (string) $form['actions']['submit']['#value'],
    ]);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'setNextAction']);
    $built = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertTrue($state->get('await_confirmation'));
    $this->assertIsInt($state->get('intent')['input']['expected_by']);
    $this->assertArrayHasKey('review_nonce', $built);
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Failed metadata reads refuse before commands and preserve retained holds.
   */
  public function testConsoleMetadataFailureIsNotAnEmptySession(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $this->assertNull($this->container->get('agency_host.console_metadata')->get('synthetic-absent-control'));
    $reviews = $this->container->get('agency_host.console_reviews');
    $app = $this->container->get('agency_host.console');
    $before = $app->queue($request)['contexts'];
    $intent = ['input' => [], 'version' => $version, 'key' => 'metadata-failure'];
    $nonce = $reviews->capture($request, $id, 'resumeRelationship', $intent, $before[0]);
    $intent += ['command' => 'resumeRelationship', 'review_nonce' => $nonce];
    $unknown = $app->intent($request, $id, $intent, static fn(): array => ['outcome' => 'uncertain']);
    $this->assertTrue($unknown['unconfirmed']);
    $failed_metadata = new StrictConsoleStorage('agency_host.console', $this->container->get('serialization.phpserialize'), $this->container->get('database'), 'synthetic_missing_console_storage');
    $state = new ConsoleStateStore($failed_metadata, $this->container->get('agency_host.console_payloads'), $this->container->get('lock'), $this->container->get('current_user'), $this->container->get('datetime.time'), $this->container->get('database'), $this->container->get('session_handler.storage'));
    $failed_app = new ConsoleApplication($this->container->get('agency_host.console_access'), $this->container->get('agency_staff.reads'), $this->container->get('agency_staff.commands'), $this->container->get('agency_staff.invitations'), $this->container->get('agency_host.transport'), $state);
    $called = FALSE;
    $result = $failed_app->intent($request, $id, $intent, static function () use (&$called): array {
      $called = TRUE;
      return ['outcome' => 'committed'];
    });
    $this->assertSame('unavailable', $result['reason']);
    $this->assertTrue($result['unconfirmed']);
    $this->assertFalse($called);
    $this->assertTrue($app->pending($request, $id)['unconfirmed']);
    $this->assertSame($before, $app->queue($request)['contexts']);
    $this->assertSame($nonce, array_key_first($reviews->listing($request)));
  }

  /**
   * An overlapping refusal cannot release a retry that can still commit.
   */
  public function testConsoleOverlappingSettlementRetainsUncertainty(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $reviews = $this->container->get('agency_host.console_reviews');
    $state = $this->container->get('agency_host.console_state');
    $app = $this->container->get('agency_host.console');
    $before = $app->queue($request)['contexts'];
    $intent = [
      'input' => [
        'summary' => 'Single retained result',
        'why_it_helps' => 'Resolve overlapping responses safely',
        'optional' => TRUE,
        'side' => 'agency',
        'expected_by' => $this->now + 7200,
      ],
      'version' => $version,
      'key' => 'overlapping-console-command',
    ];
    $nonce = $reviews->capture($request, $id, 'setNextAction', $intent, $before[0]);
    $intent += ['command' => 'setNextAction', 'review_nonce' => $nonce];
    $second = NULL;
    $first = $app->intent($request, $id, $intent, function () use ($state, $request, $id, $intent, &$second): array {
      $second = $state->reserve($request, $id, $intent);
      return ['outcome' => 'refused', 'reason' => 'identity_unavailable'];
    });
    $this->assertTrue($first['unconfirmed']);
    $this->assertTrue($state->pending($request, $id)['unconfirmed']);
    $this->assertSame($before, $app->queue($request)['contexts']);
    $outer_refusal = $reviews->result($request, $nonce, ['outcome' => 'refused', 'reason' => 'not_found']);
    $this->assertTrue($outer_refusal['unconfirmed']);
    try {
      $reviews->acknowledge($request, $nonce, $id, 'setNextAction');
      $this->fail('An in-flight retry was released after another refusal.');
    }
    catch (Refusal $error) {
      $this->assertSame('prior_unconfirmed', $error->reason);
    }
    $third = $state->reserve($request, $id, $intent);
    $committed = $app->command($request, $id, 'setNextAction', $intent);
    $this->assertSame('committed', $committed['outcome']);
    $this->assertSame($committed, $state->settle($request, $id, $intent, $second['ticket'], $committed));
    $this->assertSame($committed, $state->settle($request, $id, $intent, $third['ticket'], [
      'outcome' => 'refused',
      'reason' => 'identity_unavailable',
    ]));
    $this->assertSame($committed, $reviews->result($request, $nonce, ['outcome' => 'refused', 'reason' => 'not_found']));
    $reviews->acknowledge($request, $nonce, $id, 'setNextAction');
    try {
      $state->settle($request, $id, $intent, $second['ticket'], $committed);
      $this->fail('A late callback recreated a released request.');
    }
    catch (Refusal $error) {
      $this->assertSame('not_found', $error->reason);
    }
    $this->assertNull($state->pending($request, $id));
    $this->assertSame([], $reviews->listing($request));
    $this->assertSame($version + 1, $app->queue($request)['contexts'][0]['version']);
  }

  /**
   * Actual confirmation preserves a saved outcome after ownership moves away.
   */
  public function testConsoleReassignmentSavedResponseAfterAccessLoss(): void {
    [$id] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $replacement = User::create([
      'name' => 'independent-replacement',
      'status' => 1,
      'roles' => $this->coordinator->getRoles(TRUE),
    ]);
    $replacement->save();
    $builder = $this->container->get('form_builder');
    $app = $this->container->get('agency_host.console');
    $old_name = $app->queue($request)['contexts'][0]['display_name'];
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'reassign');
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'owner' => (string) $replacement->id(),
      'contact' => 'replacement@example.test',
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $review_state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $review_state->addBuildInfo('args', [$id, 'reassign']);
    $review = $builder->buildForm(DiscoveryCommandForm::class, $review_state);
    $this->assertSame([], $review_state->getErrors());
    $this->assertTrue($review_state->get('await_confirmation'));
    $post['review_nonce'] = $review_state->get('review_nonce');
    $post['retry'] = (string) $review['actions']['submit']['#value'];
    $request->request->replace($post);
    $confirmation = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $confirmation->addBuildInfo('args', [$id, 'reassign']);
    $response = $builder->buildForm(DiscoveryCommandForm::class, $confirmation);
    $this->assertSame([], $confirmation->getErrors());
    $this->assertSame('committed', $confirmation->get('result')['outcome']);
    $this->assertSame([], $app->queue($request)['contexts']);
    $html = (string) $this->container->get('renderer')->renderRoot($response);
    $request->setMethod('GET');
    $request->request->replace([]);
    $request->query->set('review', $review_state->get('review_nonce'));
    $recovery = $builder->getForm(DiscoveryCommandForm::class, $id, 'reassign');
    $recovery_html = (string) $this->container->get('renderer')->renderRoot($recovery);
    $held = $builder->getForm(DiscoveryHeldForm::class);
    $held_html = (string) $this->container->get('renderer')->renderRoot($held);
    foreach ([$html, $recovery_html] as $rendered) {
      $this->assertTrue(str_contains($rendered, 'This request was saved'), 'Own-session committed evidence remains visible.');
      $this->assertFalse(str_contains($rendered, $old_name), 'The old private person name stays hidden.');
      $this->assertFalse(str_contains($rendered, 'replacement@example.test'), 'The old private draft stays hidden.');
      $this->assertFalse(str_contains(html_entity_decode($rendered), "This request wasn't applied"), 'Missing assignment cannot undo a saved receipt.');
    }
    $this->assertTrue(str_contains($held_html, 'This request was saved'));
    $this->container->get('current_user')->setAccount($replacement);
    $replacement_before = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request->setMethod('POST');
    $request->query->remove('review');
    $request->request->replace($post);
    $stale_state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $stale_state->addBuildInfo('args', [$id, 'reassign']);
    $stale = $builder->buildForm(DiscoveryCommandForm::class, $stale_state);
    $stale_html = (string) $this->container->get('renderer')->renderRoot($stale);
    $this->assertFalse(str_contains($stale_html, $old_name));
    $this->assertArrayNotHasKey('submit', $stale['actions'] ?? []);
    $this->assertSame([], $app->queue($request)['contexts']);
    $this->container->get('current_user')->setAccount($replacement);
    $this->assertSame($replacement_before, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Unsubmitted reviews do not disclose drafts after external reassignment.
   */
  public function testConsoleUnsubmittedReviewAfterAccessLoss(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $replacement = User::create([
      'name' => 'unsubmitted-replacement',
      'status' => 1,
      'roles' => $this->coordinator->getRoles(TRUE),
    ]);
    $replacement->save();
    $builder = $this->container->get('form_builder');
    $app = $this->container->get('agency_host.console');
    $old_name = $app->queue($request)['contexts'][0]['display_name'];
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'reassign');
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'owner' => (string) $replacement->id(),
      'contact' => 'unsubmitted@example.test',
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'reassign']);
    $review = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertTrue($state->get('await_confirmation'));
    $moved = $this->container->get('agency_staff.commands')->setNextAction($id, [
      'owner' => [
        'uid' => (int) $replacement->id(),
        'contact' => ['kind' => 'email', 'value' => 'new-owner@example.test'],
      ],
    ], 'move-after-unsubmitted-review', $version);
    $this->assertSame('committed', $moved['outcome']);
    $this->assertSame([], $app->queue($request)['contexts']);
    $nonce = $state->get('review_nonce');
    $request->setMethod('GET');
    $request->request->replace([]);
    $request->query->set('review', $nonce);
    $recovery = $builder->getForm(DiscoveryCommandForm::class, $id, 'reassign');
    $html = html_entity_decode((string) $this->container->get('renderer')->renderRoot($recovery));
    $this->assertFalse($this->container->get('agency_host.console_reviews')->listing($request)[$nonce]['attempted']);
    foreach (['{name}', '{state}', $old_name, 'unsubmitted@example.test', 'Your entries are kept on this page'] as $private_or_false) {
      $this->assertFalse(str_contains($html, $private_or_false), 'Access loss must not disclose or promise the hidden draft.');
    }
    $this->assertTrue(str_contains($html, "This review can't be applied here because access to the conversation is no longer available."));
    $this->assertArrayHasKey('return_result', $recovery);
    $this->assertArrayHasKey('guard_scope', $recovery);
    $this->assertArrayNotHasKey('submit', $recovery['actions'] ?? []);
    $this->container->get('current_user')->setAccount($replacement);
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $this->container->get('current_user')->setAccount($this->coordinator);
    $post['review_nonce'] = $nonce;
    $post['retry'] = (string) $review['actions']['submit']['#value'];
    $request->setMethod('POST');
    $request->query->remove('review');
    $request->request->replace($post);
    $stale = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $stale->addBuildInfo('args', [$id, 'reassign']);
    $builder->buildForm(DiscoveryCommandForm::class, $stale);
    $this->container->get('current_user')->setAccount($replacement);
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Computed dates require first-commit version evidence, never just a receipt.
   */
  #[DataProvider('computedDeadlineCases')]
  public function testConsoleComputedDeadlineEvidence(string $command, bool $replay): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $commands = $this->container->get('agency_staff.commands');
    if ($command === 'resumeRelationship') {
      $paused = $commands->pauseRelationship($id, [
        'summary' => 'Send an update',
        'why_it_helps' => 'Explain the next conversation',
        'expected_by' => $this->now + 7200,
        'optional' => TRUE,
        'side' => 'agency',
      ], 'pause-before-computed-date', $version);
      $this->assertSame('committed', $paused['outcome']);
    }
    $request->setMethod('GET');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(DiscoveryCommandForm::class, $id, $command);
    $post = [
      'form_id' => $form['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'retry' => (string) $form['actions']['submit']['#value'],
    ];
    if ($command === 'moveForward') {
      $post += ['target_purpose' => 'Discuss an assignment', 'message' => 'Consider the next conversation.'];
      $request->setMethod('POST');
      $request->request->replace($post);
      $review_state = (new FormState())->setRequestMethod('POST')->disableRedirect();
      $review_state->addBuildInfo('args', [$id, $command]);
      $form = $builder->buildForm(DiscoveryCommandForm::class, $review_state);
      $this->assertSame([], $review_state->getErrors());
      $this->assertTrue($review_state->get('await_confirmation'));
    }
    $nonce = $form['review_nonce']['#value'];
    $post['review_nonce'] = $nonce;
    $post['retry'] = (string) $form['actions']['submit']['#value'];
    $request->setMethod('POST');
    $request->request->replace($post);
    if ($replay) {
      // The public command commits before the console receives its outcome.
      $record = $this->container->get('agency_host.console_reviews')->read($request, $nonce, $id, $command);
      $intent = $record['intent'];
      $first = $this->container->get('agency_host.console')->command($request, $id, $command, $intent);
      $this->assertSame('committed', $first['outcome']);
    }
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, $command]);
    $response = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $result = $state->get('result');
    $this->assertSame('committed', $result['outcome']);
    $current = $this->container->get('agency_staff.reads')->queue()['contexts'][0];
    if ($replay) {
      $this->assertArrayNotHasKey('version', $result);
      $this->assertSame($first['version'], $current['version']);
      $this->assertArrayNotHasKey('saved_deadline', $response);
    }
    else {
      $this->assertSame($current['version'], $result['version']);
      $this->assertArrayHasKey('saved_deadline', $response);
      $date = $this->container->get('date.formatter')->format($current['next_action']['expected_by'], 'custom', 'Y-m-d H:i T');
      $rendered = (string) $this->container->get('renderer')->renderRoot($response['saved_deadline']);
      $this->assertTrue(str_contains($rendered, $date), 'The saved date matches the version-qualified public obligation.');
      $changed = $commands->setNextAction($id, [
        'summary' => 'A later obligation',
        'why_it_helps' => 'Keep the current date distinct',
        'expected_by' => $this->now + 14400,
        'optional' => TRUE,
        'side' => 'agency',
      ], 'later-after-computed-date', $current['version']);
      $this->assertSame('committed', $changed['outcome']);
      $request->setMethod('GET');
      $request->request->replace([]);
      $request->query->set('review', $nonce);
      $later = $builder->getForm(DiscoveryCommandForm::class, $id, $command);
      $this->assertArrayNotHasKey('saved_deadline', $later);
    }
  }

  /**
   * Public first-commit and receipt-only replay boundaries for computed dates.
   */
  public static function computedDeadlineCases(): array {
    return [
      'resume first commit' => ['resumeRelationship', FALSE],
      'resume receipt replay' => ['resumeRelationship', TRUE],
      'proposal first commit' => ['moveForward', FALSE],
      'proposal receipt replay' => ['moveForward', TRUE],
    ];
  }

  /**
   * Retained results remain releasable without exposing a reassigned person.
   */
  public function testConsoleHeldResultAfterReassignment(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $replacement = User::create([
      'name' => 'other-console-owner',
      'status' => 1,
      'roles' => $this->coordinator->getRoles(TRUE),
    ]);
    $replacement->save();
    $reviews = $this->container->get('agency_host.console_reviews');
    $app = $this->container->get('agency_host.console');
    $row = $app->queue($request)['contexts'][0];
    $intent = [
      'input' => [
        'owner' => [
          'uid' => (int) $replacement->id(),
          'contact' => ['kind' => 'email', 'value' => 'other-owner@example.test'],
        ],
      ],
      'key' => 'console-own-reassignment',
      'version' => $version,
    ];
    $nonce = $reviews->capture($request, $id, 'reassign', $intent, $row);
    $intent += ['command' => 'reassign', 'review_nonce' => $nonce];
    $result = $app->intent($request, $id, $intent, fn(): array => $app->command($request, $id, 'reassign', $intent));
    $this->assertSame('committed', $result['outcome']);
    $this->assertSame([], $app->queue($request)['contexts']);
    $request->setMethod('GET');
    $request->query->set('review', $nonce);
    $form = $this->container->get('form_builder')->getForm(DiscoveryHeldForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringNotContainsString($row['display_name'], $html);
    $this->assertStringContainsString('This request was saved', $html);
    $this->assertArrayHasKey('acknowledge', $form['actions']);
    $this->assertArrayNotHasKey('open', $form);
    $post = [
      'form_id' => $form['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'review_nonce' => $nonce,
      'retry' => 'Stale confirmation',
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $this->container->get('form_builder')->buildForm(DiscoveryHeldForm::class, $state);
    $this->assertNotNull($app->pending($request, $id));
    $post['acknowledge'] = (string) $form['actions']['acknowledge']['#value'];
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $this->container->get('form_builder')->buildForm(DiscoveryHeldForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertNull($app->pending($request, $id));
    $this->assertSame([], $reviews->listing($request));
  }

  /**
   * Session lifetime and payload expiry never silently release unknown holds.
   */
  public function testConsoleRetentionAndConfirmedOrphanCleanup(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $request->getSession()->set('uid', (int) $this->coordinator->id());
    $handler = $this->container->get('session_handler.storage');
    $this->assertSame(SessionHandler::class, get_class($handler));
    $this->assertTrue($handler->write($request->getSession()->getId(), 'synthetic-session'));
    $reviews = $this->container->get('agency_host.console_reviews');
    $state = $this->container->get('agency_host.console_state');
    $app = $this->container->get('agency_host.console');
    $row = $app->queue($request)['contexts'][0];
    $intent = ['input' => [], 'version' => $version, 'key' => 'retention-unknown'];
    $nonce = $reviews->capture($request, $id, 'resumeRelationship', $intent, $row);
    $intent += ['command' => 'resumeRelationship', 'review_nonce' => $nonce];
    $result = $app->intent($request, $id, $intent, static fn(): array => ['outcome' => 'uncertain']);
    $this->assertTrue($result['unconfirmed']);
    $this->now += 1801;
    $request->getSession()->set('unrelated-page', 'does not extend draft');
    $state->collectOrphans();
    $this->assertNotNull($app->pending($request, $id));
    $expired = $reviews->read($request, $nonce, $id, 'resumeRelationship', TRUE);
    $this->assertSame([], $expired['projection']);
    $this->assertSame(['key' => $intent['key']], $expired['intent']);
    try {
      $reviews->read($request, $nonce, $id, 'resumeRelationship');
      $this->fail('Expired payload became usable after unrelated activity.');
    }
    catch (Refusal $error) {
      $this->assertSame('review_expired', $error->reason);
    }
    $this->assertTrue($handler->destroy($request->getSession()->getId()));
    $state->collectOrphans();
    $this->assertSame([], $reviews->listing($request));
    $this->assertNull($app->pending($request, $id));
  }

  /**
   * A cached validation error cannot reveal a former owner's private review.
   */
  public function testConsoleCachedPostRechecksAssignment(): void {
    $this->assertCachedConsolePostRefused(FALSE);
  }

  /**
   * A cached invalid-token submission cannot revive an expired private review.
   */
  public function testConsoleCachedPostRechecksExpiry(): void {
    $this->assertCachedConsolePostRefused(TRUE);
  }

  /**
   * Exercises actual cached build IDs, including a live authorized control.
   */
  private function assertCachedConsolePostRefused(bool $expiry): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $builder = $this->container->get('form_builder');
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
    $request->setMethod('POST');
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'summary' => 'Private cached review text',
      'why_it_helps' => 'Only the currently assigned owner may read this',
      'expected_by' => ['date' => gmdate('Y-m-d', $this->now + 172800), 'time' => '12:00:00'],
      'side' => 'agency',
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'setNextAction']);
    $review = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertTrue($state->get('await_confirmation'));
    $build_id = $review['#build_id'];
    $cached = $builder->getCache($build_id, new FormState());
    $this->assertIsArray($cached, 'The control really uses a stored private form.');
    $this->assertSame('Private cached review text', $cached['summary']['#default_value']);
    $nonce = $state->get('review_nonce');
    if ($expiry) {
      $this->now += 1801;
    }
    else {
      $replacement = User::create([
        'name' => 'cached-form-new-owner',
        'status' => 1,
        'roles' => ['employee', 'coordinator'],
      ]);
      $replacement->save();
      $result = $this->container->get('agency_staff.commands')->setNextAction($id, [
        'owner' => [
          'uid' => (int) $replacement->id(),
          'contact' => ['kind' => 'email', 'value' => 'new-owner@example.test'],
        ],
      ], 'reassign-before-cached-post', $version);
      $this->assertSame('committed', $result['outcome']);
    }
    $request->request->replace([
      'form_id' => $review['form_id']['#value'],
      'form_build_id' => $build_id,
      'form_token' => 'invalid-token-does-not-run-submit',
      'review_nonce' => $nonce,
      'retry' => 'Stale initial Review button',
    ]);
    $rejected = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $rejected->addBuildInfo('args', [$id, 'setNextAction']);
    $form = $builder->buildForm(DiscoveryCommandForm::class, $rejected);
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringNotContainsString('Private cached review text', $html);
    $this->assertStringNotContainsString('Only the currently assigned owner', $html);
    $this->assertNull($rejected->get('intent'));
    $this->assertFalse($this->container->get('agency_host.console_reviews')->read($request, $nonce, $id, 'setNextAction', TRUE)['attempted']);
  }

  /**
   * Invitation links exist only in the first response, never cached recovery.
   */
  public function testConsoleInvitationLinkIsNotCachedOrReplayed(): void {
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'console-link-start',
    ]);
    $this->session($request);
    $start = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    new Settings(array_replace(Settings::getAll(), ['agency_host_invitation_origin' => 'https://portal.example.test']));
    $this->container->get('router.builder')->rebuild();
    $request->setMethod('GET');
    $request->request->replace([]);
    $builder = $this->container->get('form_builder');
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'issue');
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'review_nonce' => $initial['review_nonce']['#value'],
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'issue']);
    $saved = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('committed', $state->get('result')['outcome']);
    $this->assertArrayHasKey('invitation_link', $saved);
    $url = $saved['invitation_link']['#url']->getUri();
    $token = basename(parse_url($url, PHP_URL_PATH));
    $this->assertTrue(strlen($token) >= 32, 'First success has a usable private link.');
    $this->assertFalse(str_contains(serialize($state->getCacheableArray()), $token), 'Cached state excludes the bearer handle.');
    $record = $this->container->get('agency_host.console_reviews')->read($request, $post['review_nonce'], $id, 'issue');
    $this->assertFalse(str_contains(serialize($record), $token), 'Retained request/result excludes the bearer handle.');
    $cached = $this->container->get('keyvalue.expirable')->get('form')->get($saved['#build_id']);
    $this->assertFalse(str_contains(serialize($cached), $token), 'Core cached rendered form excludes the bearer handle.');
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $replay = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $replay->addBuildInfo('args', [$id, 'issue']);
    $recovered = $builder->buildForm(DiscoveryCommandForm::class, $replay);
    $this->assertSame('committed', $replay->get('result')['outcome']);
    $this->assertSame($state->get('result')['receipt'], $replay->get('result')['receipt']);
    $this->assertArrayNotHasKey('invitation_link', $recovered);
    $this->assertFalse(str_contains(serialize($recovered), $token), 'Recovery form never regenerates the bearer handle.');
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Failed review persistence keeps a draft.
   *
   * A failed capture never becomes an executable partial intent.
   */
  public function testConsoleCaptureFailurePreservesEditableDraft(): void {
    [$id] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $metadata = $this->container->get('agency_host.console_metadata');
    $fault = $this->createMock(KeyValueStoreInterface::class);
    $fault->method('get')->willReturnCallback(fn($key, $default = NULL) => $metadata->get($key, $default));
    $fault->method('delete')->willReturnCallback(fn($key) => $metadata->delete($key));
    $fail_write = TRUE;
    $fault->method('set')->willReturnCallback(function ($key, $value) use ($metadata, &$fail_write): void {
      if ($fail_write) {
        $fail_write = FALSE;
        throw new \RuntimeException('Synthetic metadata write unavailable.');
      }
      $metadata->set($key, $value);
    });
    $state_store = new ConsoleStateStore($fault, $this->container->get('agency_host.console_payloads'), $this->container->get('lock'), $this->container->get('current_user'), $this->container->get('datetime.time'), $this->container->get('database'), $this->container->get('session_handler.storage'));
    $reviews = new ConsoleReviewStore($this->container->get('current_user'), $this->container->get('datetime.time'), $state_store);
    $app = new ConsoleApplication($this->container->get('agency_host.console_access'), $this->container->get('agency_staff.reads'), $this->container->get('agency_staff.commands'), $this->container->get('agency_staff.invitations'), $this->container->get('agency_host.transport'), $state_store);
    $this->container->set('agency_host.console', $app);
    $this->container->set('agency_host.console_reviews', $reviews);
    $builder = $this->container->get('form_builder');
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
    $baseline = $app->queue($request)['contexts'];
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'summary' => 'Keep my unsubmitted draft',
      'why_it_helps' => 'A failed capture must not produce a partial request',
      'expected_by' => ['date' => gmdate('Y-m-d', $this->now + 172800), 'time' => '12:00:00'],
      'side' => 'agency',
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'setNextAction']);
    $failed = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('unavailable', $state->get('result')['reason']);
    $this->assertNull($state->get('intent'));
    $this->assertNull($state->get('review_nonce'));
    $this->assertSame('Keep my unsubmitted draft', $failed['summary']['#default_value']);
    $this->assertFalse($failed['summary']['#disabled']);
    $this->assertArrayNotHasKey('acknowledge', $failed['actions']);
    $this->assertSame([], $reviews->listing($request));
    $this->assertSame($baseline, $app->queue($request)['contexts']);
    $recovered = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $recovered->addBuildInfo('args', [$id, 'setNextAction']);
    $review = $builder->buildForm(DiscoveryCommandForm::class, $recovered);
    $this->assertSame([], $recovered->getErrors());
    $this->assertTrue($recovered->get('await_confirmation'));
    $this->assertNotEmpty($recovered->get('review_nonce'));
    $this->assertSame($baseline, $app->queue($request)['contexts']);
    $post['review_nonce'] = $recovered->get('review_nonce');
    $post['retry'] = (string) $review['actions']['submit']['#value'];
    $request->request->replace($post);
    $confirmation = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $confirmation->addBuildInfo('args', [$id, 'setNextAction']);
    $builder->buildForm(DiscoveryCommandForm::class, $confirmation);
    $this->assertSame([], $confirmation->getErrors());
    $this->assertSame('committed', $confirmation->get('result')['outcome']);
    $this->assertSame($baseline[0]['version'] + 1, $app->queue($request)['contexts'][0]['version']);
  }

  /**
   * Capacity renders truthful recovery and explicit discard frees one slot.
   */
  public function testConsoleCapacityAndHeldDraftDiscard(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $reviews = $this->container->get('agency_host.console_reviews');
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $nonces = [];
    for ($i = 0; $i < 16; $i++) {
      $nonces[] = $reviews->capture($request, $id, 'setNextAction', [
        'input' => ['summary' => 'Unsubmitted draft ' . $i],
        'version' => $version,
        'key' => 'retained-draft-' . $i,
      ], $baseline[0]);
    }
    $builder = $this->container->get('form_builder');
    $initial = $builder->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
    $post = [
      'form_id' => $initial['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($initial['#token']),
      'summary' => 'New review cannot evict old requests',
      'why_it_helps' => 'Make the capacity boundary explicit',
      'expected_by' => ['date' => gmdate('Y-m-d', $this->now + 172800), 'time' => '12:00:00'],
      'side' => 'agency',
      'retry' => (string) $initial['actions']['submit']['#value'],
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'setNextAction']);
    $limited = $builder->buildForm(DiscoveryCommandForm::class, $state);
    $html = (string) $this->container->get('renderer')->renderRoot($limited);
    $this->assertSame('review_capacity', $state->get('result')['reason']);
    $this->assertNull($state->get('intent'));
    $this->assertStringContainsString('waiting may not free any', $html);
    $this->assertStringContainsString('can be discarded', $html);
    $this->assertStringNotContainsString('can\'t be dismissed', $html);
    $this->assertCount(16, $reviews->listing($request));
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
    $request->setMethod('GET');
    $request->request->replace([]);
    $request->query->set('review', $nonces[0]);
    $held = $builder->getForm(DiscoveryHeldForm::class);
    $request->setMethod('POST');
    $request->request->replace([
      'form_id' => $held['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($held['#token']),
      'review_nonce' => $nonces[0],
      'discard' => (string) $held['actions']['discard']['#value'],
    ]);
    $released = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $builder->buildForm(DiscoveryHeldForm::class, $released);
    $this->assertSame([], $released->getErrors());
    $this->assertCount(15, $reviews->listing($request));
    $request->query->replace([]);
    $request->request->replace($post);
    $retry = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $retry->addBuildInfo('args', [$id, 'setNextAction']);
    $builder->buildForm(DiscoveryCommandForm::class, $retry);
    $this->assertSame([], $retry->getErrors());
    $this->assertTrue($retry->get('await_confirmation'));
    $this->assertCount(16, $reviews->listing($request));
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * A stale initial Review button cannot become a final confirmation POST.
   */
  public function testConsoleStaleReviewButtonDoesNotConfirm(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $handler = DiscoveryCommandForm::create($this->container);
    $state = (new FormState())->setRequestMethod('POST');
    $form = $handler->buildForm([], $state, $id, 'setNextAction');
    $old_label = (string) $form['actions']['submit']['#value'];
    $state->setValues([
      'summary' => 'Explicit confirmation only',
      'why_it_helps' => 'Do not apply a stale review submission',
      'expected_by' => DrupalDateTime::createFromTimestamp($this->now + 7200),
      'side' => 'agency',
    ])->setTriggeringElement(['#name' => 'retry']);
    $this->submitConsole($handler, $form, $state);
    $nonce = $state->get('review_nonce');
    $request->setMethod('GET');
    $view = (new FormState())->setRequestMethod('GET')->set('review_nonce', $nonce);
    $view->addBuildInfo('args', [$id, 'setNextAction']);
    $confirmation = $this->container->get('form_builder')->buildForm(DiscoveryCommandForm::class, $view);
    $confirm_label = (string) $confirmation['actions']['submit']['#value'];
    $this->assertNotSame($old_label, $confirm_label);
    $baseline = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $post = [
      'form_id' => $confirmation['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($confirmation['#token']),
      'review_nonce' => $nonce,
      'retry' => $old_label,
    ];
    $request->setMethod('POST');
    $request->request->replace($post);
    $stale = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $stale->addBuildInfo('args', [$id, 'setNextAction']);
    $this->container->get('form_builder')->buildForm(DiscoveryCommandForm::class, $stale);
    $this->assertSame([], $stale->getErrors());
    $this->assertSame('not_found', $stale->get('result')['reason']);
    $this->assertSame($baseline, $this->container->get('agency_staff.reads')->queue()['contexts']);
    $this->assertFalse($this->container->get('agency_host.console_reviews')->read($request, $nonce, $id, 'setNextAction')['attempted']);
    $post['retry'] = $confirm_label;
    $request->request->replace($post);
    $fresh = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $fresh->addBuildInfo('args', [$id, 'setNextAction']);
    $this->container->get('form_builder')->buildForm(DiscoveryCommandForm::class, $fresh);
    $this->assertSame([], $fresh->getErrors());
    $this->assertSame('committed', $fresh->get('result')['outcome']);
    $this->assertSame($version + 1, $fresh->get('result')['version']);
  }

  /**
   * Form API's default button cannot acknowledge or release a stale POST.
   */
  public function testConsoleImplicitFormButtonsCannotReleaseRequests(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'POST');
    $this->session($request);
    $app = $this->container->get('agency_host.console');
    $store = $this->container->get('agency_host.console_reviews');
    $row = $app->queue($request)['contexts'][0];
    $intent = [
      'input' => [
        'summary' => 'Explicit release test',
        'why_it_helps' => 'Verify the actual confirmation boundary',
        'expected_by' => $this->now + 7200,
        'optional' => TRUE,
        'side' => 'agency',
      ],
      'version' => $version,
      'key' => 'actual-form-api-release',
    ];
    $nonce = $store->capture($request, $id, 'setNextAction', $intent, $row);
    $intent += ['review_nonce' => $nonce, 'command' => 'setNextAction'];
    $store->attempted($request, $nonce);
    $result = $app->intent($request, $id, $intent, fn(): array => $app->command($request, $id, 'setNextAction', $intent));
    $this->assertSame('committed', $result['outcome']);
    $store->result($request, $nonce, $result);
    $baseline = $app->queue($request)['contexts'];
    foreach (['acknowledge', 'expired_continue'] as $release) {
      if ($release === 'expired_continue') {
        $this->now += 1801;
      }
      $request->setMethod('GET');
      $request->request->replace([]);
      $form = $this->container->get('form_builder')->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
      $this->assertArrayHasKey($release, $form['actions']);
      $values = [
        'form_id' => $form['form_id']['#value'],
        'form_token' => $this->container->get('csrf_token')->get($form['#token']),
        'review_nonce' => $nonce,
        'retry' => 'A stale former button',
      ];
      $request->setMethod('POST');
      $request->request->replace($values);
      $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
      $state->addBuildInfo('args', [$id, 'setNextAction']);
      $this->container->get('form_builder')->buildForm(DiscoveryCommandForm::class, $state);
      $this->assertSame($release, $state->getTriggeringElement()['#name']);
      $this->assertSame([], $state->getErrors());
      $this->assertNotNull($app->pending($request, $id));
      $this->assertSame($intent['key'], $store->read($request, $nonce, $id, 'setNextAction', TRUE)['intent']['key']);
      $this->assertSame($baseline, $app->queue($request)['contexts']);
    }
    // The explicit release remains available after the stale submission.
    $request->setMethod('GET');
    $request->request->replace([]);
    $form = $this->container->get('form_builder')->getForm(DiscoveryCommandForm::class, $id, 'setNextAction');
    $request->setMethod('POST');
    $request->request->replace([
      'form_id' => $form['form_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'review_nonce' => $nonce,
      'expired_continue' => (string) $form['actions']['expired_continue']['#value'],
    ]);
    $state = (new FormState())->setRequestMethod('POST')->disableRedirect();
    $state->addBuildInfo('args', [$id, 'setNextAction']);
    $this->container->get('form_builder')->buildForm(DiscoveryCommandForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertNull($app->pending($request, $id));
    $this->assertSame($baseline, $app->queue($request)['contexts']);
  }

  /**
   * A no-input action opens review on GET, retaining its reviewed version.
   */
  public function testConsoleResumeGetReviewsWithoutMutation(): void {
    [$id, , $version] = $this->accepted();
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->request(NULL, 'POST');
    $paused = $this->container->get('agency_staff.commands')->pauseRelationship($id, [
      'summary' => 'Send an update',
      'why_it_helps' => 'Explain the next conversation',
      'expected_by' => $this->now + 7200,
      'optional' => TRUE,
      'side' => 'agency',
    ], 'pause-before-console-review', $version);
    $this->assertSame('committed', $paused['outcome']);
    $request = $this->request(NULL, 'GET');
    $this->session($request);
    $this->container->get('router.builder')->rebuild();
    $before = $this->container->get('agency_staff.reads')->queue()['contexts'];
    $form = $this->container->get('form_builder')->getForm(DiscoveryCommandForm::class, $id, 'resumeRelationship');
    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $this->assertStringContainsString('name="review_nonce"', $html);
    $this->assertStringNotContainsString('Review before saving', $html);
    $this->assertStringContainsString('Check this before you save', $html);
    $this->assertStringNotContainsString('{name}', $html);
    $this->assertStringNotContainsString('{state}', $html);
    $this->assertSame($before, $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * Cookie coordinator authority cannot be mixed with participant credentials.
   */
  public function testCoordinatorMixedCredentialsRefused(): void {
    foreach (['Authorization', AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER] as $header) {
      $request = $this->request(NULL, 'POST', [
        'input' => $this->input(),
        'intended_subject' => 'person-one',
        'key' => 'mixed-credential-test',
      ]);
      $this->session($request);
      $request->headers->set($header, 'fixture-other-credential');
      $result = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
      $this->assertSame(['outcome' => 'refused', 'reason' => 'not_found'], $result);
      $this->assertSame(0, $this->lookups);
    }
  }

  /**
   * Public commands connect proposal, pause, reassignment, consent and summary.
   */
  public function testCoordinatorAndTwoParticipantLifecycle(): void {
    [$id, , $version] = $this->accepted();
    $participant = ParticipantDiscoveryController::create($this->container);
    $coordinator = CoordinatorDiscoveryController::create($this->container);
    $owner = $this->coordinator;
    $company = function (string $operation, array $input) use ($id, &$version, &$owner, $coordinator): array {
      $this->container->get('current_user')->setAccount($owner);
      $request = $this->request(NULL, 'POST', [
        'input' => $input ?: new \stdClass(),
        'key' => 'company-' . $operation . '-' . $version,
        'version' => $version,
      ]);
      $this->session($request);
      $result = $this->payload($coordinator->command($id, $operation, $request));
      $this->assertSame('committed', $result['outcome'], json_encode($result));
      $version = $result['version'];
      return $result;
    };
    $workspace = function (string $expected) use ($id, &$version, $participant): array {
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
      $request = $this->request('person-one', 'GET');
      $result = $this->payload($participant->workspace($id, $request));
      $this->assertSame('available', $result['outcome']);
      $this->assertSame($expected, $result['workspace']['state']);
      $version = $result['workspace']['version'];
      $request = $this->request('person-two', 'GET');
      $this->assertSame('not_found', $this->payload($participant->workspace($id, $request))['reason']);
      return $result['workspace'];
    };
    $company('moveForward', [
      'target_purpose' => 'Discuss a scoped assignment',
      'message' => 'Review the proposed next conversation.',
    ]);
    $this->assertNotNull($workspace('exploring')['proposal']);
    $request = $this->request('person-one', 'POST', [
      'input' => ['note' => 'Time to consider'],
      'key' => 'participant-pause',
      'version' => $version,
    ]);
    $this->assertSame('committed', $this->payload($participant->command($id, 'pauseRelationship', $request))['outcome']);
    $workspace('paused');
    $replacement = User::create([
      'name' => 'replacement-coordinator',
      'status' => 1,
      'roles' => ['employee', 'coordinator'],
    ]);
    $replacement->save();
    $company('setNextAction', [
      'owner' => [
        'uid' => (int) $replacement->id(),
        'contact' => ['kind' => 'email', 'value' => 'replacement@example.test'],
      ],
    ]);
    $this->assertSame('replacement-coordinator', $workspace('paused')['owner']['name']);
    $this->container->get('current_user')->setAccount($this->coordinator);
    $old = $this->request(NULL, 'POST', [
      'input' => new \stdClass(),
      'key' => 'former-owner-denied',
      'version' => $version,
    ]);
    $this->session($old);
    $this->assertSame('not_found', $this->payload($coordinator->command($id, 'suspendAccess', $old))['reason']);
    $owner = $replacement;
    $request = $this->request('person-one', 'POST', [
      'input' => new \stdClass(),
      'key' => 'participant-resume',
      'version' => $version,
    ]);
    $this->assertSame('committed', $this->payload($participant->command($id, 'resumeRelationship', $request))['outcome']);
    $workspace('exploring');
    $request = $this->request('person-one', 'POST', [
      'input' => ['consent' => TRUE],
      'key' => 'participant-consent',
      'version' => $version,
    ]);
    $this->assertSame('committed', $this->payload($participant->command($id, 'moveForward', $request))['outcome']);
    $workspace('moved_forward');
    $company('close', [
      'category' => 'not_now',
      'message' => 'This conversation is complete.',
      'contact' => ['kind' => 'email', 'value' => 'support@example.test'],
    ]);
    $request = $this->request('person-one', 'GET');
    $summary = $this->payload($participant->summary($id, $request));
    $this->assertSame('closed', $summary['summary']['state']);
    $this->assertArrayNotHasKey('pause_note', $summary['summary']);
    $company('suspendAccess', []);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('not_found', $this->payload($participant->summary($id, $request))['reason']);
  }

  /**
   * Summary access expires through the clock port without storage mutation.
   */
  public function testClosingSummaryExpiry(): void {
    [$id, , $version] = $this->accepted();
    $participant = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'POST', [
      'input' => new \stdClass(),
      'key' => 'summary-expiry-stop',
      'version' => $version,
    ]);
    $receipt = $this->payload($participant->command($id, 'withdrawRelationship', $request));
    $this->assertSame('committed', $receipt['outcome']);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($participant->summary($id, $request))['outcome']);
    $this->now += 31 * 86400;
    $this->assertSame('not_found', $this->payload($participant->summary($id, $request))['reason']);
    $this->assertSame('pending', $receipt['receipt']['evidence']);
    $this->assertSame('committed', $receipt['outcome']);
  }

  /**
   * Delivery storage preserves qualified history and exact earlier commands.
   */
  public function testInvitationDeliveryUpgradePreservesQualifiedHistory(): void {
    $database = $this->container->get('database');
    $updates = $this->container->get('update.update_hook_registry');
    $history = $this->container->get('agency_host.binding_history');
    // Establish the known synthetic previous installation before any command.
    // No coverage record is rewritten after the update under test.
    $database->schema()->dropTable('agency_host_delivery');
    $updates->setInstalledVersion('agency_host', 10001);
    $database->update('agency_host_binding_coverage')
      ->fields(['fingerprint' => $history->fingerprint()])->execute();
    $linked = User::create(['name' => 'previously-revoked', 'status' => 1]);
    $linked->save();
    $authmap = $this->container->get('externalauth.authmap');
    $authmap->save($linked, 'openid_connect.client_portal', 'person-two');
    $authmap->delete((int) $linked->id());
    $this->assertFalse($history->authorize('person-two', static fn(): bool => TRUE));
    $input = [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'before-delivery-upgrade',
    ];
    $request = $this->request(NULL, 'POST', $input);
    $this->session($request);
    $controller = CoordinatorDiscoveryController::create($this->container);
    $start = $this->payload($controller->start($request));
    $this->assertSame('committed', $start['outcome']);
    $id = $start['receipt']['relationship_id'];
    $invitations = $this->container->get('agency_staff.invitations');
    $issued = $invitations->issue($id, 'before-delivery-issue', 1);
    $this->assertSame('committed', $issued['outcome']);
    $before = $this->container->get('agency_staff.reads')->queue();
    $coverage = $database->select('agency_host_binding_coverage', 'c')
      ->fields('c')->execute()->fetchAssoc();
    $this->container->get('module_handler')->loadInclude('agency_host', 'install');
    \agency_host_update_10002();
    // Drupal records this only after the hook succeeds.
    $updates->setInstalledVersion('agency_host', 10002);
    $this->assertTrue($database->schema()->tableExists('agency_host_delivery'));
    $replay = $this->payload($controller->start($request));
    $this->assertSame('committed', $replay['outcome'], json_encode($replay));
    $this->assertSame($start['receipt'], $replay['receipt']);
    $issue_replay = $invitations->issue($id, 'before-delivery-issue', 1);
    $this->assertSame($issued['receipt'], $issue_replay['receipt']);
    $this->assertArrayNotHasKey('invitation', $issue_replay);
    $this->assertSame($before, $this->container->get('agency_staff.reads')->queue());
    $this->assertFalse($history->authorize('person-two', static fn(): bool => TRUE));
    $this->assertSame($coverage, $database->select('agency_host_binding_coverage', 'c')
      ->fields('c')->execute()->fetchAssoc());
    \agency_host_update_10002();
    $this->assertSame($start['receipt'], $this->payload($controller->start($request))['receipt']);
  }

  /**
   * New history qualifies, but unknown schema drift cannot heal on rollback.
   */
  public function testInvitationDeliveryUpgradeRejectsUnknownIdentitySchema(): void {
    $updates = $this->container->get('update.update_hook_registry');
    $this->assertSame(10003, $updates->getInstalledVersion('agency_host'));
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $updates->setInstalledVersion('agency_host', 10004);
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $updates->setInstalledVersion('agency_host', 10003);
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * The additive update cannot qualify missing or compromised prior history.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('unqualifiedDeliveryUpgradeHistory')]
  public function testInvitationDeliveryUpgradeDoesNotRequalify(string $failure): void {
    $database = $this->container->get('database');
    $updates = $this->container->get('update.update_hook_registry');
    $history = $this->container->get('agency_host.binding_history');
    $database->schema()->dropTable('agency_host_delivery');
    $updates->setInstalledVersion('agency_host', 10001);
    $database->update('agency_host_binding_coverage')
      ->fields(['fingerprint' => $history->fingerprint()])->execute();
    if ($failure === 'missing') {
      $database->delete('agency_host_binding_coverage')->execute();
    }
    elseif ($failure === 'unqualified' || $failure === 'mismatched') {
      $database->update('agency_host_binding_coverage')->fields(
        $failure === 'unqualified' ? ['qualified' => 0] : ['fingerprint' => str_repeat('0', 64)],
      )->execute();
    }
    else {
      $epoch = $database->select('agency_host_binding_coverage', 'c')
        ->fields('c', ['epoch'])->execute()->fetchField();
      $latch = $this->container->get('agency_host.qualification_latch');
      if ($failure === 'unresolved') {
        $latch->arm($epoch);
      }
      else {
        $latch->invalidate($epoch);
      }
    }
    $this->container->get('module_handler')->loadInclude('agency_host', 'install');
    \agency_host_update_10002();
    $updates->setInstalledVersion('agency_host', 10002);
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'unqualified-delivery-upgrade',
    ]);
    $this->session($request);
    $result = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
    $this->assertSame(['outcome' => 'refused', 'reason' => 'identity_unavailable'], $result);
  }

  /**
   * Prior failure states that a storage-only upgrade must leave refused.
   */
  public static function unqualifiedDeliveryUpgradeHistory(): array {
    return array_map(static fn(string $failure): array => [$failure], [
      'missing', 'unqualified', 'mismatched', 'unresolved', 'invalidated',
    ]);
  }

  /**
   * Replacement and deletion remain revoked after an ordinary relink.
   */
  public function testReplacedBindingCannotRegainAuthority(): void {
    [$id] = $this->accepted();
    $linked = User::create(['name' => 'rebound-person', 'status' => 1]);
    $linked->save();
    $authmap = $this->container->get('externalauth.authmap');
    $authmap->save($linked, 'openid_connect.client_portal', 'person-one');
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $authmap->save($linked, 'openid_connect.client_portal', 'person-two');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
    $authmap->save($linked, 'openid_connect.client_portal', 'person-one');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
    $authmap->delete((int) $linked->id());
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Direct database writes cannot create, replace, or erase authority.
   */
  public function testDirectAuthmapWritesCannotBypassHistory(): void {
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $database = $this->container->get('database');
    $linked = User::create(['name' => 'direct-binding-person', 'status' => 1]);
    $linked->save();
    $database->insert('authmap')->fields([
      'uid' => $linked->id(),
      'provider' => 'openid_connect.client_portal',
      'authname' => 'person-one',
      'data' => NULL,
    ])->execute();
    // This public read opens and rolls back Agency's outer root transaction.
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $database->delete('authmap')->condition('uid', $linked->id())->execute();
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason'], 'Restoring the exact original map cannot revive the invalidated epoch.');
    $this->container->get('externalauth.authmap')->save($linked, 'openid_connect.client_portal', 'person-one');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason'], 'An ordinary tracked write cannot requalify lost coverage.');
  }

  /**
   * Missing scope does not block host user deletion or ordinary OIDC writes.
   */
  public function testUnconfiguredHistoryPreservesHostOperations(): void {
    [$id] = $this->accepted();
    $original = Settings::getAll();
    new Settings(array_replace($original, ['agency_host_identity' => []]));
    // Replace the public authmap service with unconfigured history.
    $history = new BindingHistory(
      $this->container->get('database'), new Settings(Settings::getAll()),
      $this->container->get('config.factory'), $this->container->get('module_handler'),
      $this->container->get('update.update_hook_registry'), $this->container->get('datetime.time'),
      $this->container->get('agency_host.qualification_latch'),
      new DatabaseStorage($this->container->get('database'), 'config'),
    );
    $inner = new Authmap($this->container->get('database'));
    $authmap = new TrackedAuthmap($inner, $history);
    $original_authmap = $this->container->get('externalauth.authmap');
    $this->container->set('externalauth.authmap', $authmap);
    $this->installEntitySchema('node');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('file');
    $unlinked = User::create(['name' => 'unlinked-delete', 'status' => 1]);
    $unlinked->save();
    $authmap->delete((int) $unlinked->id(), NULL);
    $authmap->save($unlinked, 'unrelated-provider', 'external');
    $this->assertSame((int) $unlinked->id(), (int) $authmap->getUid('external', 'unrelated-provider'));
    $authmap->delete((int) $unlinked->id(), 'unrelated-provider');
    $authmap->save($unlinked, 'openid_connect.client_portal', 'person-one');
    $this->assertSame((int) $unlinked->id(), (int) $authmap->getUid('person-one', 'openid_connect.client_portal'));
    $authmap->delete((int) $unlinked->id(), NULL);
    $unlinked->delete();
    $this->container->set('externalauth.authmap', $original_authmap);
    new Settings($original);
    $controller = ParticipantDiscoveryController::create($this->container);
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $this->request('person-one', 'GET')))['reason']);
  }

  /**
   * HMAC key rotation cannot make an absent revoked subject appear never bound.
   */
  public function testHistoryKeyRotationInvalidatesEmptyCoverage(): void {
    [$id] = $this->accepted();
    $user = User::create(['name' => 'salt-rotation', 'status' => 1]);
    $user->save();
    $authmap = $this->container->get('externalauth.authmap');
    $authmap->save($user, 'openid_connect.client_portal', 'person-one');
    $authmap->delete((int) $user->id());
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
    $original = Settings::getAll();
    new Settings(array_replace($original, ['hash_salt' => 'rotated-synthetic-history-key']));
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    new Settings($original);
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Disabled clients refuse initiation and already-issued callback state.
   */
  public function testDisabledPortalClientContainsRoutesAndAliases(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $portal = OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => ['provider_slug' => 'synthetic-portal', 'iss_allowed_domains' => 'identity.example.test'],
    ]);
    $portal->save();
    $staff = OpenIDConnectClientEntity::create([
      'id' => 'sign_in_with_keycloak',
      'label' => 'Synthetic staff',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => ['provider_slug' => 'synthetic-staff', 'iss_allowed_domains' => 'identity.example.test'],
    ]);
    $staff->save();
    $this->container->get('router.builder')->rebuild();
    $request = $this->request(NULL, 'GET');
    $request->query->set('iss', self::ISSUER);
    $request->query->set('state', $this->container->get('openid_connect.state_token')->generateToken());
    $manager = $this->container->get('access_manager');
    foreach ([TRUE, FALSE] as $enabled) {
      $portal->setStatus($enabled)->save();
      foreach ([
        [$portal, 'client_portal'],
        [$portal, 'synthetic-portal'],
        [$staff, 'synthetic-staff'],
      ] as [$client, $parameter]) {
        $request->attributes->set('openid_connect_client', $client);
        foreach ([
          'openid_connect.redirect_controller_redirect_iss',
          'openid_connect.redirect_controller_redirect',
        ] as $route) {
          $result = $manager->checkNamedRoute($route, ['openid_connect_client' => $parameter], new AnonymousUserSession(), TRUE);
          $this->assertSame($enabled || $client->id() !== 'client_portal', $result->isAllowed(), $route . ' ' . $parameter);
        }
      }
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('authmap')->countQuery()->execute()->fetchField());
  }

  /**
   * Direct completion also refuses a portal disabled during an OIDC ceremony.
   */
  public function testDisabledPortalClientContainsPreAuthorization(): void {
    $portal = OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [],
    ]);
    $portal->save();
    $context = [
      'plugin_id' => 'client_portal',
      'sub' => 'synthetic-unlinked-person',
    ];
    $hooks = $this->container->get('module_handler');
    $this->assertNotContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
    $portal->disable()->save();
    $this->assertContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
    $staff_context = ['plugin_id' => 'sign_in_with_keycloak'];
    $this->assertNotContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $staff_context]));
    $portal->delete();
    $this->assertContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
  }

  /**
   * Settings activation works, but effective disable or deletion contains it.
   */
  public function testDisabledPortalClientRespectsSettingsOverrides(): void {
    $name = 'openid_connect.client.client_portal';
    OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => FALSE,
      'settings' => [],
    ])->save();
    $hooks = $this->container->get('module_handler');
    $context = ['plugin_id' => 'client_portal'];
    $original = $GLOBALS['config'][$name] ?? NULL;
    try {
      $GLOBALS['config'][$name]['status'] = TRUE;
      $this->assertNotContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
      $GLOBALS['config'][$name]['status'] = FALSE;
      $this->assertContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
      $GLOBALS['config'][$name]['status'] = TRUE;
      $this->config($name)->delete();
      $this->assertContains(FALSE, $hooks->invokeAll('openid_connect_pre_authorize', [FALSE, $context]));
    }
    finally {
      if ($original === NULL) {
        unset($GLOBALS['config'][$name]);
      }
      else {
        $GLOBALS['config'][$name] = $original;
      }
      $this->container->get('config.factory')->reset($name);
    }
  }

  /**
   * Actual contrib completion and linking refuse stale enabled client objects.
   */
  public function testDisabledPortalClientContainsActualCompletion(): void {
    $portal = OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [
        'authorization_endpoint' => 'https://identity.example.test/authorize',
        'token_endpoint' => 'https://identity.example.test/token',
        'userinfo_endpoint' => '',
        'end_session_endpoint' => 'https://identity.example.test/logout',
      ],
    ]);
    $portal->save();
    $this->request(NULL, 'GET');
    $tokens = [
      'id_token' => [
        'sub' => 'synthetic-completion-person',
        'email' => 'synthetic-completion@example.test',
      ],
    ];
    $oidc = $this->container->get('openid_connect.openid_connect');
    $current = $this->container->get('current_user');
    $current->setAccount($this->coordinator);
    // A real enabled link proves this fixture reaches the write boundary.
    $this->assertTrue($oidc->connectCurrentUser($portal, $tokens));
    $database = $this->container->get('database');
    $maps = $database->select('authmap', 'a')->fields('a')->execute()->fetchAll();
    $claims = User::load($this->coordinator->id())->toArray();
    $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();
    $before = $session->all();
    foreach (['disabled', 'deleted'] as $state) {
      $config = $this->config('openid_connect.client.client_portal');
      if ($state === 'disabled') {
        $config->set('status', FALSE)->save();
      }
      else {
        $config->delete();
      }
      // Keep the previously loaded enabled entity, as an in-flight request can.
      $current->setAccount(new AnonymousUserSession());
      $this->assertFalse($oidc->completeAuthorization($portal, $tokens));
      $this->assertTrue($current->isAnonymous());
      $current->setAccount($this->coordinator);
      $this->assertFalse($oidc->connectCurrentUser($portal, $tokens));
      $this->assertSame($this->coordinator->id(), $current->id());
      $this->assertEquals($maps, $database->select('authmap', 'a')->fields('a')->execute()->fetchAll());
      $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
      $this->assertSame($claims, User::load($this->coordinator->id())->toArray());
      $this->assertSame($before, $session->all());
    }
  }

  /**
   * An endpoint-only change invalidates coverage even after config restoration.
   */
  public function testAuthorityEndpointDriftStaysUnqualified(): void {
    OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [],
    ])->save();
    $history = $this->container->get('agency_host.binding_history');
    $this->container->get('database')->update('agency_host_binding_coverage')->fields([
      'fingerprint' => $history->fingerprint(),
      'epoch' => bin2hex(random_bytes(32)),
    ])->execute();
    [$id] = $this->accepted();
    $name = 'openid_connect.client.client_portal';
    $config = $this->config($name);
    $original = $config->getRawData();
    $config->set('settings.token_endpoint', 'https://changed.example.test/token')->save();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $config->setData($original)->save();
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Unfinished legacy migration cannot be qualified by a newer schema number.
   */
  public function testLegacyMappingsFenceRestoration(): void {
    [$id] = $this->accepted();
    $database = $this->container->get('database');
    $database->schema()->createTable('openid_connect_authmap', [
      'fields' => ['uid' => ['type' => 'int', 'not null' => TRUE]],
    ]);
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $database->schema()->dropTable('openid_connect_authmap');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Provider entity deletion reaches the corrected public authmap seam.
   */
  public function testActualProviderDeletionRevokesMappings(): void {
    $client = OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [],
    ]);
    $client->save();
    // Explicit test qualification after adding this synthetic provider.
    $history = $this->container->get('agency_host.binding_history');
    $this->container->get('database')->update('agency_host_binding_coverage')->fields([
      'fingerprint' => $history->fingerprint(),
      'epoch' => bin2hex(random_bytes(32)),
    ])->execute();
    [$id] = $this->accepted();
    $user = User::create(['name' => 'provider-delete', 'status' => 1]);
    $user->save();
    $authmap = $this->container->get('externalauth.authmap');
    $authmap->save($user, 'openid_connect.client_portal', 'person-one');
    $client->delete();
    $this->assertFalse($authmap->getUid('person-one', 'openid_connect.client_portal'));
    // The provider config change itself also requires fresh qualification.
    $controller = ParticipantDiscoveryController::create($this->container);
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $this->request('person-one', 'GET')))['reason']);
    $this->assertSame(1, (int) $this->container->get('database')->select('agency_host_binding_history', 'h')
      ->fields('h', ['revoked'])->condition('revoked', 1)->execute()->fetchField());
  }

  /**
   * Failed real authmap uniqueness validation rolls back ledger changes too.
   */
  public function testAuthmapFailureIsAtomicWithHistory(): void {
    [$id] = $this->accepted();
    $first = User::create(['name' => 'first-link', 'status' => 1]);
    $first->save();
    $second = User::create(['name' => 'second-link', 'status' => 1]);
    $second->save();
    $authmap = $this->container->get('externalauth.authmap');
    $authmap->save($first, 'openid_connect.client_portal', 'person-one');
    $authmap->save($second, 'openid_connect.client_portal', 'person-two');
    $database = $this->container->get('database');
    $before = $database->select('agency_host_binding_history', 'h')->fields('h')->orderBy('id')->execute()->fetchAll();
    try {
      $authmap->save($second, 'openid_connect.client_portal', 'person-one');
      $this->fail('Conflicting real authmap save must throw.');
    }
    catch (IntegrityConstraintViolationException) {
      // The actual Externalauth uniqueness guard refused the replacement.
    }
    $this->assertEquals($before, $database->select('agency_host_binding_history', 'h')->fields('h')->orderBy('id')->execute()->fetchAll());
    $this->assertSame((int) $first->id(), (int) $authmap->getUid('person-one', 'openid_connect.client_portal'));
    $controller = ParticipantDiscoveryController::create($this->container);
    $this->assertSame('available', $this->payload($controller->workspace($id, $this->request('person-one', 'GET')))['outcome']);
  }

  /**
   * A failed invalidation cannot erase observed drift after old bytes return.
   */
  public function testPostgresFailedInvalidationRetainsVerificationGuard(): void {
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] !== 'pgsql') {
      $this->markTestSkipped('Independent durable PostgreSQL guard fault proof.');
    }
    [$id] = $this->accepted();
    $user = User::create(['name' => 'failed-invalidation', 'status' => 1]);
    $user->save();
    $provider = 'openid_connect.client_portal';
    $this->container->get('externalauth.authmap')->save($user, $provider, 'person-one');
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    // Fault only this host-owned invalidation INSERT. Guard persistence stays
    // real and available; no Agency storage is inspected or altered.
    $database->query('ALTER TABLE {agency_host_binding_invalid} ADD CONSTRAINT synthetic_refuse_invalidation CHECK (FALSE)');
    try {
      $database->delete('authmap')->condition('uid', $user->id())->execute();
      $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
      (new Authmap($database))->save($user, $provider, 'person-one');
    }
    finally {
      $database->query('ALTER TABLE {agency_host_binding_invalid} DROP CONSTRAINT synthetic_refuse_invalidation');
    }
    $this->assertGreaterThan(0, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $this->container->get('externalauth.authmap')->save($user, $provider, 'person-one');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * A concurrent config save stays unavailable after failed invalidation.
   */
  public function testPostgresFailedConfigInvalidationRetainsVerificationGuard(): void {
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] !== 'pgsql') {
      $this->markTestSkipped('Independent durable PostgreSQL guard fault proof.');
    }
    OpenIDConnectClientEntity::create([
      'id' => 'client_portal',
      'label' => 'Synthetic portal',
      'plugin' => 'generic',
      'status' => TRUE,
      'settings' => [],
    ])->save();
    $history = $this->container->get('agency_host.binding_history');
    $database->update('agency_host_binding_coverage')->fields([
      'fingerprint' => $history->fingerprint(),
      'epoch' => bin2hex(random_bytes(32)),
    ])->execute();
    [$id] = $this->accepted();
    $user = User::create(['name' => 'failed-invalidation', 'status' => 1]);
    $user->save();
    $provider = 'openid_connect.client_portal';
    $this->container->get('externalauth.authmap')->save($user, $provider, 'person-one');
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    // Fault only this host-owned invalidation INSERT. Guard persistence stays
    // real and available; no Agency storage is inspected or altered.
    $key = 'agency_config_drift_' . bin2hex(random_bytes(12));
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    $external = new ConfigFactory(
      new DatabaseStorage(Database::getConnection('default', $key), 'config'),
      new EventDispatcher(), $this->container->get('config.typed'),
    );
    $externalConfig = $external->getEditable('openid_connect.client.client_portal');
    $original = $externalConfig->getRawData();
    $database->query('ALTER TABLE {agency_host_binding_invalid} ADD CONSTRAINT synthetic_refuse_invalidation CHECK (FALSE)');
    try {
      $externalConfig->set('settings.token_endpoint', 'https://changed.example.test/token')->save();
      $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
      $externalConfig->setData($original)->save();
    }
    finally {
      $database->query('ALTER TABLE {agency_host_binding_invalid} DROP CONSTRAINT synthetic_refuse_invalidation');
      Database::removeConnection($key);
    }
    $this->assertGreaterThan(0, (int) $database->select('agency_host_binding_guard', 'g')->countQuery()->execute()->fetchField());
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
    $this->container->get('externalauth.authmap')->save($user, $provider, 'person-one');
    $this->assertSame('identity_unavailable', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Two primary connections serialize covered and raw authmap writes.
   */
  public function testPostgresConcurrentBindingDeletion(): void {
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] !== 'pgsql') {
      $this->markTestSkipped('Real PostgreSQL lock and independent commit proof.');
    }
    [$id] = $this->accepted();
    $user = User::create(['name' => 'concurrent-binding', 'status' => 1]);
    $user->save();
    $authmap = $this->container->get('externalauth.authmap');
    $provider = 'openid_connect.client_portal';
    $authmap->save($user, $provider, 'person-one');
    $key = 'agency_concurrent_' . bin2hex(random_bytes(12));
    Database::addConnectionInfo($key, 'default', $database->getConnectionOptions());
    $other = Database::getConnection('default', $key);
    $other->query("SET lock_timeout = '100ms'");
    $otherHistory = new BindingHistory($other, $this->container->get('settings'),
      $this->container->get('config.factory'), $this->container->get('module_handler'),
      $this->container->get('update.update_hook_registry'), $this->container->get('datetime.time'),
      new PostgresQualificationLatch($other), new DatabaseStorage($other, 'config'));
    $otherAuthmap = new TrackedAuthmap(new Authmap($other), $otherHistory);
    try {
      // The same root lifetime used by Agency retains locks after the nested
      // authorization returns, until the outer transaction has ended.
      $outer = $database->startTransaction();
      $history = $this->container->get('agency_host.binding_history');
      $this->assertTrue($history->authorize('person-one', fn(): bool => TRUE));
      try {
        $otherAuthmap->delete((int) $user->id(), $provider);
        $this->fail('A concurrent decorated delete must wait for root completion.');
      }
      catch (DatabaseExceptionWrapper $error) {
        $this->assertStringContainsString('lock timeout', $error->getMessage());
      }
      try {
        $other->delete('authmap')->condition('uid', $user->id())->execute();
        $this->fail('Raw writes must not be absorbed into a tracked fingerprint.');
      }
      catch (DatabaseExceptionWrapper $error) {
        $this->assertStringContainsString('lock timeout', $error->getMessage());
      }
      unset($outer);
      $otherAuthmap->delete((int) $user->id(), $provider);
      $controller = ParticipantDiscoveryController::create($this->container);
      $this->assertSame('not_found', $this->payload($controller->workspace($id, $this->request('person-one', 'GET')))['reason']);
      $authmap->save($user, $provider, 'person-one');
      $this->assertSame('not_found', $this->payload($controller->workspace($id, $this->request('person-one', 'GET')))['reason']);
    }
    finally {
      unset($outer, $otherAuthmap, $otherHistory);
      Database::removeConnection($key);
    }
  }

  /**
   * A blocked linked account cannot retain authority through the entity cache.
   */
  public function testFreshBlockedBindingDeniesCachedUser(): void {
    [$id] = $this->accepted();
    $linked = User::create(['name' => 'linked-person', 'status' => 1]);
    $linked->save();
    $this->container->get('externalauth.authmap')->save($linked, 'openid_connect.client_portal', 'person-one');
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $cached = User::load($linked->id());
    $this->assertTrue($cached->isActive());
    // Simulate another process changing the account behind this cache.
    $this->container->get('database')->update('users_field_data')
      ->fields(['status' => 0])->condition('uid', $linked->id())->execute();
    $this->assertTrue($cached->isActive());
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * A retained mapping to a missing local account is not an unbound person.
   */
  public function testDanglingAuthmapDeniesParticipant(): void {
    [$id] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $orphan = User::create(['uid' => 987654, 'name' => 'missing-account']);
    $this->container->get('externalauth.authmap')->save($orphan, 'openid_connect.client_portal', 'person-one');
    $this->assertSame(987654, (int) $this->container->get('externalauth.authmap')->getUid('person-one', 'openid_connect.client_portal'));
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $request))['reason']);
  }

  /**
   * Real host transport keeps isolation, fresh deactivation, Stop and retries.
   */
  public function testParticipantJourneyAndFreshAuthority(): void {
    [$id, $token] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    $this->request('person-two', 'GET');
    $missing = $this->payload($controller->workspace('00000000-0000-4000-8000-000000000000', $request));
    $this->assertSame($missing, $this->payload($controller->workspace($id, $request)));
    $this->request('person-one', 'GET');
    $this->people['person-one']['enabled'] = FALSE;
    $this->assertSame($missing, $this->payload($controller->workspace($id, $request)));
    $this->people['person-one']['enabled'] = TRUE;
    $this->outage = TRUE;
    $this->assertSame($this->payload($controller->workspace($id, $request)), $this->payload($controller->workspace('00000000-0000-4000-8000-000000000000', $request)));
    $this->outage = FALSE;
    $request = $this->request('person-one', 'POST', [
      'input' => new \stdClass(),
      'key' => 'stop-host',
      'version' => 0,
    ]);
    $stopped = $this->payload($controller->command($id, 'withdrawRelationship', $request));
    $this->assertSame('committed', $stopped['outcome']);
    $this->assertArrayNotHasKey('workspace', $stopped);
    $this->assertSame($missing, $this->payload($controller->command($id, 'withdrawRelationship', $request)));
    $request = $this->request('person-one', 'GET');
    $summary = $this->payload($controller->summary($id, $request));
    $this->assertSame('withdrawn', $summary['summary']['state']);
    $this->assertArrayNotHasKey('pause_note', $summary['summary']);
    $this->assertCount(2, User::loadMultiple());
    $this->assertArrayNotHasKey('sign_in_state', $this->payload($controller->invitation($token, $request)));
  }

  /**
   * Participant payloads cannot invoke the coordinator proposal path.
   */
  public function testParticipantCannotChooseCoordinatorPath(): void {
    [$id, , $version] = $this->accepted();
    // Even a coincident eligible coordinator must not be selected by payload.
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request = $this->request('person-one', 'POST', [
      'input' => ['target_purpose' => 'Different purpose', 'message' => 'Proposal'],
      'key' => 'proposal',
      'version' => $version,
    ]);
    $result = $this->payload(ParticipantDiscoveryController::create($this->container)->command($id, 'moveForward', $request));
    $this->assertSame(['outcome' => 'refused', 'reason' => 'invalid'], $result);
    $this->assertNull($this->container->get('agency_staff.reads')->forActor($id)['workspace']['proposal']);
  }

  /**
   * Permission and CSRF refusal precede intended-subject existence disclosure.
   */
  public function testPreparationAuthorizationPrecedesReadback(): void {
    $outsider = User::create(['name' => 'outsider', 'status' => 1]);
    $outsider->save();
    $this->container->get('current_user')->setAccount($outsider);
    $controller = CoordinatorDiscoveryController::create($this->container);
    foreach (['person-one', 'missing'] as $subject) {
      $request = $this->request(NULL, 'POST', [
        'input' => $this->input(),
        'intended_subject' => $subject,
        'key' => 'prepare-host',
      ]);
      $this->session($request);
      $this->assertSame([
        'outcome' => 'refused',
        'reason' => 'not_found',
      ], $this->payload($controller->start($request)));
    }
    $this->assertSame(0, $this->lookups);
    $this->container->get('current_user')->setAccount($this->coordinator);
    $request->headers->remove('X-CSRF-Token');
    $this->assertSame('not_found', $this->payload($controller->start($request))['reason']);
    $this->assertSame(0, $this->lookups);
    $this->session($request);
    $this->assertSame('identity_not_found', $this->payload($controller->start($request))['reason']);
    $this->outage = TRUE;
    $this->assertSame('identity_unavailable', $this->payload($controller->start($request))['reason']);
  }

  /**
   * Default contact policy permits a well-formed email and refuses other kinds.
   */
  public function testContactPolicyFailsClosed(): void {
    $identity = $this->container->get('agency_host.identity');
    $this->assertTrue($identity->mayContact(['kind' => 'email', 'value' => 'person@example.test']));
    $this->assertFalse($identity->mayContact(['kind' => 'email', 'value' => 'not-an-email']));
    $this->assertFalse($identity->mayContact(['kind' => 'url', 'value' => 'https://example.test']));
  }

  /**
   * Synthetic switches force identity and required-recording failures.
   */
  public function testSyntheticFailureInjection(): void {
    $controller = CoordinatorDiscoveryController::create($this->container);
    $this->outage = TRUE;
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'identity-failure',
    ]);
    $this->session($request);
    $this->assertSame('identity_unavailable', $this->payload($controller->start($request))['reason']);
    $this->outage = FALSE;
    $this->recordingFailure = TRUE;
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'recording-failure',
    ]);
    $this->session($request);
    $this->assertSame('recording_unavailable', $this->payload($controller->start($request))['reason']);
    $this->assertSame([], $this->container->get('agency_staff.reads')->queue()['contexts']);
  }

  /**
   * The host preserves the wider contract while the default seed stays safe.
   */
  public function testContractBoundaryEnvelope(): void {
    $boundary = $this->seed['contract_boundary_envelope'];
    $policy = $this->config('agency.settings');
    $long_name = str_repeat('Synthetic Agency ', 14);
    $policy->set('display_name', trim($long_name));
    $policy->set('policy.retention_after_close', $boundary['workspace']['retention_period']);
    $policy->set('policy.retained_information_statement', $boundary['workspace']['retained_information']);
    $policy->set('policy.fallback_contact_route', $boundary['workspace']['support_contact']);
    $policy->save();
    $this->assertSame([
      'retained_information' => $boundary['workspace']['retained_information'],
      'retention_period' => 'P2W',
    ], $this->container->get('agency_staff.policy')->retentionFacts());
    $this->assertSame(['kind' => 'chat', 'value' => '@synthetic'], $this->container->get('agency_staff.policy')->supportContact());
    [$id] = $this->accepted();
    $request = $this->request('person-one', 'GET');
    $workspace = $this->payload(ParticipantDiscoveryController::create($this->container)->workspace($id, $request))['workspace'];
    $this->assertSame(trim($long_name), $workspace['agency_name']);
    $this->assertGreaterThan(200, mb_strlen($workspace['agency_name']));
    $this->assertSame('P2W', $workspace['retention_period']);
    $this->assertSame($boundary['workspace']['retained_information'], $workspace['retained_information']);
    $this->assertSame(['kind' => 'chat', 'value' => '@synthetic'], $workspace['support_contact']);
    // Drupal account names cannot originate this long name; transport only.
    $response = $this->container->get('agency_host.transport')->respond(static fn(): array => $boundary, TRUE);
    $this->assertSame($boundary, $this->payload($response));
    $this->assertGreaterThan(200, mb_strlen($boundary['invitation']['owner_name']));
  }

  /**
   * Invitation preparation accepts English and refuses other locales.
   */
  public function testInvitationLanguageIsEnglishOnly(): void {
    $controller = CoordinatorDiscoveryController::create($this->container);
    foreach (['es', 'pt-BR', '', NULL, ['en']] as $language) {
      $input = $this->input();
      $input['language'] = $language;
      if ($language === NULL) {
        unset($input['language']);
      }
      $request = $this->request(NULL, 'POST', [
        'input' => $input,
        'intended_subject' => 'person-one',
        'key' => 'language-refused',
      ]);
      $this->session($request);
      $this->assertSame(['outcome' => 'refused', 'reason' => 'invalid'], $this->payload($controller->start($request)));
    }
    $this->assertSame(0, $this->lookups);
    $this->accepted();
  }

  /**
   * A discarded response can be retried without a second transition.
   */
  public function testLostResponseRetryAndStaleStop(): void {
    [$id, , $version] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request('person-one', 'POST', [
      'input' => ['choice' => 'yes'],
      'key' => 'lost-response',
      'version' => $version,
    ]);
    $first = $this->payload($controller->command($id, 'respond', $request));
    $this->assertSame('committed', $first['outcome']);
    // The client never sees the first response and retries the original intent.
    $retry = $this->payload($controller->command($id, 'respond', $request));
    $this->assertSame($first['receipt'], $retry['receipt']);
    $this->assertSame($first['workspace']['version'], $retry['workspace']['version']);
    $stop = $this->request('person-one', 'POST', [
      'input' => new \stdClass(),
      'key' => 'stale-stop',
      'version' => $version,
    ]);
    $this->assertSame('committed', $this->payload($controller->command($id, 'withdrawRelationship', $stop))['outcome']);
    $read = $this->request('person-one', 'GET');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $read))['reason']);
  }

  /**
   * Failed Stop reports refusal; later coordinator suspension removes access.
   */
  public function testFailedWithdrawalThenSuspension(): void {
    [$id, , $version] = $this->accepted();
    $this->recordingFailure = TRUE;
    $controller = ParticipantDiscoveryController::create($this->container);
    $stop = $this->request('person-one', 'POST', [
      'input' => new \stdClass(),
      'key' => 'failed-stop',
      'version' => $version,
    ]);
    $this->assertSame(['outcome' => 'refused', 'reason' => 'recording_unavailable'], $this->payload($controller->command($id, 'withdrawRelationship', $stop)));
    $this->recordingFailure = FALSE;
    // A historical outage alert cannot turn this refused Stop into a success.
    $this->container->get('current_user')->setAccount($this->coordinator);
    $this->container->get('router.builder')->rebuild();
    $queue_request = $this->request(NULL, 'GET');
    $this->session($queue_request);
    $queue = DiscoveryConsoleController::create($this->container)->queue($queue_request);
    $html = (string) $this->container->get('renderer')->renderRoot($queue);
    $this->assertStringNotContainsString('Stops and revokes still take effect', $html);
    $this->assertStringContainsString('Only a saved result confirms', $html);
    $this->assertStringContainsString('If the outcome is unknown', $html);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $read = $this->request('person-one', 'GET');
    $this->assertSame('available', $this->payload($controller->workspace($id, $read))['outcome']);
    $this->container->get('current_user')->setAccount($this->coordinator);
    $suspend = $this->request(NULL, 'POST', [
      'input' => new \stdClass(),
      'key' => 'suspend-after-failure',
      'version' => $version,
    ]);
    $this->session($suspend);
    $this->assertSame('committed', $this->payload(CoordinatorDiscoveryController::create($this->container)->command($id, 'suspendAccess', $suspend))['outcome']);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $read = $this->request('person-one', 'GET');
    $this->assertSame('not_found', $this->payload($controller->workspace($id, $read))['reason']);
  }

  /**
   * An unconfigured worker cannot drain or report a successful scheduled run.
   */
  public function testEvidenceWorkerRequiresMonitoringConfiguration(): void {
    $this->accepted();
    $outbox = $this->container->get('agency_staff.outbox');
    $before = $outbox->health(150);
    $worker = $this->container->get('agency_host.evidence_worker');
    $report = $worker->run();
    $this->assertFalse($report['successful']);
    $this->assertSame('unconfigured', $report['status']);
    $this->assertSame(0, $report['drained']);
    $this->assertSame($before, $outbox->health(150));
  }

  /**
   * Failed append and alert recovery are never reported as worker success.
   */
  public function testEvidenceWorkerFailureRetryAndBoundedRecovery(): void {
    $this->accepted();
    $this->configureEvidenceWorker();
    $delivered = [];
    $this->container->set('http_client', new Client([
      'handler' => static function (RequestInterface $request) use (&$delivered) {
        $delivered[] = json_decode((string) $request->getBody(), TRUE);
        return Create::promiseFor(new Response(in_array(count($delivered), [1, 3], TRUE) ? 503 : 204));
      },
    ]));
    $worker = $this->container->get('agency_host.evidence_worker');
    $first = $worker->run();
    $this->assertFalse($first['successful']);
    $this->assertSame('degraded', $first['status']);
    $this->assertSame(0, $first['drained']);
    $this->assertSame('failed', $first['alert_delivery']);
    $retry = $worker->run();
    $this->assertFalse($retry['successful']);
    $this->assertSame('sent', $retry['alert_delivery']);
    $this->assertSame('degraded', $delivered[1]['status']);
    Key::create([
      'id' => 'synthetic_worker_chain',
      'label' => 'Synthetic worker chain',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-only-signing-material'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'synthetic_worker_chain')->save();
    $outbox = $this->container->get('agency_staff.outbox');
    // Keep the failed older rows ahead of the new bounded-batch workload.
    // Equal timestamps otherwise let random UUID order postpone recovery.
    $this->now++;
    $transaction = $this->container->get('database')->startTransaction();
    for ($i = 0; $i < 25; $i++) {
      $outbox->recordObservation('not_found', str_repeat('a', 64));
    }
    unset($transaction);
    $recovered = $worker->run();
    $this->assertSame(20, $recovered['drained'], 'One run has a fixed bounded batch.');
    $this->assertFalse($recovered['successful'], 'A failed recovery notification must retry.');
    $this->assertSame('healthy', $recovered['status']);
    $this->assertSame('failed', $recovered['alert_delivery']);
    $this->assertGreaterThan(0, $outbox->health(150)['pending_count']);
    $final = $worker->run();
    $this->assertTrue($final['successful']);
    $this->assertSame('sent', $final['alert_delivery']);
    $this->assertSame('recovered', $delivered[3]['status']);
    $this->assertSame(0, $outbox->health(150)['pending_count']);
    $this->assertCount(4, $delivered);
    $this->assertTrue($worker->run()['successful']);
    $this->assertCount(4, $delivered, 'Unchanged health does not send another transition.');
  }

  /**
   * A received transition with a lost response cannot suppress its opposite.
   */
  #[DataProvider('unacknowledgedEvidenceTransitions')]
  public function testEvidenceAlertUnacknowledgedTransition(int $lostResponse): void {
    $this->accepted();
    $this->configureEvidenceWorker();
    $outbox = $this->container->get('agency_staff.outbox');
    $outbox->drain();
    $this->assertTrue($outbox->health(150)['degraded']);
    $delivered = [];
    $this->container->set('http_client', new Client([
      'handler' => static function (RequestInterface $request) use (&$delivered, $lostResponse) {
        // The receiver has applied the transition before its response is lost.
        $delivered[] = json_decode((string) $request->getBody(), TRUE)['status'];
        if (count($delivered) === $lostResponse) {
          throw new \RuntimeException('Synthetic response lost after receiver commit.');
        }
        return Create::promiseFor(new Response(204));
      },
    ]));
    $monitor = $this->container->get('agency_host.evidence_health_monitor');
    $this->assertSame($lostResponse === 1 ? 'failed' : 'sent', $monitor->check()['alert_delivery']);
    Key::create([
      'id' => 'synthetic_transition_chain',
      'label' => 'Synthetic transition chain',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-only-signing-material'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'synthetic_transition_chain')->save();
    $outbox->drain(1000);
    $this->assertFalse($outbox->health(150)['degraded']);
    $this->assertSame($lostResponse === 2 ? 'failed' : 'sent', $monitor->check()['alert_delivery']);
    if ($lostResponse === 2) {
      // A new delivery failure follows the unacknowledged recovery.
      $this->config('audit_chain.settings')->set('hash_key', '')->save();
      $transaction = $this->container->get('database')->startTransaction();
      $outbox->recordObservation('not_found', str_repeat('b', 64));
      unset($transaction);
      $outbox->drain();
      $this->assertTrue($outbox->health(150)['degraded']);
      $this->assertSame('sent', $monitor->check()['alert_delivery']);
    }
    $this->assertSame($lostResponse === 1 ? ['degraded', 'recovered'] : ['degraded', 'recovered', 'degraded'], $delivered);
    $this->assertSame('unchanged', $monitor->check()['alert_delivery']);
  }

  /**
   * Covers recovery after uncertain degradation and its inverse.
   */
  public static function unacknowledgedEvidenceTransitions(): array {
    return ['lost degradation response' => [1], 'lost recovery response' => [2]];
  }

  /**
   * Another PostgreSQL session excludes a worker without touching the queue.
   */
  public function testEvidenceWorkerSerializesIndependentConnections(): void {
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] !== 'pgsql') {
      $this->markTestSkipped('Requires independent PostgreSQL advisory locks.');
    }
    $this->accepted();
    $this->configureEvidenceWorker();
    $outbox = $this->container->get('agency_staff.outbox');
    $before = $outbox->health(150);
    Database::addConnectionInfo('synthetic_evidence_worker', 'default', $database->getConnectionOptions());
    $other = Database::getConnection('default', 'synthetic_evidence_worker');
    $parameters = [
      ':namespace' => 'agency_host.evidence_worker',
      ':table' => $database->prefixTables('{agency_staff_outbox}'),
    ];
    $other->query('SELECT pg_advisory_lock(hashtext(:namespace), hashtext(:table))', $parameters);
    try {
      $worker = $this->container->get('agency_host.evidence_worker');
      $report = $worker->run();
      $this->assertFalse($report['successful']);
      $this->assertSame('busy', $report['status']);
      $this->assertSame(0, $report['drained']);
      $this->assertSame($before, $outbox->health(150));
      $other->query('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:table))', $parameters);
      $this->assertSame('degraded', $worker->run()['status']);
      $locked = $other->query('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:table))', $parameters)->fetchField();
      $this->assertContains($locked, [TRUE, 1, '1', 't'], 'A failed drain releases only its owned session lock.');
    }
    finally {
      $other->query('SELECT pg_advisory_unlock_all()');
      Database::removeConnection('synthetic_evidence_worker');
    }
  }

  /**
   * A claim failure before attempt recording cannot masquerade as healthy work.
   */
  public function testEvidenceWorkerDetectsUnrecordedDrainFailure(): void {
    $this->accepted();
    $this->configureEvidenceWorker();
    $database = $this->container->get('database');
    $connection = $this->createMock(Connection::class);
    $connection->method('inTransaction')->willReturnCallback($database->inTransaction(...));
    $connection->method('startTransaction')->willReturnCallback($database->startTransaction(...));
    $first = TRUE;
    $connection->method('select')->willReturnCallback(static function ($table, $alias = NULL, array $options = []) use ($database, &$first) {
      if ($first) {
        $first = FALSE;
        throw new \RuntimeException('Synthetic claim failure before attempt recording.');
      }
      return $database->select($table, $alias, $options);
    });
    $outbox = new EvidenceOutbox($connection, $this->container->get('audit_chain.logger'),
      $this->container->get('uuid'), $this->container->get('datetime.time'));
    $worker = new EvidenceWorker($this->container->get('agency_host.evidence_lock'), $outbox,
      $this->monitor($outbox, '150'), $this->container->get('settings'));
    $report = $worker->run();
    $health = $outbox->health(150);
    $this->assertFalse($health['degraded'], 'Fresh rows have no age or recorded error yet.');
    $this->assertSame(0, $health['pending_attempts']);
    $this->assertGreaterThan(0, $health['pending_count']);
    $this->assertFalse($report['successful'], 'Silent drain failure is not a successful scheduled run.');
    $this->assertSame('incomplete', $report['status']);
  }

  /**
   * Every monitor caller shares exclusion, including the ordinary cron hook.
   */
  public function testEvidenceWorkerMonitorLockAndFreshTransition(): void {
    $database = $this->container->get('database');
    if ($database->getConnectionOptions()['driver'] !== 'pgsql') {
      $this->markTestSkipped('Requires independent PostgreSQL advisory locks.');
    }
    $this->accepted();
    $this->configureEvidenceWorker();
    $outbox = $this->container->get('agency_staff.outbox');
    $outbox->drain();
    $delivered = [];
    $this->container->set('http_client', new Client([
      'handler' => static function (RequestInterface $request) use (&$delivered) {
        $delivered[] = json_decode((string) $request->getBody(), TRUE);
        return Create::promiseFor(new Response(204));
      },
    ]));
    $monitor = $this->container->get('agency_host.evidence_health_monitor');
    Database::addConnectionInfo('synthetic_evidence_monitor', 'default', $database->getConnectionOptions());
    $other = Database::getConnection('default', 'synthetic_evidence_monitor');
    $parameters = [
      ':namespace' => 'agency_host.evidence_monitor',
      ':table' => $database->prefixTables('{agency_staff_outbox}'),
    ];
    $other->query('SELECT pg_advisory_lock(hashtext(:namespace), hashtext(:table))', $parameters);
    try {
      $this->assertSame('busy', $monitor->check()['status']);
      $this->assertCount(0, $delivered, 'A competing cron caller must not send a transition.');
      $other->query('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:table))', $parameters);
      $state = $this->container->get('state');
      $state->set('agency_host.evidence_alert_status', 'healthy');
      $this->container->get('keyvalue')->get('state')->set('agency_host.evidence_alert_status', 'degraded');
      $this->assertSame('healthy', $state->get('agency_host.evidence_alert_status'), 'Retain a stale request cache as the negative control.');
      $this->assertSame('unchanged', $monitor->check()['alert_delivery']);
      $this->assertCount(0, $delivered, 'Fresh stored state is read inside the lock.');
      $locked = $other->query('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:table))', $parameters)->fetchField();
      $this->assertContains($locked, [TRUE, 1, '1', 't']);
    }
    finally {
      $other->query('SELECT pg_advisory_unlock_all()');
      Database::removeConnection('synthetic_evidence_monitor');
    }
  }

  /**
   * Drush discovers the real command and returns truthful JSON and exit codes.
   */
  public function testEvidenceWorkerCommandExitAndDiscovery(): void {
    $manager = new ServiceManager($this->classLoader, new DrushConfig(), new NullLogger());
    $path = DRUPAL_ROOT . '/' . $this->container->get('module_handler')->getModule('agency_host')->getPath() . '/src/Drush';
    $class = AgencyEvidenceCommands::class;
    $this->assertContains($class, $manager->discoverModuleCommands([$path], 'Drupal\\agency_host\\Drush'));
    $handler = $class::create($this->container);
    $output = new BufferedOutput();
    $handler->setOutput($output);
    $input = new ArrayInput([]);
    $handler->setInput($input);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handler);
    $commands = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'agency:evidence-run'));
    $this->assertCount(1, $commands);
    $this->assertSame(1, $commands[0]->run($input, $output));
    $report = json_decode($output->fetch(), TRUE, 16, JSON_THROW_ON_ERROR);
    $this->assertSame([
      'successful' => FALSE,
      'status' => 'unconfigured',
      'drained' => 0,
      'alert_delivery' => 'not_checked',
    ], $report);
    $this->configureEvidenceWorker();
    // A new command receives the newly configured worker, as a fresh CLI does.
    $worker = new EvidenceWorker($this->container->get('agency_host.evidence_lock'),
      $this->container->get('agency_staff.outbox'), $this->monitor($this->container->get('agency_staff.outbox'), '150'), $this->container->get('settings'));
    $handler = new $class($worker);
    $handler->setOutput($output);
    $handler->setInput($input);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handler);
    $commands = array_values(array_filter($commands, static fn($command): bool => $command->getName() === 'agency:evidence-run'));
    $this->assertSame(0, $commands[0]->run($input, $output));
    $report = json_decode($output->fetch(), TRUE, 16, JSON_THROW_ON_ERROR);
    $this->assertTrue($report['successful']);
    $this->assertSame('healthy', $report['status']);
    $this->assertSame(['successful', 'status', 'drained', 'alert_delivery'], array_keys($report));
  }

  /**
   * Supplies only synthetic monitoring configuration for worker controls.
   */
  private function configureEvidenceWorker(): void {
    $settings = new Settings(array_replace(Settings::getAll(), [
      'agency_host_evidence_alert' => [
        'url' => 'https://alerts.example.test/agency',
        'token' => 'synthetic-worker-receiver',
        'after_seconds' => '150',
      ],
    ]));
    $this->container->set('settings', $settings);
  }

  /**
   * Public health drives retryable degraded and recovery alert delivery.
   */
  public function testEvidenceHealthAlertDeliveryAndRecovery(): void {
    $this->accepted();
    $outbox = $this->container->get('agency_staff.outbox');
    $outbox->drain();
    $this->assertTrue($outbox->health(300)['degraded']);
    $settings = new Settings(array_replace(Settings::getAll(), [
      'agency_host_evidence_alert' => [
        'url' => 'https://alerts.example.test/agency',
        'after_seconds' => '300',
      ],
    ]));
    $this->container->set('settings', $settings);
    $delivered = [];
    $this->container->set('http_client', new Client([
      'handler' => static function (RequestInterface $request) use (&$delivered) {
        $delivered[] = json_decode((string) $request->getBody(), TRUE);
        return Create::promiseFor(new Response(count($delivered) === 1 ? 503 : 204));
      },
    ]));
    $monitor = $this->container->get('agency_host.evidence_health_monitor');
    $this->assertSame('failed', $monitor->check()['alert_delivery']);
    $this->assertSame('sent', $monitor->check()['alert_delivery']);
    $this->assertSame('unchanged', $monitor->check()['alert_delivery']);
    $this->assertCount(2, $delivered);
    $this->assertSame('degraded', $delivered[1]['status']);
    $this->assertSame(300, $delivered[1]['threshold_seconds']);
    $this->assertSame([
      'event', 'status', 'threshold_seconds', 'pending_count',
      'oldest_pending_age_seconds', 'pending_attempts',
    ], array_keys($delivered[1]));
    Key::create([
      'id' => 'synthetic_chain',
      'label' => 'Synthetic chain',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'synthetic-only-signing-material'],
    ])->save();
    $this->config('audit_chain.settings')->set('hash_key', 'synthetic_chain')->save();
    $outbox->drain(1000);
    $this->assertSame(0, $outbox->health(300)['pending_count']);
    $recovery = $monitor->check();
    $this->assertSame('healthy', $recovery['status']);
    $this->assertSame('sent', $recovery['alert_delivery']);
    $this->assertSame('recovered', $delivered[2]['status']);
    $this->assertSame('unchanged', $monitor->check()['alert_delivery']);
    $recoveredHealth = $outbox->health(300);
    $this->assertFalse($recoveredHealth['degraded']);
    $this->assertSame('evidence_delivery_failed', $recoveredHealth['last_error']);
    $this->assertNotNull($recoveredHealth['last_error_at']);
    foreach (['0', '-1', '1.5', ' 300', '3e2', str_repeat('9', 30)] as $invalidThreshold) {
      $invalid = $this->monitor($outbox, $invalidThreshold)->check();
      $this->assertSame('unavailable', $invalid['status']);
      $this->assertNull($invalid['health']);
    }
    foreach ([NULL, FALSE, ''] as $unset) {
      $disabled = $this->monitor($outbox, $unset)->check();
      $this->assertSame('disabled', $disabled['status']);
      $this->assertSame('disabled', $disabled['alert_delivery']);
    }
    $connection = $this->createMock(Connection::class);
    $connection->method('select')->willThrowException(new \RuntimeException('Synthetic unavailable connection.'));
    $unavailableOutbox = new EvidenceOutbox($connection,
      $this->container->get('audit_chain.logger'), $this->container->get('uuid'), $this->container->get('datetime.time'));
    $this->assertSame('unavailable', $this->monitor($unavailableOutbox, '300')->check()['status']);
    $this->assertFalse($outbox->health(300)['degraded']);
  }

  /**
   * Age alone alerts before any delivery attempt has failed.
   */
  public function testEvidenceAgeOnlyOverdue(): void {
    $this->accepted();
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(time() + 301);
    $outbox = new EvidenceOutbox($this->container->get('database'),
      $this->container->get('audit_chain.logger'), $this->container->get('uuid'), $clock);
    $health = $outbox->health(300);
    $this->assertSame(0, $health['pending_attempts']);
    $this->assertNull($health['last_error']);
    $this->assertTrue($health['oldest_undelivered_alert']);
    $this->assertTrue($health['degraded']);
    $this->assertSame('degraded', $this->monitor($outbox, '300')->check()['status']);
  }

  /**
   * Uses the real public outbox port with explicit runtime-only threshold.
   */
  private function monitor(EvidenceOutbox $outbox, mixed $threshold): EvidenceHealthMonitor {
    return new EvidenceHealthMonitor($outbox,
      new Settings([
        'agency_host_evidence_alert' => [
          'url' => 'https://alerts.example.test/agency',
          'after_seconds' => $threshold,
          'token' => 'synthetic-worker-receiver',
        ],
      ]), $this->container->get('state'), $this->container->get('http_client'),
      $this->container->get('logger.channel.agency_host'), $this->container->get('agency_host.evidence_lock'));
  }

  /**
   * One intent key cannot replay preparation for a different verified subject.
   */
  public function testPreparationIntentIncludesVerifiedSubject(): void {
    $this->people['person-two']['email'] = $this->people['person-one']['email'];
    $controller = CoordinatorDiscoveryController::create($this->container);
    $input = $this->input();
    $first = NULL;
    foreach (['person-one', 'person-two', 'person-one'] as $index => $subject) {
      $request = $this->request(NULL, 'POST', [
        'input' => $input,
        'intended_subject' => $subject,
        'key' => 'same-preparation-intent',
      ]);
      $this->session($request);
      $result = $this->payload($controller->start($request));
      if ($index === 1) {
        // Agency currently classifies a changed intent fingerprint as invalid.
        $this->assertSame(['outcome' => 'refused', 'reason' => 'invalid'], $result);
      }
      elseif ($first === NULL) {
        $this->assertSame('committed', $result['outcome']);
        $first = $result['receipt'];
      }
      else {
        $this->assertSame('committed', $result['outcome']);
        $this->assertSame($first, $result['receipt']);
      }
    }
  }

  /**
   * Workspace polling cannot spend the separate invitation acceptance budget.
   */
  public function testReadTrafficDoesNotExhaustAcceptanceBudget(): void {
    [$id, $token] = $this->accepted();
    $controller = ParticipantDiscoveryController::create($this->container);
    for ($i = 0; $i < 20; $i++) {
      $request = $this->request('person-one', 'GET');
      $this->assertSame('available', $this->payload($controller->workspace($id, $request))['outcome']);
    }
    for ($i = 0; $i < 19; $i++) {
      $request = $this->request('person-one', 'POST', ['key' => 'accept-host']);
      $this->assertSame('committed', $this->payload($controller->accept($token, $request))['outcome']);
    }
    $this->assertSame(429, $controller->accept($token, $request)->getStatusCode());
  }

  /**
   * Real OAuth and routing enforce BFF, method and cookie boundaries.
   */
  public function testHttpAuthenticationAndPrivateErrors(): void {
    foreach (['oauth2_token', 'file', 'path_alias'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['simple_oauth']);
    $this->setSetting('simple_oauth.key_permissions_check', FALSE);
    mkdir($this->siteDirectory . '/keys', 0775);
    file_put_contents($this->siteDirectory . '/keys/public.key', $this->publicKey);
    file_put_contents($this->siteDirectory . '/keys/private.key', $this->privateKey);
    chmod($this->siteDirectory . '/keys/private.key', 0600);
    $this->config('simple_oauth.settings')
      ->set('public_key', $this->siteDirectory . '/keys/public.key')
      ->set('private_key', $this->siteDirectory . '/keys/private.key')->save();
    Role::create([
      'id' => 'bff',
      'label' => 'Synthetic BFF',
      'permissions' => ['use agency discovery participant channel'],
    ])->save();
    $service = User::create(['name' => 'bff', 'status' => 1, 'roles' => ['bff']]);
    $service->save();
    $scope = Oauth2Scope::create([
      'name' => 'discovery',
      'description' => 'Synthetic Discovery scope',
      'grant_types' => ['client_credentials' => ['status' => TRUE, 'description' => 'Synthetic']],
      'umbrella' => FALSE,
      'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
      'granularity_configuration' => ['role' => 'bff'],
    ]);
    $scope->save();
    Consumer::create([
      'client_id' => 'synthetic-bff',
      'label' => 'Synthetic BFF',
      'grant_types' => ['client_credentials'],
      'scopes' => [$scope->id()],
      'secret' => 'fixture-secret',
      'user_id' => $service->id(),
      'confidential' => TRUE,
      'is_default' => TRUE,
    ])->save();
    $this->config('agency_host.settings')->set('enabled', TRUE)->set('bff_client_id', 'synthetic-bff')->save();
    $kernel = $this->container->get('http_kernel');
    $mint = Request::create('/oauth/token', 'POST', [
      'grant_type' => 'client_credentials',
      'client_id' => 'synthetic-bff',
      'client_secret' => 'fixture-secret',
      'scope' => 'discovery',
    ]);
    $minted = $kernel->handle($mint);
    $this->assertSame(200, $minted->getStatusCode(), $minted->getContent());
    $token = json_decode($minted->getContent(), TRUE)['access_token'];
    $request = $this->request('person-one', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $response = $kernel->handle($request);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame('available', $this->payload($response)['outcome']);
    // A response-layer outage can happen after a controller has returned.
    $this->container->get('event_dispatcher')->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event): void {
      $request = $event->getRequest();
      if ($request->headers->has('X-Synthetic-Failure') && !$request->attributes->get('_synthetic_failed')) {
        $request->attributes->set('_synthetic_failed', TRUE);
        throw new ServiceUnavailableHttpException();
      }
    });
    // Capture the intentionally generated core exception log outside stderr.
    $errorLog = tempnam(sys_get_temp_dir(), 'agency-host-failure-');
    $previousLog = ini_set('error_log', $errorLog);
    try {
      foreach (['GET', 'POST'] as $method) {
        $failed_request = $this->request('person-one', $method);
        $failed_request->headers->set('Authorization', 'Bearer ' . $token);
        $failed_request->headers->set('X-Synthetic-Failure', 'yes');
        $failed_response = $kernel->handle($failed_request);
        $this->assertSame(503, $failed_response->getStatusCode());
        $this->assertSame($method === 'POST'
          ? ['outcome' => 'uncertain']
          : ['outcome' => 'refused', 'reason' => 'unavailable'], $this->payload($failed_response));
      }
    }
    finally {
      ini_set('error_log', $previousLog);
    }
    $this->assertStringContainsString(ServiceUnavailableHttpException::class, (string) file_get_contents($errorLog));
    unlink($errorLog);
    $request->headers->remove(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER);
    $response = $kernel->handle($request);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('signed_out', $this->payload($response)['reason']);
    foreach ([
      ['/api/agency/discovery/workspaces', 'GET'],
      ['/api/agency/discovery/workspaces/00000000-0000-4000-8000-000000000000/respond', 'GET'],
      ['/admin/agency/discovery/start', 'POST'],
      ['/api/agency/discovery/invitations/not-a-token', 'GET'],
    ] as [$path, $method]) {
      $request = Request::create($path, $method);
      $response = $kernel->handle($request);
      $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
      $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
      $this->assertSame('application/json', $response->headers->get('Content-Type'));
      $this->assertSame('refused', json_decode($response->getContent(), TRUE)['outcome']);
      $this->assertArrayNotHasKey('message', json_decode($response->getContent(), TRUE));
    }
  }

  /**
   * Language negotiation must not bypass protection on routing denials.
   */
  public function testLocalizedRoutingFailuresRemainPrivate(): void {
    $this->container->get('module_installer')->install(['language'], FALSE);
    $this->container = \Drupal::getContainer();
    foreach (['es', 'pt-br'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $this->assertTrue($this->container->has('path_processor_language'));
    $this->config('language.negotiation')->set('url.prefixes', [
      'en' => '',
      'es' => 'es',
      'pt-br' => 'pt-br',
    ])->save();
    $this->container->get('router.builder')->rebuild();
    $kernel = $this->container->get('http_kernel');
    $this->container->get('event_dispatcher')->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
      if ($event->getRequest()->headers->has('X-Synthetic-Early-Denial')) {
        $event->setResponse(new HttpResponse('<html>Private denial</html>', 403, ['Content-Type' => 'text/html']));
      }
    }, 512);
    foreach (['es', 'pt-br'] as $langcode) {
      foreach ([
        ['/api/agency/discovery/workspaces', 'GET', 403],
        ['/api/agency/discovery/workspaces', 'POST', 405],
        ['/api/agency/discovery/invitations/invalid', 'GET', 404],
        ['/admin/agency/discovery/start', 'POST', 403],
        ['/admin/agency/discovery/console', 'GET', 403],
        ['/admin/agency/discovery/console/invalid', 'GET', 404],
      ] as [$path, $method, $status]) {
        $request = Request::create('/' . $langcode . $path, $method);
        // Start each request without the setup coordinator or prior context.
        $this->container->get('current_user')->setAccount(new AnonymousUserSession());
        $this->container->get('router')->getContext()->fromRequest($request);
        $response = $kernel->handle($request);
        $this->assertSame($status, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        if (str_starts_with($path, '/admin/agency/discovery/console')) {
          $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        }
        else {
          $this->assertSame('application/json', $response->headers->get('Content-Type'));
          $this->assertSame([
            'outcome' => 'refused',
            'reason' => $response->getStatusCode() === 405 ? 'method_not_allowed' : 'not_found',
          ], json_decode($response->getContent(), TRUE));
        }
      }
      foreach (['/api/agency/discovery/workspaces', '/admin/agency/discovery/console'] as $path) {
        $request = Request::create('/' . $langcode . $path);
        $request->headers->set('X-Synthetic-Early-Denial', 'yes');
        $response = $kernel->handle($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        if (str_starts_with($path, '/api/')) {
          $this->assertSame(['outcome' => 'refused', 'reason' => 'not_found'], json_decode($response->getContent(), TRUE));
        }
        else {
          $this->assertSame('<html>Private denial</html>', $response->getContent());
        }
      }
    }
  }

  /**
   * Prepares, issues and accepts using the real host identity and domain ports.
   */
  private function accepted(): array {
    $request = $this->request(NULL, 'POST', [
      'input' => $this->input(),
      'intended_subject' => 'person-one',
      'key' => 'start-host',
    ]);
    $this->session($request);
    $start = $this->payload(CoordinatorDiscoveryController::create($this->container)->start($request));
    $this->assertSame('committed', $start['outcome'], json_encode($start));
    $id = $start['receipt']['relationship_id'];
    $issued = $this->container->get('agency_staff.invitations')->issue($id, 'issue-host', 1);
    $this->assertSame('committed', $issued['outcome'], json_encode($issued));
    $token = $issued['invitation']['token'];
    $controller = ParticipantDiscoveryController::create($this->container);
    $request = $this->request(NULL, 'GET');
    $inspection = $this->payload($controller->invitation($token, $request));
    $this->assertArrayNotHasKey('sign_in_state', $inspection);
    $this->assertArrayNotHasKey('owner_contact', $inspection);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $request = $this->request('person-one', 'POST', ['key' => 'accept-host']);
    $accepted = $this->payload($controller->accept($token, $request));
    $this->assertSame('committed', $accepted['outcome'], json_encode($accepted));
    $request = $this->request('person-one', 'GET');
    $reopen = $this->payload($controller->invitation($token, $request));
    $this->assertSame('workspace', $reopen['status']);
    $this->assertArrayNotHasKey('sign_in_state', $reopen);
    $request = $this->request('person-two', 'GET');
    $used = $this->payload($controller->invitation($token, $request));
    $this->assertSame('already_used', $used['status']);
    $this->assertArrayNotHasKey('sign_in_state', $used);
    $closed = $this->payload($controller->invitation(str_repeat('0', 64), $request));
    $this->assertSame('closed', $closed['status']);
    $this->assertArrayNotHasKey('sign_in_state', $closed);
    $this->request('person-one', 'GET');
    return [$id, $token, $accepted['workspace']['version']];
  }

  /**
   * Submits the explicit currently rendered button for handler-level tests.
   */
  private function submitConsole($handler, array &$form, FormState $state): void {
    $name = $state->getTriggeringElement()['#name'] ?? '';
    $find = function (array $element) use (&$find, $name): ?array {
      if (($element['#name'] ?? '') === $name) {
        return $element;
      }
      foreach ($element as $key => $child) {
        if (is_string($key) && !str_starts_with($key, '#') && is_array($child) && ($found = $find($child))) {
          return $found;
        }
      }
      return NULL;
    };
    $button = $find($form);
    $this->assertNotNull($button, 'The requested action must actually be rendered.');
    $state->setTriggeringElement($button);
    $this->container->get('request_stack')->getCurrentRequest()->request->set($name, (string) $button['#value']);
    $handler->submitForm($form, $state);
  }

  /**
   * Creates a synthetic HTTP request, with a stubbed validated JWT if present.
   */
  private function request(?string $subject, string $method, array $body = []): Request {
    $request = Request::create('https://example.test/api/agency/discovery/workspaces', $method, server: [
      'CONTENT_TYPE' => 'application/json',
    ], content: json_encode($body));
    $request->setSession(new Session(new MockArraySessionStorage()));
    if ($subject !== NULL) {
      $request->headers->set(AgencyIdentityProvider::PARTICIPANT_TOKEN_HEADER, $this->realProofSigner !== NULL ? ($this->realProofSigner)($subject) : 'fixture.' . base64_encode(json_encode([
        'iss' => self::ISSUER,
        'sub' => $subject,
      ])) . '.fixture');
    }
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * Sets the real cookie/CSRF prerequisites independently of account authority.
   */
  private function session(Request $request): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $options = $this->container->get('session_configuration')->getOptions($request);
    $request->cookies->set($options['name'], 'synthetic-session');
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $request->headers->set('Origin', 'https://example.test');
  }

  /**
   * Asserts privacy on every exercised result before decoding its payload.
   */
  private function payload(JsonResponse $response): array {
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Synthetic preparation input does not ratify tenant policy.
   */
  private function input(): array {
    return [
      'display_name' => 'Example Person',
      'contact' => ['kind' => 'email', 'value' => 'person-one@example.test'],
      'language' => 'en',
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
