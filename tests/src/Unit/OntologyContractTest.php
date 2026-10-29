<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the ontology contract and the genericity rules.
 *
 * Invalid fixtures are the failure cases. The published contract is accepted
 * only after each named rule has a fixture that fails it.
 *
 * @group agency
 */
#[Group('agency')]
final class OntologyContractTest extends TestCase {

  /**
   * Discovery storage fields that the contract must declare.
   */
  private const DISCOVERY_FIELDS = [
    'agency_person' => [
      'id',
      'uuid',
      'created',
      'changed',
      'display_name',
      'contact_kind',
      'contact_value',
    ],
    'agency_principal' => [
      'id',
      'uuid',
      'created',
      'changed',
      'principal_key',
      'issuer',
      'subject',
      'person_uuid',
      'status',
    ],
    'agency_discovery' => [
      'id',
      'uuid',
      'created',
      'changed',
      'revision_id',
      'revision_created',
      'person_uuid',
      'principal_uuid',
      'owner_uid',
      'state',
      'waiting_on',
      'version',
      'details',
    ],
    'agency_invitation' => [
      'id',
      'uuid',
      'created',
      'changed',
      'relationship_uuid',
      'principal_uuid',
      'token_hash',
      'status',
      'expires',
      'delivery_state',
    ],
    'agency_entitlement' => [
      'id',
      'uuid',
      'created',
      'changed',
      'relationship_uuid',
      'principal_uuid',
      'status',
      'version',
      'readable_until',
      'details',
    ],
  ];

  /**
   * Implemented Discovery command identifiers.
   */
  private const DISCOVERY_COMMANDS = [
    'startDiscovery',
    'setNextAction',
    'respond',
    'pauseRelationship',
    'resumeRelationship',
    'withdrawRelationship',
    'revokeInvitation',
    'suspendAccess',
    'close',
    'moveForward',
    'issue',
    'resend',
    'accept',
    'revoke',
    'removeExpiredDiscovery',
  ];

  /**
   * Object type ids that remain proposed for slice 5.
   */
  private const SLICE_FIVE_TYPES = [
    'Expense',
    'Credential',
    'Availability',
    'Campaign',
  ];

  /**
   * Legacy sources that Slice 2 must map before gate G1.
   */
  private const SLICE_TWO_SOURCES = [
    'profile_employee',
    'profile_contractor',
    'chronicle_personnel',
    'chronicle_staff_invite',
    'staff_onboard',
    'career',
    'career_application',
  ];

  /**
   * Named 10/22 career pipeline sources that are not Agency copy rows.
   */
  private const SLICE_TWO_CAREER_PIPELINE = [
    'career_upload_token' => 'file_gate_carry_over',
    'career_resume_job_store' => 'file_gate_carry_over',
    'career_resume_claim_access' => 'file_gate_carry_over',
    'career_application_geo_handlers' => 'host_stay',
  ];

  /**
   * Posting and application types owned by the recruiting boundary.
   */
  private const SLICE_TWO_RECRUITING_TYPES = [
    'agency_career_posting',
    'agency_application',
  ];

  /**
   * Staff actions that must use the workforce evidence shape.
   */
  private const WORKFORCE_EVIDENCE_ACTIONS = [
    'inviteStaff',
    'acceptStaffInvitation',
    'revokeStaffInvitation',
    'updateProfileEvidence',
  ];

  /**
   * Recruiting actions that must use the recruiting evidence shape.
   */
  private const RECRUITING_EVIDENCE_ACTIONS = [
    'createCareerPosting',
    'publishCareerPosting',
    'closeCareerPosting',
    'submitApplication',
  ];

  /**
   * Allowed Field Guard markings on the Slice 2 transfer map.
   */
  private const SLICE_TWO_MARKINGS = [
    'public',
    'internal',
    'confidential',
    'personal',
    'financial',
  ];

  /**
   * The published contract passes schema validation and every rule.
   */
  public function testPublishedContractPasses(): void {
    $contract = $this->loadYaml('ontology/ontology.yml');
    $this->assertSame([], $this->evaluate($contract, $this->publishedScanPaths()));
  }

  /**
   * Each rule has a fixture that fails that rule.
   */
  public function testInvalidFixturesFailNamedRules(): void {
    $contract = $this->loadYaml('ontology/ontology.yml');
    $rules = $this->ruleIds($contract);
    $this->assertNotEmpty($rules);
    $directory = $this->path('ontology/fixtures/invalid');
    $this->assertDirectoryExists($directory);
    $found = [];
    foreach (glob($directory . '/*.yml') ?: [] as $file) {
      $rule = basename($file, '.yml');
      $found[] = $rule;
      $this->assertContains($rule, $rules);
      $violations = $this->evaluate($this->loadYaml(
        'ontology/fixtures/invalid/' . basename($file),
      ), [$file]);
      $this->assertContains($rule, $violations, $file . ' should fail ' . $rule);
    }
    sort($rules);
    sort($found);
    $this->assertSame($rules, $found);
  }

