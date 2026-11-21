<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\OperatingActions;
use Drupal\agency_commercial\OperatingRuns;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies operating-action input keys without contrib tool definitions.
 *
 * @group agency
 */
#[Group('agency')]
final class OperatingToolDefinitionsTest extends TestCase {

  /**
   * JSON object keys that must never appear on a public refusal body.
   */
  private const PAYLOAD_KEYS = [
    'email',
    'phone',
    'payload',
    'draft',
    'lease',
  ];

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
   * Each operating seat publishes the same four typed action inputs.
   */
  public function testTypedActionsExcludeAuthorityInputs(): void {
    $expected = [
      'claim' => [],
      'context' => ['run_id', 'lease'],
      'submit' => ['run_id', 'lease', 'draft'],
      'fail' => ['run_id', 'lease', 'failure_code'],
    ];
    foreach (['steward', 'intake', 'delivery', 'renewal'] as $seat) {
      $this->assertSame(
        array_keys($expected),
        OperatingRuns::forSeat($seat),
      );
      foreach ($expected as $action => $keys) {
        $this->assertSame($keys, OperatingActions::keys($action));
        $values = array_fill_keys($keys, 'fixture');
        if ($action === 'fail') {
          $values['failure_code'] = 'timeout';
        }
        if ($action === 'submit') {
          $values['draft'] = ['summary' => 'fixture'];
        }
        $this->assertSame($values, OperatingActions::payload($action, $values));
      }
    }
  }

  /**
   * Unknown keys fail closed without dumping caller payload.
   */
  public function testUnknownInputIsRejectedWithoutPayload(): void {
    $this->assertUnknownInput('claim', ['seat' => 'steward']);
    $this->assertUnknownInput('context', [
      'run_id' => '1',
      'lease' => 'lease',
      'source' => 'must-not-leave@example.test',
    ]);
    $this->assertUnknownInput('submit', [
      'run_id' => '1',
      'lease' => 'lease',
      'draft' => ['summary' => 'fixture'],
      'evidence' => 'private-note',
    ]);
    $this->assertUnknownInput('fail', [
      'run_id' => '1',
      'lease' => 'lease',
      'failure_code' => 'timeout',
      'message' => 'model prompt text',
    ]);
  }

  /**
   * Unknown actions and failure codes fail closed without dumping payload.
   */
  public function testUnknownActionAndFailureCode(): void {
    try {
      OperatingActions::payload('complete', [
        'draft' => 'must-not-leave',
      ]);
      $this->fail('Unknown action must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertSame(
        'Unknown operating action.',
        $exception->getMessage(),
      );
      $this->assertStringNotContainsString(
        'must-not-leave',
        $exception->getMessage(),
      );
    }
    try {
      OperatingActions::payload('fail', [
        'run_id' => '1',
        'lease' => 'lease',
        'failure_code' => 'highest',
      ]);
      $this->fail('Unknown failure code must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertSame(
        'Unknown operating run failure.',
        $exception->getMessage(),
      );
      $this->assertStringNotContainsString(
        'highest',
        $exception->getMessage(),
      );
    }
  }

  /**
   * Asserts unknown inputs fail closed without dumping payload keys.
   *
   * @param string $action
   *   Lifecycle action name.
   * @param array<string, mixed> $values
   *   Named inputs that must be refused.
   */
  private function assertUnknownInput(string $action, array $values): void {
    try {
      OperatingActions::payload($action, $values);
      $this->fail('Unknown input must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertSame(
        'Unknown operating action input.',
        $exception->getMessage(),
      );
      foreach (self::PAYLOAD_KEYS as $key) {
        $this->assertStringNotContainsString($key, $exception->getMessage());
      }
      S3DenyAssertions::assertPayloadAbsent($this, [
        'reason' => $exception->getMessage(),
      ]);
    }
  }

}
