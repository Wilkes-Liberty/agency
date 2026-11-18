<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

use Drupal\Component\Uuid\Uuid;

/**
 * A host-qualified managed file identity and protected grant snapshot digest.
 *
 * Grant identifiers, signing secrets and subject hashes remain with the host
 * inventory. The digest is for in-memory comparison during qualification.
 */
final readonly class QualifiedFile {

  public function __construct(
    public int $fid,
    public string $uuid,
    public string $grantClass,
    public string $grantDigest,
  ) {
    if ($fid < 1 || !Uuid::isValid($uuid) || $grantClass === ''
      || !preg_match('/^[a-f0-9]{64}$/D', $grantDigest)) {
      throw new PersonnelFileRefusal('unqualified_file');
    }
  }

}