  /**
   * Implemented Discovery storage fields stay aligned with the entities.
   */
  public function testImplementedDiscoveryFieldsAreDeclared(): void {
    $by_name = $this->objectsByMachineName(
      $this->loadYaml('ontology/ontology.yml'),
    );
    foreach (self::DISCOVERY_FIELDS as $machine_name => $fields) {
      $this->assertArrayHasKey($machine_name, $by_name);
      $declared = $this->propertyNames($by_name[$machine_name]);
      foreach ($fields as $field) {
        $this->assertContains($field, $declared, $machine_name);
      }
      $this->assertTrue($by_name[$machine_name]['status'] === 'implemented');
    }
    $relationship = $by_name['agency_discovery'];
    $this->assertTrue($relationship['revisionable']);
    $this->assertTrue($relationship['identifiers']['revision_id']);
    $kinds = [];
    foreach ($relationship['kinds'] ?? [] as $kind) {
      $kinds[$kind['id']] = $kind;
    }
    $this->assertSame('implemented', $kinds['discovery']['status'] ?? NULL);
    $this->assertSame(
      'agency_discovery',
      $kinds['discovery']['machine_name'] ?? NULL,
    );
  }

  /**
   * Slice 2 declares a proposed recruiting relationship kind.
   */
  public function testRecruitingKindIsProposedForSliceTwo(): void {
    $relationship = $this->objectsByMachineName(
      $this->loadYaml('ontology/ontology.yml'),
    )['agency_discovery'];
    $kinds = [];
    foreach ($relationship['kinds'] ?? [] as $kind) {
      $kinds[$kind['id']] = $kind;
    }
    $this->assertSame('proposed', $kinds['recruiting']['status'] ?? NULL);
    $this->assertSame('S2', $kinds['recruiting']['slice'] ?? NULL);
    $this->assertSame(
      'agency_recruiting',
      $kinds['recruiting']['machine_name'] ?? NULL,
    );
  }

  /**
   * Every required Slice 2 source maps to an agency type.
   */
  public function testSliceTwoSourcesAreMapped(): void {
    $contract = $this->loadYaml('ontology/ontology.yml');
    $this->assertSame(
      [],
      $this->validateSchema(
        $contract['s2_transfer_map'] ?? NULL,
        's2_transfer_map',
      ),
    );
    $map = $contract['s2_transfer_map'] ?? [];
    $this->assertIsArray($map);
    $by_source = $this->transferRowsBySource($map);
    foreach (self::SLICE_TWO_SOURCES as $source) {
      $this->assertArrayHasKey($source, $by_source, $source);
      $copy_rows = $this->copyRows($by_source[$source]);
      $this->assertNotSame([], $copy_rows, $source);
      foreach ($copy_rows as $row) {
        $this->assertTrue(
          $this->machineNamePrefixed($row['target'] ?? NULL),
          $source,
        );
        $this->assertNotSame('', $row['copier'] ?? '', $source);
        $this->assertContains(
          $row['field_guard_marking'] ?? NULL,
          self::SLICE_TWO_MARKINGS,
          $source,
        );
        $this->assertContains(
          $row['authority_domain'] ?? NULL,
          ['workforce', 'recruiting'],
          $source,
        );
      }
    }
    $resume_rows = $this->copyRows($by_source['career_resume'] ?? []);
    $this->assertCount(1, $resume_rows);
    $this->assertSame(
      'career_resume_file',
      $resume_rows[0]['copier'] ?? NULL,
    );
    $this->assertSame(
      'resume_file_uuid',
      $resume_rows[0]['target_property'] ?? NULL,
    );
    $this->assertSame(
      'agency_application',
      $resume_rows[0]['target'] ?? NULL,
    );
    $this->assertSame(
      'recruiting',
      $resume_rows[0]['authority_domain'] ?? NULL,
    );

    $by_name = $this->objectsByMachineName($contract);
    foreach ($map as $row) {
      if (($row['disposition'] ?? '') !== 'copy') {
        continue;
      }
      $target = (string) ($row['target'] ?? '');
      $this->assertArrayHasKey($target, $by_name, $target);
      if ($target === 'agency_person') {
        $this->assertSame('S1', $by_name[$target]['slice'] ?? NULL, $target);
        $this->assertSame(
          'implemented',
          $by_name[$target]['status'] ?? NULL,
          $target,
        );
        continue;
      }
      $this->assertSame('S2', $by_name[$target]['slice'] ?? NULL, $target);
      $this->assertSame(
        'proposed',
        $by_name[$target]['status'] ?? NULL,
        $target,
      );
    }

    $intake_sources = $by_name['agency_intake_submission']['legacy_sources'] ?? [];
    $this->assertIsArray($intake_sources);
    $this->assertNotContains('career_application', $intake_sources);
    $this->assertContains(
      'resume_file_uuid',
      $this->propertyNames($by_name['agency_application']),
    );
    $invitation_sources = $by_name['agency_invitation']['legacy_sources'] ?? [];
    $this->assertIsArray($invitation_sources);
    $this->assertNotContains('chronicle_staff_invite', $invitation_sources);
    $this->assertContains(
      'chronicle_staff_invite',
      $by_name['agency_staff_invitation']['legacy_sources'] ?? [],
    );

    $client_rows = $by_source['profile_client'] ?? [];
    $this->assertCount(1, $client_rows);
    $this->assertSame('host_stay', $client_rows[0]['disposition'] ?? NULL);
    $this->assertSame(
      'fail_closed',
      $client_rows[0]['collision_preflight'] ?? NULL,
    );
    $this->assertSame([], $this->copyRows($client_rows));
  }

