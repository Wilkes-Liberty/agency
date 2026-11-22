<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Slice 5 types stay proposed; unimplemented transitions have no action.
 *
 * Host scan at webcms b12940a3 listed zero Slice 5 classes as to-port.
 * Campaign, expense and agreement are built in this package, not migrated.
 *
 * @group agency
 */
#[Group('agency')]
final class SliceFiveTransitionTest extends TestCase {

  /**
   * Loads the published ontology contract.
   *
   * @return array<string, mixed>
   *   Decoded YAML.
   */
  private function contract(): array {
    $path = dirname(__DIR__, 3) . '/ontology/ontology.yml';
    $this->assertFileExists($path);
    $contract = Yaml::parseFile($path);
    $this->assertIsArray($contract);
    return $contract;
  }

  /**
   * Objects keyed by ontology id.
   *
   * @return array<string, array<string, mixed>>
   *   Object rows.
   */
  private function objects(): array {
    $by_id = [];
    foreach ($this->contract()['objects'] as $object) {
      $this->assertIsArray($object);
      $this->assertIsString($object['id']);
      $by_id[$object['id']] = $object;
    }
    return $by_id;
  }

  /**
   * Action ids from the published contract.
   *
   * @return list<string>
   *   Action identifiers.
   */
  private function actionIds(): array {
    $ids = [];
    foreach ($this->contract()['actions'] as $action) {
      $this->assertIsArray($action);
      $this->assertIsString($action['id']);
      $ids[] = $action['id'];
    }
    return $ids;
  }

  /**
   * Package service definition files, including test doubles.
   *
   * @return list<string>
   *   Absolute paths.
   */
  private function serviceDefinitionFiles(): array {
    $root = dirname(__DIR__, 3);
    $files = [$root . '/agency.services.yml'];
    foreach (['modules', 'tests'] as $dir) {
      $path = $root . '/' . $dir;
      if (!is_dir($path)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(
          $path,
          \FilesystemIterator::SKIP_DOTS,
        ),
      );
      foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), '.services.yml')) {
          $files[] = $file->getPathname();
        }
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Campaign, expense, agreement, credential and availability stay proposed.
   */
  public function testSliceFiveTypesRemainProposed(): void {
    $objects = $this->objects();
    foreach ([
      'Campaign',
      'Expense',
      'Agreement',
      'Credential',
      'Availability',
    ] as $id) {
      $this->assertArrayHasKey($id, $objects, $id);
      $this->assertSame('proposed', $objects[$id]['status'], $id);
      $this->assertSame('S5', $objects[$id]['slice'], $id);
    }
    $this->assertArrayNotHasKey('Roster', $objects);
  }

  /**
   * Pause and complete have no campaign action. Launch is the only write.
   */
  public function testCampaignPauseAndCompleteHaveNoAction(): void {
    $campaign = $this->objects()['Campaign'];
    $actions = [];
    foreach ($campaign['lifecycle']['transitions'] as $transition) {
      $actions[] = $transition['action'];
    }
    $this->assertSame(['launchCampaign', 'launchCampaign'], $actions);
    $ids = $this->actionIds();
    $this->assertContains('launchCampaign', $ids);
    $this->assertNotContains('pauseCampaign', $ids);
    $this->assertNotContains('completeCampaign', $ids);
  }

  /**
   * Submit, approve and reimburse have no expense action.
   */
  public function testExpenseLaterStatesHaveNoAction(): void {
    $expense = $this->objects()['Expense'];
    $actions = [];
    foreach ($expense['lifecycle']['transitions'] as $transition) {
      $actions[] = $transition['action'];
    }
    $this->assertSame(['recordExpense'], $actions);
    $ids = $this->actionIds();
    $this->assertContains('recordExpense', $ids);
    $this->assertNotContains('submitExpense', $ids);
    $this->assertNotContains('approveExpense', $ids);
    $this->assertNotContains('reimburseExpense', $ids);
  }

  /**
   * Agreement lifecycle lists states and no transitions.
   */
  public function testAgreementHasEmptyTransitions(): void {
    $agreement = $this->objects()['Agreement'];
    $this->assertSame([], $agreement['lifecycle']['transitions']);
    $ids = $this->actionIds();
    $this->assertNotContains('draftAgreement', $ids);
    $this->assertNotContains('signAgreement', $ids);
    $this->assertNotContains('activateAgreement', $ids);
    $this->assertNotContains('terminateAgreement', $ids);
  }

  /**
   * Credential and availability actions stay proposed. They are not registered.
   */
  public function testCredentialAndAvailabilityStayProposedActions(): void {
    $by_id = [];
    foreach ($this->contract()['actions'] as $action) {
      $by_id[$action['id']] = $action;
    }
    $this->assertSame('proposed', $by_id['verifyCredential']['status']);
    $this->assertSame('proposed', $by_id['offerAvailability']['status']);
    $this->assertSame('S5', $by_id['verifyCredential']['slice']);
    $this->assertSame('S5', $by_id['offerAvailability']['slice']);
  }

  /**
   * Credential, availability and roster have no submodule on disk.
   */
  public function testCredentialAvailabilityRosterModulesAreAbsent(): void {
    $root = dirname(__DIR__, 3) . '/modules';
    foreach ([
      'agency_credential',
      'agency_availability',
      'agency_roster',
    ] as $module) {
      $this->assertFalse(is_dir($root . '/' . $module), $module);
      $this->assertFalse(
        file_exists($root . '/' . $module . '/' . $module . '.info.yml'),
        $module,
      );
    }
  }

  /**
   * Service YAML omits unregistered Slice 5 writes and module ids.
   */
  public function testPackageServicesOmitUnregisteredSliceFiveWrites(): void {
    $needles = [
      'verifyCredential',
      'offerAvailability',
      'agency_credential',
      'agency_availability',
      'agency_roster',
    ];
    $files = $this->serviceDefinitionFiles();
    $this->assertNotEmpty($files);
    foreach ($files as $path) {
      $contents = file_get_contents($path);
      $this->assertNotFalse($contents, $path);
      foreach ($needles as $needle) {
        $this->assertStringNotContainsString($needle, $contents, $path);
      }
    }
  }

  /**
   * Roster has no object type and no action id.
   */
  public function testRosterHasNoAction(): void {
    foreach ($this->actionIds() as $id) {
      $this->assertStringNotContainsStringIgnoringCase('roster', $id);
    }
  }

}
