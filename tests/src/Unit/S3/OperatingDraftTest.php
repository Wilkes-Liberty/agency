<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

require_once dirname(__DIR__, 4)
  . '/modules/agency_commercial/src/OperatingDraft.php';

use Drupal\agency_commercial\OperatingDraft;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises bounded draft receipts independently of Drupal's container.
 *
 * @group agency
 */
#[Group('agency')]
final class OperatingDraftTest extends TestCase {

  /**
   * Server-generated evidence identifiers disclosed to this run.
   */
  private const REFERENCES = ['opportunity:12:revision:3'];

  /**
   * Builds a valid draft fixture.
   *
   * @return array
   *   One bounded recommendation payload.
   */
  private function draft(): array {
    return [
      'summary' => 'One follow-up needs review.',
      'findings' => [
        [
          'observation' => 'The recorded next action is overdue.',
          'recommendation' => 'Ask the owner to review the next action.',
          'references' => self::REFERENCES,
        ],
      ],
      'did' => ['Reviewed the supplied opportunity snapshot.'],
      'skipped' => [],
      'could_not_verify' => ['No delivery status was provided.'],
    ];
  }

  /**
   * Equivalent property orders produce the same receipt.
   */
  public function testEquivalentPropertyOrdersProduceSameReceipt(): void {
    $clock = new OperatingDraft();
    $draft = $this->draft();
    $this->assertSame(
      $clock->encode($draft, self::REFERENCES),
      $clock->encode(array_reverse($draft, TRUE), self::REFERENCES),
    );
  }

  /**
   * Invented evidence is refused.
   */
  public function testInventedEvidenceIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingDraft())->encode($this->draft(), []);
  }

  /**
   * Unknown action field is refused.
   */
  public function testUnknownActionFieldIsRefused(): void {
    $draft = $this->draft();
    $draft['send_email'] = TRUE;
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingDraft())->encode($draft, self::REFERENCES);
  }

  /**
   * Markup cannot enter a draft.
   */
  public function testMarkupCannotEnterDraft(): void {
    $draft = $this->draft();
    $draft['summary'] = '<script>alert(1)</script>';
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingDraft())->encode($draft, self::REFERENCES);
  }

  /**
   * Oversized draft is refused.
   */
  public function testOversizedDraftIsRefused(): void {
    $draft = $this->draft();
    $draft['summary'] = str_repeat('x', 1201);
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingDraft())->encode($draft, self::REFERENCES);
  }

  /**
   * Missing work declaration is not success.
   */
  public function testMissingWorkDeclarationIsNotSuccess(): void {
    $draft = $this->draft();
    $draft['did'] = [];
    $this->expectException(\InvalidArgumentException::class);
    (new OperatingDraft())->encode($draft, self::REFERENCES);
  }

}