  /**
   * Personnel source copies onto person and profile evidence.
   */
  public function testSliceTwoPersonnelSourceIsMapped(): void {
    $rows = $this->copyRows(
      $this->transferRowsBySource(
        $this->loadYaml('ontology/ontology.yml')['s2_transfer_map'] ?? [],
      )['chronicle_personnel'] ?? [],
    );
    $targets = [];
    $copiers = [];
    foreach ($rows as $row) {
      $targets[] = (string) ($row['target'] ?? '');
      $copiers[] = (string) ($row['copier'] ?? '');
      $this->assertSame('workforce', $row['authority_domain'] ?? NULL);
    }
    $this->assertCount(2, $rows);
    $this->assertContains('agency_person', $targets);
    $this->assertContains('agency_profile_evidence', $targets);
    $this->assertContains('chronicle_personnel', $copiers);
    $this->assertContains('chronicle_personnel_evidence', $copiers);
  }

  /**
   * Named 10/22 upload, scan, claim and geo sources have a disposition.
   */
  public function testSliceTwoCareerPipelineSourcesAreNamed(): void {
    $by_source = $this->transferRowsBySource(
      $this->loadYaml('ontology/ontology.yml')['s2_transfer_map'] ?? [],
    );
    foreach (self::SLICE_TWO_CAREER_PIPELINE as $source => $disposition) {
      $this->assertArrayHasKey($source, $by_source, $source);
      $this->assertCount(1, $by_source[$source], $source);
      $this->assertSame(
        $disposition,
        $by_source[$source][0]['disposition'] ?? NULL,
        $source,
      );
      $this->assertSame([], $this->copyRows($by_source[$source]), $source);
    }
  }

  /**
   * Posting and application types use the recruiting submodule boundary.
   */
  public function testSliceTwoRecruitingTypesUseRecruitingBoundary(): void {
    $by_name = $this->objectsByMachineName(
      $this->loadYaml('ontology/ontology.yml'),
    );
    foreach (self::SLICE_TWO_RECRUITING_TYPES as $machine_name) {
      $this->assertArrayHasKey($machine_name, $by_name, $machine_name);
      $owner = $by_name[$machine_name]['owning_submodule'] ?? [];
      $this->assertSame(
        'agency_recruiting',
        $owner['name'] ?? NULL,
        $machine_name,
      );
      $this->assertSame('proposed', $owner['status'] ?? NULL, $machine_name);
      $this->assertSame('S2', $by_name[$machine_name]['slice'] ?? NULL);
      $this->assertNotSame(
        'agency_workforce',
        $owner['name'] ?? NULL,
        $machine_name,
      );
    }
    foreach ([
      'agency_profile_evidence',
      'agency_staff_invitation',
      'agency_staff_onboard',
    ] as $machine_name) {
      $this->assertSame(
        'agency_workforce',
        $by_name[$machine_name]['owning_submodule']['name'] ?? NULL,
        $machine_name,
      );
    }
  }

  /**
   * Staff and recruiting writes do not reuse Discovery evidence fields.
   */
  public function testSliceTwoEvidenceShapesAreNotDiscovery(): void {
    $by_id = [];
    foreach ($this->loadYaml('ontology/ontology.yml')['actions'] ?? [] as $action) {
      if (is_array($action) && isset($action['id'])) {
        $by_id[(string) $action['id']] = $action;
      }
    }
    $forbidden = ['contact', 'note', 'token', 'credential', 'file'];
    foreach (self::WORKFORCE_EVIDENCE_ACTIONS as $id) {
      $fields = $by_id[$id]['evidence']['fields'] ?? [];
      $this->assertContains('classification_hash', $fields, $id);
      $this->assertNotContains('waiting_on', $fields, $id);
      foreach ($forbidden as $item) {
        $this->assertContains(
          $item,
          $by_id[$id]['evidence']['forbidden'] ?? [],
          $id,
        );
      }
    }
    foreach (self::RECRUITING_EVIDENCE_ACTIONS as $id) {
      $fields = $by_id[$id]['evidence']['fields'] ?? [];
      $this->assertContains('posting_id', $fields, $id);
      $this->assertContains('application_id', $fields, $id);
      $this->assertNotContains('waiting_on', $fields, $id);
      foreach ($forbidden as $item) {
        $this->assertContains(
          $item,
          $by_id[$id]['evidence']['forbidden'] ?? [],
          $id,
        );
      }
    }
  }

