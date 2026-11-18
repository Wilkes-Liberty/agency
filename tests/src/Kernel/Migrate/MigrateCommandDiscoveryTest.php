<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Kernel\Migrate;

use Composer\Autoload\ClassLoader;
use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Drupal\agency_migrate\Drush\Commands\AgencyMigrateCommands;
use Drush\Config\DrushConfig;
use Drush\Runtime\ServiceManager;
use League\Container\Container;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Installed Drush must discover and parse the real migration commands.
 *
 * @group agency
 */
#[Group('agency')]
#[RunTestsInSeparateProcesses]
final class MigrateCommandDiscoveryTest extends MigrateKernelTestBase {

  /**
   * The discovered handler resolves real services and parsed restore flags.
   */
  public function testDiscoveredCommandsUseTypedOptions(): void {
    $manager = new ServiceManager(new ClassLoader(), new DrushConfig(), new NullLogger());
    $path = DRUPAL_ROOT . '/' . $this->container->get('module_handler')->getModule('agency_migrate')->getPath() . '/src/Drush';
    $classes = $manager->discoverModuleCommands([$path], 'Drupal\\agency_migrate\\Drush');
    $this->assertContains(AgencyMigrateCommands::class, $classes);
    $handlers = $manager->instantiateServices($classes, new Container(), $this->container);
    $this->assertCount(1, $handlers);
    $this->assertSame($this->container->get('agency_migrate.commands'), $handlers[0]);
    $commands = (new AnnotatedCommandFactory())->createCommandsFromClass($handlers[0]);
    $indexed = [];
    foreach ($commands as $command) {
      $indexed[$command->getName()] = $command;
    }
    $this->assertCount(5, $indexed);
    $command = $indexed['agency:migrate:authority'];
    $definition = $command->getDefinition();
    $this->assertSame([], $definition->getArguments());
    $this->assertTrue($definition->getOption('domain')->acceptValue());
    $this->assertTrue($definition->getOption('generation')->acceptValue());
    $this->assertFalse($definition->getOption('restore-from-backup')->acceptValue());

    foreach ([
      ['drush', '--restore-from-backup'],
      [
        'drush', '--domain=synthetic', '--set=legacy', '--reason=private-reason',
        '--generation=3', '--rollback', '--restore-from-backup', '--execute',
      ],
    ] as $arguments) {
      $input = new ArgvInput($arguments, $definition);
      $output = new BufferedOutput();
      $this->assertSame(1, $command->run($input, $output));
      $this->assertStringContainsString('restore_refused', $output->fetch());
    }
    $database = $this->container->get('database');
    $this->assertSame(2, (int) $database->select('agency_migrate_outbox', 'o')
      ->condition('operation', 'restore_refused')->countQuery()->execute()->fetchField());
    $this->assertSame([], $this->container->get('agency_migrate.authority')->status());
    $this->assertSame(0, (int) $database->select('agency_migrate_rollback_receipt', 'r')->countQuery()->execute()->fetchField());

    $input = new ArgvInput(['drush', '--domain=synthetic', '--register', '--reason=CLI qualification', '--execute'], $definition);
    ob_start();
    try {
      $this->assertSame(0, $command->run($input, new BufferedOutput()));
      $result = json_decode((string) ob_get_contents(), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    finally {
      ob_end_clean();
    }
    $this->assertSame(['domain' => 'synthetic', 'state' => 'legacy'], $result);
    $this->assertSame('legacy', $this->container->get('agency_migrate.authority')->get('synthetic'));
  }

}
