<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\StatementMigration;

/**
 * Qualified, confidential source evidence for an existing challenge receipt.
 *
 * @internal
 */
final class ChallengeProjection {

  /**
   * Creates a transient receipt tied to the qualified statement identity.
   *
   * @param array{string, 'statement', int} $statement
   *   The receipt's qualified statement identity.
   * @param string $actorUuid
   *   The original actor's verified source UUID.
   * @param array{id: int, document: int, actor: int, retry_key: string, digest: string, reason: string, created: int} $row
   *   Original receipt fields, including the opaque digest.
   */
  public function __construct(
    public readonly array $statement,
    public readonly string $actorUuid,
    private readonly array $row,
  ) {}

  /**
   * Returns the original receipt without replaying its business action.
   *
   * @return array{id: int, document: int, actor: int, retry_key: string, digest: string, reason: string, created: int}
   *   The original receipt row.
   */
  public function inverse(): array {
    return $this->row;
  }

}