  /**
   * Implemented actions include every current Discovery command.
   */
  public function testImplementedActionsCoverDiscoveryCommands(): void {
    $implemented = [];
    foreach ($this->loadYaml('ontology/ontology.yml')['actions'] ?? [] as $action) {
      if (($action['status'] ?? '') === 'implemented') {
        $implemented[] = $action['id'];
      }
    }
    foreach (self::DISCOVERY_COMMANDS as $command) {
      $this->assertContains($command, $implemented);
    }
  }

  /**
   * Evaluates a contract document against schemas and rules.
   *
   * @param array $contract
   *   Decoded ontology document.
   * @param string[] $scan_files
   *   Files that must stay free of tenant strings.
   *
   * @return string[]
   *   Violated rule identifiers.
   */
  private function evaluate(array $contract, array $scan_files): array {
    $violations = [];
    $schema_errors = array_merge(
      $this->validateSchema($contract['objects'] ?? NULL, 'objects'),
      $this->validateSchema($contract['links'] ?? NULL, 'links'),
      $this->validateSchema($contract['actions'] ?? NULL, 'actions'),
    );
    if (array_key_exists('s2_transfer_map', $contract)) {
      $schema_errors = array_merge(
        $schema_errors,
        $this->validateSchema(
          $contract['s2_transfer_map'],
          's2_transfer_map',
        ),
      );
    }
    if ($schema_errors !== []) {
      $violations[] = 'schema_valid';
    }

    $objects = is_array($contract['objects'] ?? NULL) ? $contract['objects'] : [];
    $links = is_array($contract['links'] ?? NULL) ? $contract['links'] : [];
    $actions = is_array($contract['actions'] ?? NULL) ? $contract['actions'] : [];
    $object_ids = [];
    $action_ids = [];

    foreach ($objects as $object) {
      if (!is_array($object)) {
        $violations[] = 'schema_valid';
        continue;
      }
      if (isset($object['id']) && is_string($object['id'])) {
        $object_ids[] = $object['id'];
      }
      if (!$this->propertiesMarked($object)) {
        $violations[] = 'every_property_has_a_marking';
      }
      if (!$this->identifiersDeclared($object)) {
        $violations[] = 'identifiers_are_declared';
      }
      if (!$this->revisionMatches($object)) {
        $violations[] = 'revisionable_matches_revision_id';
      }
      if (!$this->crossTenantDenied($object)) {
        $violations[] = 'cross_tenant_reads_denied';
      }
      if (!$this->machineNamePrefixed($object['machine_name'] ?? NULL)) {
        $violations[] = 'machine_names_use_agency_prefix';
      }
      foreach ($object['kinds'] ?? [] as $kind) {
        $name = is_array($kind) ? ($kind['machine_name'] ?? NULL) : NULL;
        if (!$this->machineNamePrefixed($name)) {
          $violations[] = 'machine_names_use_agency_prefix';
        }
      }
    }

    foreach ($actions as $action) {
      if (!is_array($action)) {
        $violations[] = 'schema_valid';
        continue;
      }
      if (isset($action['id']) && is_string($action['id'])) {
        $action_ids[] = $action['id'];
      }
      if (!$this->permissionDeclared($action)) {
        $violations[] = 'every_action_has_a_permission';
      }
      if (!$this->evidenceDeclared($action)) {
        $violations[] = 'every_action_has_evidence';
      }
    }

    if (!$this->transitionsClosed($objects, $action_ids)) {
      $violations[] = 'transitions_are_closed';
    }
    if (!$this->linksComplete($links, $object_ids)) {
      $violations[] = 'links_declare_cardinality_and_owner';
    }
    if (!$this->projectionsAllowlisted($contract, $objects)) {
      $violations[] = 'projections_are_allowlisted';
    }
    if (!$this->discoveryStorageDeclared($objects)) {
      $violations[] = 'implemented_discovery_matches_storage';
    }
    if (!$this->sliceFiveProposed($objects)) {
      $violations[] = 's5_types_remain_proposed';
    }
    if (!$this->compatibilitySemver($contract)) {
      $violations[] = 'compatibility_follows_semver';
    }
    if (!empty($contract['ships_tenant_defaults'])) {
      $violations[] = 'tenant_values_are_host_configuration';
    }
    if (!$this->discoveryCommandsRegistered($actions)) {
      $violations[] = 'implemented_actions_are_discovery_commands';
    }
    if (!$this->recruitingKindDeclared($objects)) {
      $violations[] = 's2_recruiting_application_kind';
    }
    if ($this->tenantStringFound($contract, $scan_files)) {
      $violations[] = 'no_tenant_specific_strings';
    }
    if ($this->hostPrefixOutsideLegacy($contract, $scan_files)) {
      $violations[] = 'no_host_prefix_outside_legacy_sources';
    }

    return array_values(array_unique($violations));
  }

  /**
   * Returns rule identifiers declared on a contract.
   *
   * @param array $contract
   *   Decoded ontology document.
   *
   * @return string[]
   *   Rule identifiers.
   */
  private function ruleIds(array $contract): array {
    $ids = [];
    foreach ($contract['rules'] ?? [] as $rule) {
      if (is_array($rule) && isset($rule['id']) && is_string($rule['id'])) {
        $ids[] = $rule['id'];
      }
    }
    return $ids;
  }

