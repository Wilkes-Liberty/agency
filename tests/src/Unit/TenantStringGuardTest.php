<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

require_once __DIR__ . '/TenantStringGuard.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fails when tenant strings ship in package code, config or defaults.
 *
 * The seeded fixture is the failure case. The published tree is accepted
 * only after that fixture still fails and remaining hits are allowlisted
 * with a reason.
 *
 * @group agency
 */
#[Group('agency')]
final class TenantStringGuardTest extends TestCase {

  /**
   * Scanner under test.
   */
  private TenantStringGuard $guard;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->guard = new TenantStringGuard();
  }

  /**
   * The seeded fixture fails for every declared tenant string.
   */
  public function testSeededFixtureFails(): void {
    $path = $this->guard->seedPath();
    $this->assertFileExists($path);
    $contents = file_get_contents($path);
    $this->assertIsString($contents);

    $hits = $this->guard->scanText(
      'tests/fixtures/tenant-strings/forbidden-seed.yml',
      $contents,
      $this->guard->loadAllowlist(),
    );
    $matches = [];
    foreach ($hits as $hit) {
      $matches[] = strtolower((string) $hit['match']);
    }

    $this->assertNotSame([], $hits, 'The seeded fixture must fail the check.');
    $this->assertContains('wilkes', $matches);
    $this->assertContains('w&l', $matches);
    $this->assertContains('wilkesliberty', $matches);
    $this->assertContains('chronicle', $matches);
  }

  /**
   * Current package code, config and defaults stay within the allowlist.
   */
  public function testPublishedPackagePasses(): void {
    $root = $this->guard->packageRoot();
    $hits = $this->guard->scanTree($root, $this->guard->loadAllowlist());
    $this->assertSame(
      [],
      $hits,
      "Unexpected tenant strings:\n" . $this->guard->formatHits($hits),
    );
  }

  /**
   * The package scan visits shipped files and skips tests and documents.
   */
  public function testPackageScanCoversCodeConfigAndDefaults(): void {
    $root = $this->guard->packageRoot();
    $relative = [];
    foreach ($this->guard->collectFiles($root) as $absolute) {
      $relative[] = substr($absolute, strlen($root) + 1);
    }

    $this->assertContains('agency.module', $relative);
    $this->assertContains('agency.install', $relative);
    $this->assertContains('composer.json', $relative);
    $this->assertContains('config/schema/agency.schema.yml', $relative);
    $this->assertContains(
      'modules/agency_host/config/install/agency_host.identity_tokens.yml',
      $relative,
    );
    $this->assertContains(
      'modules/agency_host/resources/coordinator.en.json',
      $relative,
    );
    $this->assertNotContains(
      'tests/fixtures/tenant-strings/forbidden-seed.yml',
      $relative,
    );
    $this->assertNotContains(
      'tests/fixtures/tenant-strings/allowlist.yml',
      $relative,
    );
    $this->assertNotContains('README.md', $relative);
    $this->assertNotContains('docs/DEVELOPMENT.md', $relative);
  }

  /**
   * Every allowlist entry records why the exception exists.
   */
  public function testAllowlistEntriesHaveReasons(): void {
    $allowlist = $this->guard->loadAllowlist();
    $tokens = [];
    foreach ($allowlist['tokens'] as $entry) {
      $this->assertNotSame('', $entry['reason']);
      $tokens[] = $entry['token'];
    }
    $paths = [];
    foreach ($allowlist['paths'] as $entry) {
      $this->assertNotSame('', $entry['reason']);
      $paths[] = $entry['path'];
    }
    $this->assertContains('chronicle-agency-history-key-v1', $tokens);
    $this->assertContains('chronicle_agency', $tokens);
    $this->assertContains('paladin_issuer', $tokens);
    $this->assertContains('paladin_audience', $tokens);
    $this->assertContains('composer.json', $paths);
  }

  /**
   * Bare forbidden words cannot be allowlisted.
   */
  public function testAllowlistRejectsBareForbiddenTokens(): void {
    foreach (TenantStringGuard::BARE_FORBIDDEN as $token) {
      try {
        $this->guard->validateAllowlist([
          'tokens' => [
            [
              'token' => $token,
              'reason' => 'An overly broad exception must be refused.',
            ],
          ],
          'paths' => [],
        ]);
        $this->fail('Bare token ' . $token . ' must be refused.');
      }
      catch (\InvalidArgumentException $exception) {
        $this->assertStringContainsString(
          'bare forbidden',
          strtolower($exception->getMessage()),
        );
      }
    }
  }

  /**
   * Declared legacy keys are masked; leftover chronicle still fails.
   */
  public function testDeclaredLegacyKeysDoNotHideBareChronicle(): void {
    $allowlist = $this->guard->validateAllowlist([
      'tokens' => [
        [
          'token' => 'chronicle_agency',
          'reason' => 'Digest schema slot used by already-qualified coverage.',
        ],
      ],
      'paths' => [],
    ]);
    $hits = $this->guard->scanText(
      'synthetic',
      "keep chronicle_agency and reject chronicle\n",
      $allowlist,
    );
    $this->assertCount(1, $hits);
    $this->assertSame('chronicle', $hits[0]['match']);
    $this->assertSame(1, $hits[0]['line']);
  }

  /**
   * A path exception skips only that file.
   */
  public function testPathAllowlistIsExact(): void {
    $root = $this->guard->packageRoot();
    $allowlist = $this->guard->validateAllowlist([
      'tokens' => [],
      'paths' => [
        [
          'path' => 'composer.json',
          'reason' => 'Maintainer attribution is not a tenant default.',
        ],
      ],
    ]);
    $this->assertSame(
      [],
      $this->guard->scanFile($root, $root . '/composer.json', $allowlist),
    );

    $seed = $this->guard->seedPath();
    $this->assertNotSame(
      [],
      $this->guard->scanFile($root, $seed, $allowlist),
    );
  }

}
