<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_engagement\Unit;

use Drupal\agency_engagement\ClientMembershipPolicy;
use Drupal\agency_engagement\EngagementDecision;
use Drupal\agency_engagement\MembershipAudit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Membership audit carries reason or uid and omits leak probes.
 *
 * @group agency
 */
#[Group('agency')]
final class AgencyMembershipAuditTest extends TestCase {

  /**
   * Distinctive values that must never appear in audit evidence.
   */
  private const PROBES = [
    'kc-sub-leak-probe',
    'visitor-audit@example.test',
    '+15550101',
    'leaked-token-value',
    'secret-resume.pdf',
  ];

  /**
   * Bind refuse records portal.bind_refused without leak probes.
   */
  public function testBindRefusedIsMinimized(): void {
    $audit = new MembershipAudit();
    $row = $audit->bindRefused(ClientMembershipPolicy::REASON_MISSING_GROUP);
    $this->assertSame('portal.bind_refused', $row['operation']);
    $this->assertSame(
      ClientMembershipPolicy::REASON_MISSING_GROUP,
      $row['metadata']['reason'],
    );
    $this->assertNotSame(
      EngagementDecision::REASON_NOT_FOUND,
      $row['metadata']['reason'],
    );
    $audit->assertMinimized($row, self::PROBES);
  }

  /**
   * Role grant records portal.roles_reconciled without leak probes.
   */
  public function testRolesReconciledIsMinimized(): void {
    $audit = new MembershipAudit();
    $row = $audit->rolesReconciled(12);
    $this->assertSame('portal.roles_reconciled', $row['operation']);
    $this->assertSame(12, $row['metadata']['subject_uid']);
    $audit->assertMinimized($row, self::PROBES);
  }

  /**
   * Not-found and leak fields cannot enter the audit row.
   */
  public function testLeakFieldsAndNotFoundAreInvalid(): void {
    $audit = new MembershipAudit();
    $this->expectException(\InvalidArgumentException::class);
    $audit->bindRefused(EngagementDecision::REASON_NOT_FOUND);
  }

}
