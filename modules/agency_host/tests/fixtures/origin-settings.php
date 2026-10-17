<?php

/**
 * @file
 * Exercises origin settings with real root and non-root CLI processes.
 *
 * Predeclared failures: non-root input grants approval; incomplete or oversized
 * malformed input survives; inherited authority remains when input disappears;
 * adding only the final plan pin requires changing source or receipt bytes;
 * bootstrap prints approval material. Fixtures contain synthetic digests only.
 */

declare(strict_types=1);

use Drupal\agency_host\Identity\CleanOriginEnvironment;

require dirname(__DIR__, 2) . '/src/Identity/CleanOriginEnvironment.php';

$root = ($argv[1] ?? '') === 'root';
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')
  || (posix_geteuid() === 0) !== $root) {
  throw new RuntimeException('Run the control with the requested actual CLI uid.');
}
$approval = [
  'receipt_sha256' => str_repeat('a', 64),
  'target_sha256' => str_repeat('b', 64),
  'execution_go_sha256' => str_repeat('c', 64),
];
$empty = [
  'agency_host_origin_receipt_file' => '',
  'agency_host_origin_approval' => [],
];
$checks = 0;
$check = static function (string|false $path, string|false $json, array $expected) use (&$checks): void {
  foreach (['DRUPAL_AGENCY_ORIGIN_RECEIPT_FILE' => $path, 'DRUPAL_AGENCY_ORIGIN_APPROVAL_JSON' => $json] as $name => $value) {
    putenv($value === FALSE ? $name : $name . '=' . $value);
  }
  ob_start();
  try {
    $actual = CleanOriginEnvironment::settings();
  }
  finally {
    $output = ob_get_clean();
  }
  if ($actual !== $expected || $output !== '') {
    throw new RuntimeException('Protected settings control failed; values omitted.');
  }
  $checks++;
};
$path = '/run/example-agency/receipt.json';
$valid = json_encode($approval, JSON_THROW_ON_ERROR);
$check($path, $valid, $root ? [
  'agency_host_origin_receipt_file' => $path,
  'agency_host_origin_approval' => $approval,
] : $empty);
$approval['plan_sha256'] = str_repeat('d', 64);
$check($path, json_encode($approval, JSON_THROW_ON_ERROR), $root ? [
  'agency_host_origin_receipt_file' => $path,
  'agency_host_origin_approval' => $approval,
] : $empty);
foreach ([FALSE, '', '{', 'null', '[]', '{}', str_repeat('a', 4097),
  json_encode(['receipt_sha256' => str_repeat('a', 64)]),
  json_encode($approval + ['unknown' => 'value']),
  json_encode(array_replace($approval, ['plan_sha256' => ''])),
  json_encode(array_replace($approval, ['target_sha256' => 123])),
  json_encode(array_replace($approval, ['execution_go_sha256' => str_repeat('A', 64)])),
] as $invalid) {
  $check($path, $invalid, $empty);
}
foreach ([FALSE, '', 'relative/receipt.json', " /run/receipt.json", "/run/receipt\n.json"] as $invalid) {
  $check($invalid, $valid, $empty);
}
$check(FALSE, FALSE, $empty);

// Evaluate the actual settings file with an isolated, synthetic local override.
// This catches moving or removing the final overwrite, not just parser defects.
$temporary = sys_get_temp_dir() . '/agency-origin-bootstrap-' . bin2hex(random_bytes(8));
$files = [];
$directories = [$temporary, $temporary . '/web', $temporary . '/web/sites',
  $temporary . '/web/sites/default', $temporary . '/config', $temporary . '/config/overlay',
];
try {
  foreach ($directories as $directory) {
    mkdir($directory, 0700);
  }
  foreach ([
    'chronicle-staff-invite-organization', 'chronicle-client-access-organization',
    'chronicle-client-access-disposable', 'chronicle-client-access-keycloak',
    'chronicle-upload-geo', 'chronicle-upload-career',
    'chronicle-service-intake-mappings', 'chronicle-forms-platforms',
    'chronicle-forms-provenance', 'chronicle-forms-content-dimensions',
  ] as $name) {
    $files[] = $file = $temporary . '/config/overlay/' . $name . '.php';
    file_put_contents($file, '<?php return [];');
  }
  $files[] = $local = $temporary . '/web/sites/default/settings.local.php';
  file_put_contents($local, '<?php $settings["agency_host_origin_receipt_file"] = "/old/receipt.json"; $settings["agency_host_origin_approval"] = ["old" => "authority"];');
  define('DRUPAL_ROOT', $temporary . '/web');
  $source = dirname(__DIR__, 5) . '/sites/default/settings.php';
  foreach ([FALSE, '{', $valid] as $json) {
    putenv('DRUPAL_AGENCY_ORIGIN_RECEIPT_FILE=' . $path);
    putenv($json === FALSE ? 'DRUPAL_AGENCY_ORIGIN_APPROVAL_JSON' : 'DRUPAL_AGENCY_ORIGIN_APPROVAL_JSON=' . $json);
    ob_start();
    try {
      $actual = (static function () use ($temporary, $source, $empty): array {
        $app_root = $temporary . '/web';
        $site_path = 'sites/default';
        if (!is_file($app_root . '/' . $site_path . '/settings.local.php')) {
          throw new RuntimeException('Synthetic local override is missing.');
        }
        $settings = [];
        require $source;
        return array_intersect_key($settings, $empty);
      })();
    }
    finally {
      $output = ob_get_clean();
    }
    $expected = $root && $json === $valid ? [
      'agency_host_origin_receipt_file' => $path,
      'agency_host_origin_approval' => json_decode($valid, TRUE, 8, JSON_THROW_ON_ERROR),
    ] : $empty;
    if ($actual !== $expected || $output !== '') {
      throw new RuntimeException('Actual bootstrap retained or exposed inherited approval.');
    }
    $checks++;
  }
}
finally {
  foreach (array_reverse($files) as $file) {
    unlink($file);
  }
  foreach (array_reverse($directories) as $directory) {
    rmdir($directory);
  }
}
print "Protected origin settings: $checks checks passed.\n";
