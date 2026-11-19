<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\StatementMigration;

/**
 * Qualified, confidential source evidence for one immutable statement.
 *
 * @internal
 */
final class StatementProjection {

  /**
   * Creates a transient projection with no allocated target identity.
   *
   * @param array{string, 'statement', int} $identity
   *   The original namespace, kind and serial ID.
   * @param array{id: int, kind: 'statement', client_user: int, client_profile: int, actor: int, created: int, body: string, sources: string} $row
   *   The complete original row including immutable JSON bytes.
   * @param array{client_user: string, client_profile: string, actor: string} $subjects
   *   Source UUID bindings for the corresponding original row IDs.
   * @param array $snapshot
   *   The validated snapshot and its reviewed presentation when present.
   * @param list<array{id: int, revision: int, uuid: string, engagement: int}> $sources
   *   Original entry pins in source-list order with verified bindings.
   * @param list<int> $membership
   *   The canonical source membership set.
   * @param array<int, string> $engagements
   *   Original engagement IDs mapped to verified source UUIDs.
   */
  public function __construct(
    public readonly array $identity,
    private readonly array $row,
    public readonly array $subjects,
    public readonly array $snapshot,
    public readonly array $sources,
    public readonly array $membership,
    public readonly array $engagements,
  ) {}

  /**
   * Returns the original row without decoding and re-encoding its JSON.
   *
   * The membership property supplies the separate original index rows.
   *
   * @return array{id: int, kind: 'statement', client_user: int, client_profile: int, actor: int, created: int, body: string, sources: string}
   *   The original row with complete field values.
   */
  public function inverse(): array {
    return $this->row;
  }

  /**
   * Returns the original membership rows in deterministic set order.
   *
   * @return list<array{document: int, engagement: int}>
   *   Original document and engagement keys.
   */
  public function inverseMembership(): array {
    return array_map(fn(int $id): array => [
      'document' => $this->identity[2],
      'engagement' => $id,
    ], $this->membership);
  }

}
