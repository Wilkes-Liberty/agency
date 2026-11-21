<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\PublicStatementProjection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Public statement lines omit private notes and rates.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyPublicStatementProjectionTest extends TestCase {

  /**
   * The public line is exactly the ratified keys.
   */
  public function testPublicLineIsTheAllowlist(): void {
    $line = PublicStatementProjection::line([
      'public_summary' => 'Public',
      'seconds' => 61,
      'date' => '2026-01-06',
      'note' => 'Private fixture note',
      'hourly_rate_minor' => 15000,
      'filename' => 'secret-resume.pdf',
    ]);
    $this->assertSame(PublicStatementProjection::KEYS, array_keys($line));
    $this->assertSame('Public', $line['public_summary']);
    $this->assertSame(61, $line['seconds']);
    $this->assertSame('2026-01-06', $line['date']);
    foreach (PublicStatementProjection::PRIVATE_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $line);
    }
  }

}