  /**
   * Files scanned for tenant-specific strings on the published contract.
   *
   * @return string[]
   *   Absolute paths.
   */
  private function publishedScanPaths(): array {
    return [
      $this->path('ontology/ontology.yml'),
      $this->path('docs/ONTOLOGY.md'),
      $this->path('ontology/objects.schema.json'),
      $this->path('ontology/actions.schema.json'),
      $this->path('ontology/links.schema.json'),
      $this->path('ontology/s2_transfer_map.schema.json'),
    ];
  }

  /**
   * Groups transfer-map rows by source id.
   *
   * @param array $map
   *   Slice 2 transfer map.
   *
   * @return array
   *   Rows keyed by source, each a list of row arrays.
   */
  private function transferRowsBySource(array $map): array {
    $index = [];
    foreach ($map as $row) {
      if (!is_array($row) || !isset($row['source'])) {
        continue;
      }
      $index[(string) $row['source']][] = $row;
    }
    return $index;
  }

  /**
   * Returns copy-disposition rows from a source's transfer rows.
   *
   * @param array $rows
   *   Transfer-map rows for one source.
   *
   * @return array
   *   Rows whose disposition is copy.
   */
  private function copyRows(array $rows): array {
    $copy = [];
    foreach ($rows as $row) {
      if (is_array($row) && ($row['disposition'] ?? '') === 'copy') {
        $copy[] = $row;
      }
    }
    return $copy;
  }

  /**
   * Indexes object types by machine name.
   *
   * @param array $contract
   *   Decoded ontology document.
   *
   * @return array
   *   Object definitions keyed by machine name.
   */
  private function objectsByMachineName(array $contract): array {
    $index = [];
    foreach ($contract['objects'] ?? [] as $object) {
      if (is_array($object) && isset($object['machine_name'])) {
        $index[(string) $object['machine_name']] = $object;
      }
    }
    return $index;
  }

  /**
   * Returns declared property names for one object type.
   *
   * @param array $object
   *   Object type definition.
   *
   * @return string[]
   *   Property names.
   */
  private function propertyNames(array $object): array {
    $names = [];
    foreach ($object['properties'] ?? [] as $property) {
      if (is_array($property) && isset($property['name'])) {
        $names[] = (string) $property['name'];
      }
    }
    return $names;
  }

