<?php

/**
 * @file
 * Boots a second real kernel against only the coordinator's isolated test DB.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Tests\agency\Fixtures\MigrateOperationWorker;
use Drupal\Core\Site\Settings;

if (PHP_SAPI !== 'cli') {
  exit(1);
}
$loader = require $argv[2] . '/autoload.php';
$root = $argv[1];
chdir($root);
require_once $root . '/core/includes/bootstrap.inc';
$loader->addPsr4('Drupal\\KernelTests\\', $root . '/core/tests/Drupal/KernelTests');
$loader->addPsr4('Drupal\\Tests\\', $root . '/core/tests/Drupal/Tests');
$loader->addPsr4('Drupal\\TestTools\\', $root . '/core/tests/Drupal/TestTools');
$input = json_decode(stream_get_contents(STDIN), TRUE, 64, JSON_THROW_ON_ERROR);
$prefix = $input['database']['prefix'] ?? '';
$prefix = is_array($prefix) ? ($prefix['default'] ?? '') : $prefix;
if (!preg_match('/^(?:test[0-9]+)+$/D', $prefix)) {
  throw new RuntimeException('An isolated kernel-test site is required.');
}
$input['test_prefix'] = $prefix;
drupal_valid_test_ua($prefix);
foreach (['mysql', 'pgsql'] as $driver) {
  $loader->addPsr4('Drupal\\' . $driver . '\\', $root . '/core/modules/' . $driver . '/src');
}
Database::addConnectionInfo('default', 'default', $input['database']);
new Settings(['hash_salt' => 'synthetic-operation-worker']);

require dirname(__DIR__, 2) . '/src/Fixtures/MigrateOperationWorker.php';
$worker = new MigrateOperationWorker('operationWorker');
print json_encode($worker->runWorker($input, $loader, $root), JSON_THROW_ON_ERROR);
