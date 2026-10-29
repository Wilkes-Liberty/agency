<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Composer\InstalledVersions;
use Drupal\agency_host\Identity\BindingHistory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the reviewed Externalauth 2.0.x writers without moving the fingerprint.
 *
 * Findings live in docs/EXTERNALAUTH_2.0.15.md.
 *
 * @group agency_host
 *
 * @see \Drupal\agency_host\Identity\BindingHistory
 */
#[CoversClass(BindingHistory::class)]
#[Group('agency_host')]
final class BindingHistoryFingerprintTest extends UnitTestCase {

  /**
   * Observed SHA-256 of the two fingerprinted writers on 2.0.13, 2.0.14 and 2.0.15.
   */
  private const WRITER_HASHES = [
    'src/Authmap.php' => 'e6c98dd790128410e07f05ad0ae54aa4b8e05850fc1fa8bad8a9491d9126c93a',
    'src/Form/AuthmapDeleteForm.php' => 'aebd9185d105c9f1445baf532a33c6419459d28914d5da6f808921ccf1543500',
  ];

  /**
   * The reviewed reference string stays 2.0.13 so the digest cannot drift.
   */
  public function testReviewedReferenceStaysTwoZeroThirteen(): void {
    $source = file_get_contents($this->bindingHistoryPath());
    $this->assertNotFalse($source);
    $this->assertStringContainsString("'drupal/externalauth' => '2.0.13'", $source);
    $this->assertStringContainsString("InstalledVersions::getReference(\$package)", $source);
    $this->assertDoesNotMatchRegularExpression(
      "/'drupal\\/externalauth'\\s*=>\\s*'2\\.0\\.1[45]'/",
      $source,
      'Bumping the reviewed Externalauth reference would change the fingerprint digest.',
    );
  }

  /**
   * Releases 2.0.14 and 2.0.15 keep the same writer bytes already bound.
   */
  public function testReviewedWriterHashesMatchTwoOhFifteen(): void {
    $writers = (new \ReflectionClass(BindingHistory::class))->getConstant('REVIEWED_WRITERS');
    $this->assertIsArray($writers);
    $observed = [];
    foreach ($writers as $row) {
      $this->assertIsArray($row);
      [$package, $file, $hash] = $row;
      if ($package !== 'drupal/externalauth') {
        continue;
      }
      $this->assertArrayHasKey($file, self::WRITER_HASHES);
      $this->assertSame(self::WRITER_HASHES[$file], $hash);
      $observed[$file] = $hash;
    }
    $this->assertSame(self::WRITER_HASHES, $observed);
  }

  /**
   * The installed 2.0.13 writers still match the hashes bound into coverage.
   */
  public function testInstalledWritersMatchReviewedHashes(): void {
    if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('drupal/externalauth')) {
      $this->markTestSkipped('Reviewed Externalauth is not installed on this host.');
    }
    $this->assertSame('2.0.13', InstalledVersions::getReference('drupal/externalauth'));
    $root = InstalledVersions::getInstallPath('drupal/externalauth');
    foreach (self::WRITER_HASHES as $file => $expected) {
      $path = $root . '/' . $file;
      $this->assertFileExists($path);
      $actual = hash_file('sha256', $path);
      $this->assertIsString($actual);
      $this->assertTrue(hash_equals($expected, $actual), $file);
    }
  }

  /**
   * Absolute path to the history class that owns the fingerprint.
   */
  private function bindingHistoryPath(): string {
    return dirname(__DIR__, 3) . '/src/Identity/BindingHistory.php';
  }

}
