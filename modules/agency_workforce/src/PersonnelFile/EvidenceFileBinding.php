<?php

declare(strict_types=1);

namespace Drupal\agency_workforce\PersonnelFile;

use Drupal\Component\Uuid\Uuid;

/**
 * The identity of one ordered parent revision edge and its pinned child.
 */
final readonly class EvidenceFileBinding {

  public function __construct(
    public int $profileId,
    public string $profileUuid,
    public int $profileRevisionId,
    public string $profileLanguage,
    public string $parentField,
    public int $delta,
    public int $paragraphId,
    public string $paragraphUuid,
    public int $paragraphRevisionId,
    public string $paragraphLanguage,
    public string $fileField,
  ) {
    if ($profileId < 1 || $profileRevisionId < 1 || $paragraphId < 1
      || $paragraphRevisionId < 1 || $delta < 0
      || !Uuid::isValid($profileUuid) || !Uuid::isValid($paragraphUuid)
      || $profileLanguage === '' || $paragraphLanguage === ''
      || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $parentField)
      || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $fileField)) {
      throw new PersonnelFileRefusal('stale_source');
    }
  }

}
