<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_commercial\InstructionReleasePolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ports InstructionReleaseTest fail-closed cases without HTTP or storage.
 *
 * @group agency
 */
#[Group('agency')]
final class InstructionReleasePolicyTest extends TestCase {

  private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 4);
    spl_autoload_register(static function (string $class) use ($root): void {
      $prefix = 'Drupal\\agency_commercial\\';
      if (!str_starts_with($class, $prefix)) {
        return;
      }
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $file = $root . '/modules/agency_commercial/src/' . $relative . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * Disabled, plain-HTTP or mismatched enrollment refuses without a send.
   */
  public function testDisabledOrUnsafeSettingsRefuseWithoutRequests(): void {
    $policy = new InstructionReleasePolicy();
    foreach ([
      ['enrolled' => FALSE],
      ['https' => FALSE],
      ['environment_match' => FALSE],
    ] as $override) {
      try {
        $policy->release($this->facts($override));
        $this->fail('Unsafe enrollment must not send.');
      }
      catch (\LogicException $e) {
        $this->assertSame('not_configured', $e->getMessage());
      }
    }
  }

  /**
   * Machine callers and missing permission never send.
   */
  public function testPermissionIsRequired(): void {
    $policy = new InstructionReleasePolicy();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('forbidden');
    $policy->release($this->facts(['permitted' => FALSE]));
  }

  /**
   * List offers release only from held, uncertain, failed, or stale sending.
   */
  public function testListOffersReleaseOnlyWhenItCanProceed(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertTrue($policy->releasable(['state' => 'held']));
    $this->assertTrue($policy->releasable(['state' => 'failed']));
    $this->assertFalse($policy->releasable(['state' => 'accepted']));
    $this->assertFalse($policy->releasable(['state' => 'sending']));
    $this->assertTrue($policy->releasable([
      'state' => 'sending',
      'stale_sending' => TRUE,
    ]));
  }

  /**
   * A missing form token is forbidden, not a payment outcome.
   */
  public function testFormRequiresCsrfToken(): void {
    $policy = new InstructionReleasePolicy();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('forbidden');
    $policy->release($this->facts(['csrf' => FALSE]));
  }

  /**
   * A 200 with a valid obligation is accepted.
   */
  public function testHeldInstructionIsAccepted(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertSame(
      ['state' => 'accepted', 'reason' => NULL],
      $policy->release($this->facts()),
    );
  }

  /**
   * Known 409 conflict codes are terminal refusals.
   */
  public function testDefinitiveRefusalIsTerminal(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertSame(
      ['state' => 'refused', 'reason' => 'ineligible'],
      $policy->release($this->facts([
        'http_status' => 409,
        'error' => 'ineligible',
        'obligation_valid' => FALSE,
      ])),
    );
  }

  /**
   * A 404 stays failed so a later retry with the same key can accept.
   */
  public function testNotFoundOrDeniedRetriesToAcceptance(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertSame(
      ['state' => 'failed', 'reason' => 'not_found_or_denied'],
      $policy->release($this->facts([
        'http_status' => 404,
        'obligation_valid' => FALSE,
      ])),
    );
  }

  /**
   * A 400 is a fault in Drupal's fixed request.
   */
  public function testBadRequestIsRefusedAsFault(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertSame(
      ['state' => 'refused', 'reason' => 'fault_invalid_request'],
      $policy->release($this->facts([
        'http_status' => 400,
        'obligation_valid' => FALSE,
      ])),
    );
  }

  /**
   * Timeouts and unknown 409 codes stay uncertain with the same command key.
   */
  public function testUncertainOutcomesRetryWithTheSameKey(): void {
    $policy = new InstructionReleasePolicy();
    $this->assertSame(
      ['state' => 'uncertain', 'reason' => 'http_503'],
      $policy->release($this->facts([
        'http_status' => 503,
        'obligation_valid' => FALSE,
      ])),
    );
    $this->assertSame(
      ['state' => 'uncertain', 'reason' => 'http_409'],
      $policy->release($this->facts([
        'http_status' => 409,
        'error' => 'other',
        'obligation_valid' => FALSE,
      ])),
    );
  }

  /**
   * A second click while sending and not stale is in_progress.
   */
  public function testConcurrentReleaseIsRefusedWhileInFlight(): void {
    $policy = new InstructionReleasePolicy();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('in_progress');
    $policy->release($this->facts(['state' => 'sending']));
  }

  /**
   * Audit failure before persist does not send; after send is unrecorded.
   */
  public function testAuditFailureRollsBackTheTransition(): void {
    $policy = new InstructionReleasePolicy();
    try {
      $policy->release($this->facts(['claim_audit' => FALSE]));
      $this->fail('Missing claim audit must not send.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('claim_unrecorded', $e->getMessage());
    }
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('outcome_unrecorded');
    $policy->release($this->facts(['outcome_audit' => FALSE]));
  }

  /**
   * Return values never include enrollment secrets or bearer tokens.
   */
  public function testCredentialsNeverLeak(): void {
    $policy = new InstructionReleasePolicy();
    $encoded = (string) json_encode($policy->release($this->facts()));
    $this->assertStringNotContainsString('client_secret', $encoded);
    $this->assertStringNotContainsString('access_token', $encoded);
  }

  /**
   * Enrolled held instruction with a valid obligation.
   *
   * @param array<string, mixed> $override
   *   Fact replacements.
   *
   * @return array<string, mixed>
   *   Adapter facts.
   */
  private function facts(array $override = []): array {
    return $override + [
      'enrolled' => TRUE,
      'https' => TRUE,
      'environment_match' => TRUE,
      'permitted' => TRUE,
      'csrf' => TRUE,
      'machine_principal' => FALSE,
      'instruction_id' => self::ID,
      'profile_match' => TRUE,
      'digest_match' => TRUE,
      'state' => 'held',
      'stale_sending' => FALSE,
      'claim_audit' => TRUE,
      'outcome_audit' => TRUE,
      'http_status' => 200,
      'obligation_valid' => TRUE,
    ];
  }

}
