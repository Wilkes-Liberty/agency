<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\PersonnelFile;

use Drupal\agency_workforce\PersonnelFile\EvidenceFileBinding;
use Drupal\agency_workforce\PersonnelFile\PersonnelFileProjector;
use Drupal\agency_workforce\PersonnelFile\PersonnelFileRefusal;
use Drupal\agency_workforce\PersonnelFile\PersonnelFileSourceInterface;
use Drupal\agency_workforce\PersonnelFile\QualifiedFile;
use Drupal\Core\Session\UserSession;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers loss, misbinding and partial projection of protected file items.
 *
 * @group agency
 */
#[Group('agency')]
final class PersonnelFileProjectorTest extends UnitTestCase {

  /**
   * An installed property is not silently stripped to produce a valid copy.
   */
  public function testUnmappedPropertyRefusesProjection(): void {
    $source = $this->createMock(PersonnelFileSourceInterface::class);
    $source->method('readItems')->willReturn([[
      'target_id' => 7,
      'display' => '0',
      'description' => NULL,
      'unreviewed_property' => 'private value',
    ],
    ]);
    $source->expects($this->never())->method('qualifyFile');

    $this->expectException(PersonnelFileRefusal::class);
    $this->expectExceptionMessage('unmapped_property');
    (new PersonnelFileProjector($source))->project($this->binding(), new UserSession(['uid' => 21]));
  }

  /**
   * A host cannot relink an existing slot to a different managed-file ID.
   */
  public function testMismatchedFileIdRefusesProjection(): void {
    $source = $this->createMock(PersonnelFileSourceInterface::class);
    $source->method('readItems')->willReturn([['target_id' => 7]]);
    $source->expects($this->once())->method('qualifyFile')->with(7)->willReturn($this->file(8));

    $this->expectException(PersonnelFileRefusal::class);
    $this->expectExceptionMessage('unqualified_file');
    (new PersonnelFileProjector($source))->project($this->binding(), new UserSession(['uid' => 21]));
  }

  /**
   * A repeated reference preserves both positions and NULL versus empty.
   */
  public function testInverseRefusesChangedRepeatedItem(): void {
    $unchanged = [
      ['target_id' => '7', 'display' => '0', 'description' => NULL],
      ['target_id' => '7', 'display' => '1', 'description' => ''],
    ];
    $changed = $unchanged;
    $changed[1]['description'] = NULL;
    $source = $this->createMock(PersonnelFileSourceInterface::class);
    $source->method('readItems')->willReturn($unchanged, $unchanged, $changed);
    $source->method('qualifyFile')->with(7)->willReturn($this->file(7));

    $actor = new UserSession(['uid' => 21]);
    $projector = new PersonnelFileProjector($source);
    $projection = $projector->project($this->binding(), $actor);
    $this->assertSame([
      ['target_id' => 7, 'display' => FALSE, 'description' => NULL],
      ['target_id' => 7, 'display' => TRUE, 'description' => ''],
    ], $projector->preflightInverse($projection, $actor));

    $this->expectException(PersonnelFileRefusal::class);
    $this->expectExceptionMessage('file_projection_drift');
    $projector->preflightInverse($projection, $actor);
  }

  /**
   * Builds a synthetic edge with separate parent and child identities.
   */
  private function binding(): EvidenceFileBinding {
    return new EvidenceFileBinding(
      12, '62d52a44-2923-4e80-a9f4-9781b21cfdd2', 16, 'fr-ca', 'field_evidence', 2,
      31, 'c59c7ad3-b0a3-4786-b735-72cf75268079', 22, 'en', 'field_evidence_file',
    );
  }

  /**
   * Builds an opaque qualification result from the host port.
   */
  private function file(int $fid): QualifiedFile {
    return new QualifiedFile($fid, 'b306d77d-c1c0-4f78-8592-76a99ea09981', 'operator', hash('sha256', 'fixture'));
  }

}
