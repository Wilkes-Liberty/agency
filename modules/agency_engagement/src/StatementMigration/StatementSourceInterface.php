<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\StatementMigration;

/**
 * Reads reviewed source mappings in one stable, writer-fenced snapshot.
 *
 * @internal
 */
interface StatementSourceInterface {

  /**
   * Returns the shared serial row, including an occupied invoice identity.
   */
  public function document(int $id): ?array;

  /**
   * Returns the document's original engagement membership IDs.
   *
   * @return list<int>
   *   Original engagement IDs in any order.
   */
  public function membership(int $document): array;

  /**
   * Resolves an unambiguous source user or Profile ID to its source UUID.
   */
  public function identity(string $type, int $id): ?string;

  /**
   * Verifies that the Profile is a client Profile with the specified owner.
   */
  public function ownsProfile(int $profile, int $user): bool;

  /**
   * Resolves an exact pinned revision with approved evidence at issuance.
   *
   * @return array{uuid: string, engagement: int, date: string, seconds: int, public_summary: string, source_langcode: string, source_hash: string}|null
   *   Its stable identity and frozen source values, or NULL if unqualified.
   */
  public function approvedEntry(int $id, int $revision, int $document, int $issued): ?array;

  /**
   * Resolves unambiguous engagement evidence at issuance.
   *
   * A non-NULL revision is the provenance of frozen title prose. Older
   * presentations require an unambiguous historical source mapping.
   *
   * @return array{uuid: string, client_user: int, client_profile: int, title: string, source_langcode: string, source_hash: string}|null
   *   The historical engagement and bill-to anchors, or NULL if unqualified.
   */
  public function engagement(int $id, int $issued, ?int $revision): ?array;

  /**
   * Returns a challenge by its original serial identity.
   */
  public function challenge(int $id): ?array;

  /**
   * Returns a challenge by its original actor/retry key.
   */
  public function challengeByRetry(int $actor, string $key): ?array;

}
