<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

use Symfony\Component\Yaml\Yaml;

/**
 * Scans shipped package files for tenant-specific strings.
 *
 * The needles are Wilkes, W&L, wilkesliberty and chronicle. Declared
 * legacy-compat keys and path exceptions live in the allowlist file.
 */
final class TenantStringGuard {

  /**
   * Bare tokens that must never appear as allowlist entries.
   */
  public const BARE_FORBIDDEN = [
    'wilkes',
    'w&l',
    'wilkesliberty',
    'chronicle',
  ];

  /**
   * Forbidden alternatives, longest first so wilkesliberty wins over Wilkes.
   */
  public const FORBIDDEN_ALTERNATIVES = [
    'wilkesliberty',
    'Wilkes',
    'W&L',
    'chronicle',
  ];

  /**
   * File extensions scanned as package code, config or defaults.
   */
  private const EXTENSIONS = [
    'php',
    'module',
    'install',
    'inc',
    'yml',
    'yaml',
    'json',
  ];

  /**
   * Directory names excluded from the package scan.
   */
  private const SKIP_DIRECTORIES = [
    '.git',
    '.github',
    'changelog.d',
    'docs',
    'node_modules',
    'ontology',
    'tests',
    'vendor',
  ];

  /**
   * Returns the compiled forbidden-string pattern.
   *
   * @return string
   *   A PCRE pattern.
   */
  public static function forbiddenPattern(): string {
    $parts = [];
    foreach (self::FORBIDDEN_ALTERNATIVES as $alternative) {
      $parts[] = preg_quote($alternative, '/');
    }
    return '/' . implode('|', $parts) . '/i';
  }

  /**
   * Resolves the package checkout root.
   *
   * @return string
   *   Absolute path without a trailing slash.
   */
  public function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Resolves the allowlist file.
   *
   * @return string
   *   Absolute path.
   */
  public function allowlistPath(): string {
    return $this->packageRoot()
      . '/tests/fixtures/tenant-strings/allowlist.yml';
  }

  /**
   * Resolves the seeded failure fixture.
   *
   * @return string
   *   Absolute path.
   */
  public function seedPath(): string {
    return $this->packageRoot()
      . '/tests/fixtures/tenant-strings/forbidden-seed.yml';
  }

  /**
   * Loads and validates an allowlist document.
   *
   * @param string|null $path
   *   Absolute path; defaults to the package allowlist.
   *
   * @return array
   *   Normalized allowlist with tokens and paths lists.
   */
  public function loadAllowlist(?string $path = NULL): array {
    $path = $path ?? $this->allowlistPath();
    if (!is_file($path)) {
      throw new \RuntimeException(
        'Tenant-string allowlist is missing: ' . $path,
      );
    }
    $data = $this->parseYaml($path);
    if (!is_array($data)) {
      throw new \InvalidArgumentException(
        'Tenant-string allowlist must be a mapping.',
      );
    }
    return $this->validateAllowlist($data);
  }

  /**
   * Validates allowlist structure and rejects bare forbidden tokens.
   *
   * @param array $data
   *   Decoded allowlist.
   *
   * @return array
   *   Normalized allowlist.
   */
  public function validateAllowlist(array $data): array {
    $tokens = $this->stringEntries($data['tokens'] ?? NULL, 'token', 'tokens');
    $paths = $this->stringEntries($data['paths'] ?? NULL, 'path', 'paths');
    foreach ($tokens as $entry) {
      if (in_array(strtolower($entry['token']), self::BARE_FORBIDDEN, TRUE)) {
        throw new \InvalidArgumentException(
          'Allowlist token ' . $entry['token']
          . ' is a bare forbidden string. '
          . 'Declare a specific legacy-compat key.',
        );
      }
    }
    return [
      'tokens' => $tokens,
      'paths' => $paths,
    ];
  }