  /**
   * Checks that every property has a known marking.
   *
   * @param array $object
   *   Object type definition.
   *
   * @return bool
   *   TRUE when every property is marked.
   */
  private function propertiesMarked(array $object): bool {
    $allowed = [
      'public',
      'internal',
      'confidential',
      'personal',
      'financial',
    ];
    $properties = $object['properties'] ?? NULL;
    if (!is_array($properties) || $properties === []) {
      return FALSE;
    }
    foreach ($properties as $property) {
      if (!is_array($property) || !in_array($property['marking'] ?? NULL, $allowed, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks identifier flags for one object type.
   *
   * @param array $object
   *   Object type definition.
   *
   * @return bool
   *   TRUE when integer id and UUID are required.
   */
  private function identifiersDeclared(array $object): bool {
    $identifiers = $object['identifiers'] ?? [];
    return is_array($identifiers)
      && ($identifiers['integer_id'] ?? FALSE) === TRUE
      && ($identifiers['uuid'] ?? FALSE) === TRUE
      && array_key_exists('revision_id', $identifiers);
  }

  /**
   * Checks that the revisionable flag matches the revision identifier.
   *
   * @param array $object
   *   Object type definition.
   *
   * @return bool
   *   TRUE when the flags agree.
   */
  private function revisionMatches(array $object): bool {
    $revisionable = $object['revisionable'] ?? NULL;
    $revision_id = $object['identifiers']['revision_id'] ?? NULL;
    return is_bool($revisionable) && $revisionable === $revision_id;
  }

  /**
   * Checks that cross-tenant reads are denied.
   *
   * @param array $object
   *   Object type definition.
   *
   * @return bool
   *   TRUE when the tenant scope denies cross-tenant reads.
   */
  private function crossTenantDenied(array $object): bool {
    return ($object['tenant_scope']['cross_tenant_reads'] ?? NULL) === 'denied';
  }

  /**
   * Checks that a machine name uses the agency prefix.
   *
   * @param mixed $name
   *   Candidate machine name.
   *
   * @return bool
   *   TRUE when the name is an agency type id.
   */
  private function machineNamePrefixed(mixed $name): bool {
    return is_string($name) && preg_match('/^agency_[a-z0-9_]+$/', $name) === 1;
  }

  /**
   * Checks that an action declares a permission.
   *
   * @param array $action
   *   Action type definition.
   *
   * @return bool
   *   TRUE when permission id and kind are present.
   */
  private function permissionDeclared(array $action): bool {
    $permission = $action['permission'] ?? NULL;
    return is_array($permission)
      && isset($permission['id'], $permission['kind'])
      && is_string($permission['id'])
      && $permission['id'] !== ''
      && is_string($permission['kind'])
      && $permission['kind'] !== '';
  }

  /**
   * Checks that an action declares evidence fields and forbidden items.
   *
   * @param array $action
   *   Action type definition.
   *
   * @return bool
   *   TRUE when the evidence block is complete.
   */
  private function evidenceDeclared(array $action): bool {
    $evidence = $action['evidence'] ?? NULL;
    return is_array($evidence)
      && array_key_exists('emitted', $evidence)
      && is_array($evidence['fields'] ?? NULL)
      && is_array($evidence['forbidden'] ?? NULL)
      && $evidence['forbidden'] !== [];
  }

  /**
   * Checks that lifecycle transitions name declared states and actions.
   *
   * @param array $objects
   *   Object type definitions.
   * @param string[] $action_ids
   *   Declared action identifiers.
   *
   * @return bool
   *   TRUE when every transition is closed.
   */
  private function transitionsClosed(array $objects, array $action_ids): bool {
    if ($action_ids === []) {
      return FALSE;
    }
    foreach ($objects as $object) {
      if (!is_array($object)) {
        return FALSE;
      }
      $states = ['none'];
      foreach ($object['lifecycle']['states'] ?? [] as $state) {
        if (is_array($state) && isset($state['id'])) {
          $states[] = (string) $state['id'];
        }
      }
      foreach ($object['lifecycle']['transitions'] ?? [] as $transition) {
        if (!is_array($transition)
          || !in_array($transition['from'] ?? NULL, $states, TRUE)
          || !in_array($transition['to'] ?? NULL, $states, TRUE)
          || !in_array($transition['action'] ?? NULL, $action_ids, TRUE)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Checks link cardinality, ownership and endpoint types.
   *
   * @param array $links
   *   Link type definitions.
   * @param string[] $object_ids
   *   Declared object identifiers.
   *
   * @return bool
   *   TRUE when every link is complete and closed.
   */
  private function linksComplete(array $links, array $object_ids): bool {
    if ($links === [] || $object_ids === []) {
      return FALSE;
    }
    $cardinalities = ['1:1', '1:N', 'N:1', 'N:M'];
    $owners = ['from', 'to', 'join'];
    foreach ($links as $link) {
      if (!is_array($link)
        || !in_array($link['cardinality'] ?? NULL, $cardinalities, TRUE)
        || !in_array($link['reference_owner'] ?? NULL, $owners, TRUE)
        || !in_array($link['from'] ?? NULL, $object_ids, TRUE)
        || !in_array($link['to'] ?? NULL, $object_ids, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Checks that projections exist and only name declared properties.
   *
   * @param array $contract
   *   Decoded ontology document.
   * @param array $objects
   *   Object type definitions.
   *
   * @return bool
   *   TRUE when projections are an allow-list.
   */
  private function projectionsAllowlisted(array $contract, array $objects): bool {
    $projections = $contract['projections'] ?? NULL;
    if (!is_array($projections) || $projections === []) {
      return FALSE;
    }
    $by_id = [];
    foreach ($objects as $object) {
      if (is_array($object) && isset($object['id'])) {
        $by_id[$object['id']] = $this->propertyNames($object);
      }
    }
    foreach ($projections as $projection) {
      if (!is_array($projection)
        || !isset($projection['audience'], $projection['object'], $projection['fields'])
        || !is_array($projection['fields'])
        || $projection['fields'] === []) {
        return FALSE;
      }
      $object = $projection['object'];
      if ($object !== 'Evidence' && !isset($by_id[$object])) {
        return FALSE;
      }
      foreach ($projection['fields'] as $field) {
        if (!is_string($field) || $field === '') {
          return FALSE;
        }
        if ($object !== 'Evidence' && !in_array($field, $by_id[$object], TRUE)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Checks that the five implemented Discovery types are present.
   *
   * @param array $objects
   *   Object type definitions.
   *
   * @return bool
   *   TRUE when implemented Discovery storage is declared.
   */
  private function discoveryStorageDeclared(array $objects): bool {
    $by_name = [];
    foreach ($objects as $object) {
      if (is_array($object) && isset($object['machine_name'])) {
        $by_name[(string) $object['machine_name']] = $object;
      }
    }
    foreach (self::DISCOVERY_FIELDS as $machine_name => $fields) {
      $object = $by_name[$machine_name] ?? NULL;
      if (!is_array($object) || ($object['status'] ?? NULL) !== 'implemented') {
        return FALSE;
      }
      $declared = $this->propertyNames($object);
      foreach ($fields as $field) {
        if (!in_array($field, $declared, TRUE)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Checks that slice-five types stay proposed.
   *
   * @param array $objects
   *   Object type definitions.
   *
   * @return bool
   *   TRUE when the four later types are proposed for S5.
   */
  private function sliceFiveProposed(array $objects): bool {
    $found = [];
    foreach ($objects as $object) {
      if (!is_array($object) || !isset($object['id'])) {
        continue;
      }
      if (in_array($object['id'], self::SLICE_FIVE_TYPES, TRUE)) {
        $found[] = $object['id'];
        if (($object['status'] ?? NULL) !== 'proposed'
          || ($object['slice'] ?? NULL) !== 'S5') {
          return FALSE;
        }
      }
    }
    return count($found) === count(self::SLICE_FIVE_TYPES);
  }

  /**
   * Checks semantic-version compatibility rules.
   *
   * @param array $contract
   *   Decoded ontology document.
   *
   * @return bool
   *   TRUE when compatibility uses semantic versioning.
   */
  private function compatibilitySemver(array $contract): bool {
    $compatibility = $contract['compatibility'] ?? [];
    return is_array($compatibility)
      && ($compatibility['scheme'] ?? NULL) === 'semver'
      && ($compatibility['beta_freezes_released_types'] ?? NULL) === TRUE
      && ($compatibility['breaking_change'] ?? NULL) === 'deprecation_cycle'
      && is_string($contract['version'] ?? NULL)
      && preg_match('/^\d+\.\d+\.\d+$/', $contract['version']) === 1;
  }

  /**
   * Checks that Relationship declares a proposed recruiting kind.
   *
   * @param array $objects
   *   Object type definitions.
   *
   * @return bool
   *   TRUE when the Slice 2 recruiting kind is present.
   */
  private function recruitingKindDeclared(array $objects): bool {
    foreach ($objects as $object) {
      if (!is_array($object) || ($object['id'] ?? NULL) !== 'Relationship') {
        continue;
      }
      foreach ($object['kinds'] ?? [] as $kind) {
        if (is_array($kind)
          && ($kind['id'] ?? NULL) === 'recruiting'
          && ($kind['machine_name'] ?? NULL) === 'agency_recruiting'
          && ($kind['status'] ?? NULL) === 'proposed'
          && ($kind['slice'] ?? NULL) === 'S2') {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Checks that every Discovery command is an implemented action.
   *
   * @param array $actions
   *   Action type definitions.
   *
   * @return bool
   *   TRUE when the Discovery command set is registered.
   */
  private function discoveryCommandsRegistered(array $actions): bool {
    $implemented = [];
    foreach ($actions as $action) {
      if (is_array($action)
        && ($action['status'] ?? NULL) === 'implemented'
        && isset($action['id'])) {
        $implemented[] = $action['id'];
      }
    }
    foreach (self::DISCOVERY_COMMANDS as $command) {
      if (!in_array($command, $implemented, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Detects tenant-specific strings in the contract or scanned files.
   *
   * @param array $contract
   *   Decoded ontology document.
   * @param string[] $scan_files
   *   Files to scan as text.
   *
   * @return bool
   *   TRUE when a tenant string is present.
   */
  private function tenantStringFound(array $contract, array $scan_files): bool {
    $pattern = '/Wilkes|Liberty|wilkesliberty/i';
    if ($this->valueMatches($contract, $pattern)) {
      return TRUE;
    }
    foreach ($scan_files as $file) {
      if (is_file($file)
        && preg_match($pattern, (string) file_get_contents($file)) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Detects host-prefixed identifiers outside legacy_sources.
   *
   * @param array $contract
   *   Decoded ontology document.
   * @param string[] $scan_files
   *   Files to scan as text.
   *
   * @return bool
   *   TRUE when a forbidden host prefix is present.
   */
  private function hostPrefixOutsideLegacy(array $contract, array $scan_files): bool {
    if ($this->hostPrefixInData($contract, [])) {
      return TRUE;
    }
    foreach ($scan_files as $file) {
      if (!is_file($file) || str_ends_with($file, '.yml')) {
        continue;
      }
      if (preg_match('/wl_/', (string) file_get_contents($file)) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Walks decoded data for host prefixes outside legacy_sources.
   *
   * @param mixed $data
   *   Decoded value.
   * @param string[] $path
   *   Traversal path.
   *
   * @return bool
   *   TRUE when a forbidden prefix is found.
   */
  private function hostPrefixInData(mixed $data, array $path): bool {
    $legacy = in_array('legacy_sources', $path, TRUE);
    if (is_string($data)) {
      return !$legacy && str_contains($data, 'wl_');
    }
    if (!is_array($data)) {
      return FALSE;
    }
    foreach ($data as $key => $value) {
      $next = $path;
      $next[] = is_string($key) ? $key : (string) $key;
      if ($this->hostPrefixInData($value, $next)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns whether any string value matches a pattern.
   *
   * @param mixed $data
   *   Decoded value.
   * @param string $pattern
   *   Regular expression.
   *
   * @return bool
   *   TRUE when a string matches.
   */
  private function valueMatches(mixed $data, string $pattern): bool {
    if (is_string($data)) {
      return preg_match($pattern, $data) === 1;
    }
    if (!is_array($data)) {
      return FALSE;
    }
    foreach ($data as $value) {
      if ($this->valueMatches($value, $pattern)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Validates a document section against its JSON Schema.
   *
   * @param mixed $data
   *   Section value.
   * @param string $name
   *   Schema file stem.
   *
   * @return string[]
   *   Schema error messages.
   */
  private function validateSchema(mixed $data, string $name): array {
    $path = $this->path('ontology/' . $name . '.schema.json');
    $schema = json_decode(
      (string) file_get_contents($path),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    return $this->validate($data, $schema, $name);
  }

  /**
   * Validates a value against a JSON Schema subset.
   *
   * @param mixed $data
   *   Candidate value.
   * @param array $schema
   *   Schema or subschema.
   * @param string $path
   *   Location for error messages.
   *
   * @return string[]
   *   Error messages.
   */
  private function validate(mixed $data, array $schema, string $path): array {
    $errors = [];
    if (isset($schema['type']) && !$this->typeMatches($data, $schema['type'])) {
      $errors[] = $path . ': unexpected type';
      return $errors;
    }
    if (isset($schema['enum']) && !in_array($data, $schema['enum'], TRUE)) {
      $errors[] = $path . ': value is not in the enum';
    }
    if (array_key_exists('const', $schema) && $data !== $schema['const']) {
      $errors[] = $path . ': value does not match const';
    }
    if (is_string($data)) {
      if (isset($schema['minLength']) && strlen($data) < $schema['minLength']) {
        $errors[] = $path . ': shorter than minLength';
      }
      if (isset($schema['maxLength']) && strlen($data) > $schema['maxLength']) {
        $errors[] = $path . ': longer than maxLength';
      }
      if (isset($schema['pattern'])
        && preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/', $data) !== 1) {
        $errors[] = $path . ': failed pattern';
      }
    }
    if (is_int($data) || is_float($data)) {
      if (isset($schema['minimum']) && $data < $schema['minimum']) {
        $errors[] = $path . ': below minimum';
      }
      if (isset($schema['maximum']) && $data > $schema['maximum']) {
        $errors[] = $path . ': above maximum';
      }
    }
    if (!is_array($data)) {
      return $errors;
    }
    $is_list = array_is_list($data);
    if ($is_list) {
      if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
        $errors[] = $path . ': fewer than minItems';
      }
      if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
        $errors[] = $path . ': more than maxItems';
      }
      if (!empty($schema['uniqueItems'])
        && count($data) !== count(array_unique($data, SORT_REGULAR))) {
        $errors[] = $path . ': items are not unique';
      }
      if (isset($schema['items']) && is_array($schema['items'])) {
        foreach ($data as $index => $item) {
          $errors = array_merge(
            $errors,
            $this->validate($item, $schema['items'], $path . '/' . $index),
          );
        }
      }
      return $errors;
    }
    if (isset($schema['minProperties'])
      && count($data) < $schema['minProperties']) {
      $errors[] = $path . ': fewer than minProperties';
    }
    foreach ($schema['required'] ?? [] as $required) {
      if (!array_key_exists($required, $data)) {
        $errors[] = $path . ': missing ' . $required;
      }
    }
    $properties = $schema['properties'] ?? [];
    foreach ($data as $key => $value) {
      if (isset($properties[$key]) && is_array($properties[$key])) {
        $errors = array_merge(
          $errors,
          $this->validate($value, $properties[$key], $path . '/' . $key),
        );
        continue;
      }
      $additional = $schema['additionalProperties'] ?? TRUE;
      if ($additional === FALSE) {
        $errors[] = $path . ': unexpected ' . $key;
      }
      elseif (is_array($additional)) {
        $errors = array_merge(
          $errors,
          $this->validate($value, $additional, $path . '/' . $key),
        );
      }
    }
    return $errors;
  }

  /**
   * Checks a JSON Schema type declaration.
   *
   * @param mixed $data
   *   Candidate value.
   * @param mixed $type
   *   Type name or list of type names.
   *
   * @return bool
   *   TRUE when the value matches.
   */
  private function typeMatches(mixed $data, mixed $type): bool {
    $types = is_array($type) ? $type : [$type];
    foreach ($types as $candidate) {
      $ok = match ($candidate) {
        'object' => is_array($data) && !array_is_list($data),
        'array' => is_array($data) && array_is_list($data),
        'string' => is_string($data),
        'integer' => is_int($data),
        'number' => is_int($data) || is_float($data),
        'boolean' => is_bool($data),
        'null' => $data === NULL,
        default => FALSE,
      };
      if ($ok) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads a YAML document from the module tree.
   *
   * @param string $relative
   *   Path relative to the module root.
   *
   * @return array
   *   Decoded document.
   */
  private function loadYaml(string $relative): array {
    $path = $this->path($relative);
    $this->assertFileExists($path);
    if (class_exists(Yaml::class)) {
      $data = Yaml::parseFile($path);
    }
    elseif (function_exists('yaml_parse_file')) {
      $data = yaml_parse_file($path);
    }
    else {
      $this->fail('A YAML parser is required to read the ontology contract.');
    }
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Resolves a path inside the module checkout.
   *
   * @param string $relative
   *   Path relative to the module root.
   *
   * @return string
   *   Absolute path.
   */
  private function path(string $relative): string {
    return dirname(__DIR__, 3) . '/' . $relative;
  }

}
