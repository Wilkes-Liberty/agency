<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once __DIR__ . '/S3DenyAssertions.php';

use Drupal\agency_commercial\ToolOperations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies typed action boundaries and the payload adapter.
 *
 * @group agency
 */
#[Group('agency')]
final class ToolOperationsTest extends TestCase {

  /**
   * JSON object keys that must never appear on a public refusal body.
   */
  private const PAYLOAD_KEYS = [
    'email',
    'phone',
    'payload',
    'dispatch_key',
    'opportunity_id',
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
   * Optional omissions must not erase fields during an update.
   */
  public function testUpdatePreservesOmissionsAndExplicitClearing(): void {
    $this->assertSame([
      'id' => 7,
      'revision' => 12,
      'fields' => ['next_action' => ''],
    ], ToolOperations::payload('opportunity_update', [
      'id' => 7,
      'revision' => 12,
      'next_action' => '',
      'next_action_at' => NULL,
      'source' => NULL,
    ]));
  }

  /**
   * Activities keep the retry key outside the record fields.
   */
  public function testActivityPreservesRetryAndParent(): void {
    $input = [
      'opportunity' => 7,
      'type' => 'note',
      'summary' => 'Follow up',
      'occurred_at' => '2026-09-16T12:00:00',
    ];
    $this->assertSame(
      ['request' => 'request-123456789', 'fields' => $input],
      ToolOperations::payload(
        'activity_create',
        $input + ['request' => 'request-123456789'],
      ),
    );
  }

  /**
   * The adapter cannot route an activity to client documents.
   */
  public function testProtectedParentIsRejected(): void {
    $this->assertUnknownInput('activity_create', ['client_user' => 7]);
  }

  /**
   * The update adapter cannot expose an ownership reassignment input.
   */
  public function testOwnershipChangeIsRejected(): void {
    $this->assertUnknownInput('contact_update', [
      'id' => 7,
      'revision' => 12,
      'owner' => 9,
    ]);
  }

  /**
   * Finance, marketing, outbound, cos and engagement keep generic seats.
   */
  public function testSeatOperations(): void {
    $this->assertSame(
      ['billing_completeness'],
      ToolOperations::forSeat('finance'),
    );
    $this->assertSame(
      ['opportunity_list', 'opportunity_get'],
      ToolOperations::forSeat('marketing'),
    );
    $outbound = ToolOperations::forSeat('outbound');
    $this->assertContains('activity_create', $outbound);
    $this->assertContains('contact_update', $outbound);
    $this->assertNotContains('billing_completeness', $outbound);
    $this->assertContains(
      'billing_completeness',
      ToolOperations::forSeat('cos'),
    );
    $this->assertContains(
      'billing_completeness',
      ToolOperations::forSeat('engagement'),
    );
    $this->assertSame([], ToolOperations::forSeat('unknown'));
    $this->assertTrue(ToolOperations::isRead('opportunity_list'));
    $this->assertTrue(ToolOperations::isRead('opportunity_get'));
    $this->assertTrue(ToolOperations::isRead('billing_completeness'));
    $this->assertFalse(ToolOperations::isRead('opportunity_update'));
    $this->assertFalse(ToolOperations::isRead('activity_create'));
  }

  /**
   * Asserts unknown inputs fail closed without dumping payload keys.
   *
   * @param string $operation
   *   Operation name.
   * @param array<string, mixed> $values
   *   Named inputs that must be refused.
   */
  private function assertUnknownInput(string $operation, array $values): void {
    try {
      ToolOperations::payload($operation, $values);
      $this->fail('Unknown input must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertSame(
        'Unknown CRM action input.',
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