  /**
   * Collects shipped files that must stay tenant-neutral.
   *
   * @param string $root
   *   Package root.
   *
   * @return string[]
   *   Absolute file paths, sorted.
   */
  public function collectFiles(string $root): array {
    $root = $this->normalizeRoot($root);
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        function (\SplFileInfo $current): bool {
          $name = $current->getFilename();
          if (str_starts_with($name, '.')) {
            return FALSE;
          }
          if ($current->isDir()) {
            return !in_array($name, self::SKIP_DIRECTORIES, TRUE);
          }
          $extension = strtolower($current->getExtension());
          return in_array($extension, self::EXTENSIONS, TRUE);
        },
      ),
    );
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo && $file->isFile()) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Scans the shipped package tree.
   *
   * @param string $root
   *   Package root.
   * @param array $allowlist
   *   Validated allowlist.
   *
   * @return array
   *   Hit records.
   */
  public function scanTree(string $root, array $allowlist): array {
    $root = $this->normalizeRoot($root);
    $hits = [];
    foreach ($this->collectFiles($root) as $absolute) {
      $hits = array_merge($hits, $this->scanFile($root, $absolute, $allowlist));
    }
    return $hits;
  }

  /**
   * Scans one file relative to the package root.
   *
   * @param string $root
   *   Package root.
   * @param string $absolute
   *   Absolute file path.
   * @param array $allowlist
   *   Validated allowlist.
   *
   * @return array
   *   Hit records.
   */
  public function scanFile(
    string $root,
    string $absolute,
    array $allowlist,
  ): array {
    $relative = $this->relativePath($root, $absolute);
    foreach ($allowlist['paths'] ?? [] as $entry) {
      if (($entry['path'] ?? '') === $relative) {
        return [];
      }
    }
    $contents = file_get_contents($absolute);
    if (!is_string($contents)) {
      throw new \RuntimeException('Unable to read ' . $relative);
    }
    return $this->scanText($relative, $contents, $allowlist);
  }

  /**
   * Scans a text buffer for remaining forbidden strings.
   *
   * @param string $label
   *   Path or fixture label used in reports.
   * @param string $contents
   *   File contents.
   * @param array $allowlist
   *   Validated allowlist.
   *
   * @return array
   *   Hit records.
   */
  public function scanText(
    string $label,
    string $contents,
    array $allowlist,
  ): array {
    $masked = $this->maskTokens($contents, $allowlist['tokens'] ?? []);
    $count = preg_match_all(
      self::forbiddenPattern(),
      $masked,
      $matches,
      PREG_OFFSET_CAPTURE,
    );
    if (!is_int($count) || $count === 0) {
      return [];
    }
    $hits = [];
    foreach ($matches[0] as $match) {
      $offset = (int) $match[1];
      $hits[] = [
        'path' => $label,
        'line' => $this->lineNumber($contents, $offset),
        'match' => $match[0],
        'excerpt' => $this->excerpt($contents, $offset),
      ];
    }
    return $hits;
  }

  /**
   * Formats hits for an assertion message.
   *
   * @param array $hits
   *   Hit records.
   *
   * @return string
   *   One hit per line.
   */
  public function formatHits(array $hits): string {
    $lines = [];
    foreach ($hits as $hit) {
      $lines[] = sprintf(
        '%s:%s: %s (%s)',
        $hit['path'],
        $hit['line'],
        $hit['match'],
        $hit['excerpt'],
      );
    }
    return implode("\n", $lines);
  }

  /**
   * Parses a YAML file with Symfony or ext-yaml.
   *
   * @param string $path
   *   Absolute path.
   *
   * @return mixed
   *   Decoded document.
   */
  private function parseYaml(string $path): mixed {
    if (class_exists(Yaml::class)) {
      return Yaml::parseFile($path);
    }
    if (function_exists('yaml_parse_file')) {
      return yaml_parse_file($path);
    }
    throw new \RuntimeException(
      'A YAML parser is required to read the tenant-string allowlist.',
    );
  }

  /**
   * Normalizes a list of token or path entries.
   *
   * @param mixed $value
   *   Raw list.
   * @param string $key
   *   Required value key.
   * @param string $section
   *   Section name for errors.
   *
   * @return array
   *   Normalized entries.
   */
  private function stringEntries(
    mixed $value,
    string $key,
    string $section,
  ): array {
    if ($value === NULL) {
      return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
      throw new \InvalidArgumentException(
        'Allowlist ' . $section . ' must be a list.',
      );
    }
    $entries = [];
    foreach ($value as $index => $item) {
      if (!is_array($item)
        || !isset($item[$key])
        || !is_string($item[$key])
        || $item[$key] === '') {
        throw new \InvalidArgumentException(
          'Allowlist ' . $section . '[' . $index . '] must declare '
          . $key . '.',
        );
      }
      $reason = $item['reason'] ?? NULL;
      if (!is_string($reason) || trim($reason) === '') {
        throw new \InvalidArgumentException(
          'Allowlist ' . $section . '[' . $index . '] must declare a reason.',
        );
      }
      $entries[] = [
        $key => $item[$key],
        'reason' => trim($reason),
      ];
    }
    return $entries;
  }

  /**
   * Masks allowlisted tokens so they do not match the forbidden pattern.
   *
   * @param string $contents
   *   File contents.
   * @param array $tokens
   *   Token entries.
   *
   * @return string
   *   Contents with tokens replaced by spaces.
   */
  private function maskTokens(string $contents, array $tokens): string {
    usort($tokens, static function (array $a, array $b): int {
      return strlen((string) $b['token']) <=> strlen((string) $a['token']);
    });
    $masked = $contents;
    foreach ($tokens as $entry) {
      $token = (string) $entry['token'];
      $masked = preg_replace(
        '/' . preg_quote($token, '/') . '/i',
        str_repeat(' ', strlen($token)),
        $masked,
      ) ?? $masked;
    }
    return $masked;
  }

  /**
   * Converts a byte offset to a 1-based line number.
   *
   * @param string $contents
   *   File contents.
   * @param int $offset
   *   Byte offset.
   *
   * @return int
   *   Line number.
   */
  private function lineNumber(string $contents, int $offset): int {
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
  }

  /**
   * Returns a single-line excerpt around an offset.
   *
   * @param string $contents
   *   File contents.
   * @param int $offset
   *   Byte offset.
   *
   * @return string
   *   Trimmed line, truncated for reports.
   */
  private function excerpt(string $contents, int $offset): string {
    $start = strrpos(substr($contents, 0, $offset), "\n");
    $start = $start === FALSE ? 0 : $start + 1;
    $end = strpos($contents, "\n", $offset);
    if ($end === FALSE) {
      $line = substr($contents, $start);
    }
    else {
      $line = substr($contents, $start, $end - $start);
    }
    $line = trim($line);
    if (strlen($line) > 120) {
      $line = substr($line, 0, 117) . '...';
    }
    return $line;
  }

  /**
   * Returns a root path without a trailing slash.
   *
   * @param string $root
   *   Package root.
   *
   * @return string
   *   Normalized root.
   */
  private function normalizeRoot(string $root): string {
    return rtrim($root, '/');
  }

  /**
   * Returns a POSIX-style path relative to the package root.
   *
   * @param string $root
   *   Package root.
   * @param string $absolute
   *   Absolute file path.
   *
   * @return string
   *   Relative path.
   */
  private function relativePath(string $root, string $absolute): string {
    $root = $this->normalizeRoot($root);
    if (!str_starts_with($absolute, $root . '/')) {
      throw new \RuntimeException(
        'File is outside the package root: ' . $absolute,
      );
    }
    return str_replace('\\', '/', substr($absolute, strlen($root) + 1));
  }

}
