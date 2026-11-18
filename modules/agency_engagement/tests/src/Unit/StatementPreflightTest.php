<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\StatementMigration\StatementPreflight;
use Drupal\agency_engagement\StatementMigration\StatementSourceInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Refuses source records whose stored representation is ambiguous.
 *
 * @group agency
 */
#[Group('agency')]
final class StatementPreflightTest extends UnitTestCase {

  private const BODY = '{"groups":[{"engagement":12,"title":"Project","lines":[{"public_summary":"Public","seconds":61,"date":"2026-01-06"}]}],"used_seconds":61,"included_seconds":null,"remaining_seconds":null,"budget_reference":""}';

  /**
   * Duplicate keys must not silently choose which scope or values to retain.
   */
  public function testRefusesDuplicateKeysEvenWhenLastValueIsValid(): void {
    $preflight = $this->preflight(str_replace('"engagement":12', '"engagement":99,"engage\u006dent":12', self::BODY), '[{"id":2,"revision":22}]');
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('body');
    $preflight->qualify('synthetic-source', 8);
  }

  /**
   * A numeric-keyed object is outside the ordered original pin contract.
   */
  public function testRefusesAnObjectMasqueradingAsAnOrderedList(): void {
    $preflight = $this->preflight(self::BODY, '{"0":{"id":2,"revision":22}}');
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('sources');
    $preflight->qualify('synthetic-source', 8);
  }

  /**
   * Unknown versions and numeric strings cannot pass the typed projection.
   */
  public function testRefusesStringSeconds(): void {
    $preflight = $this->preflight(str_replace('"seconds":61', '"seconds":"61"', self::BODY), '[{"id":2,"revision":22}]');
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('lines');
    $preflight->qualify('synthetic-source', 8);
  }

  /**
   * Retains source key order, whitespace, zeroes and absent presentation.
   */
  public function testKeepsExactSnapshotBytesAndDistinctAnchors(): void {
    $body = " \n" . str_replace(
      ['"included_seconds":null', '"remaining_seconds":null', '"budget_reference":""'],
      ['"included_seconds":0', '"remaining_seconds":-61', '"budget_reference":"Synthetic"'],
      self::BODY,
    );
    $sources = '[{"revision":22, "id":2}]' . "\n";
    $qualified = $this->preflight($body, $sources)->qualify('synthetic-source', 8);
    $this->assertSame(['synthetic-source', 'statement', 8], $qualified->identity);
    $this->assertSame(61, $qualified->snapshot['used_seconds']);
    $this->assertSame(0, $qualified->snapshot['included_seconds']);
    $this->assertSame(-61, $qualified->snapshot['remaining_seconds']);
    $this->assertArrayNotHasKey('presentation', $qualified->snapshot);
    $this->assertSame($body, $qualified->inverse()['body']);
    $this->assertSame($sources, $qualified->inverse()['sources']);
    $this->assertSame([12], $qualified->membership);
  }

  /**
   * Supplies synthetic retained evidence for one statement.
   */
  private function preflight(string $body, string $sources): StatementPreflight {
    $source = $this->createMock(StatementSourceInterface::class);
    $source->method('document')->with(8)->willReturn([
      'id' => 8,
      'kind' => 'statement',
      'actor' => 1,
      'client_user' => 2,
      'client_profile' => 2,
      'created' => 1767700000,
      'body' => $body,
      'sources' => $sources,
    ]);
    $source->method('membership')->with(8)->willReturn([12]);
    $source->method('identity')->willReturnCallback(static fn(string $type, int $id): string => "$type-$id");
    $source->method('ownsProfile')->with(2, 2)->willReturn(TRUE);
    $source->method('approvedEntry')->with(2, 22, 8, 1767700000)->willReturn([
      'uuid' => 'synthetic-entry',
      'engagement' => 12,
      'date' => '2026-01-06',
      'seconds' => 61,
      'public_summary' => 'Public',
      'source_langcode' => 'und',
      'source_hash' => str_repeat('0', 64),
    ]);
    $source->method('engagement')->with(12, 1767700000, NULL)->willReturn([
      'uuid' => 'synthetic-engagement',
      'client_user' => 2,
      'client_profile' => 2,
      'title' => 'Project',
      'source_langcode' => 'und',
      'source_hash' => str_repeat('0', 64),
    ]);
    return new StatementPreflight($source);
  }

}
