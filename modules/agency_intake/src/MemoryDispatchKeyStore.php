<?php

declare(strict_types=1);

namespace Drupal\agency_intake;

use Drupal\agency_intake\Exception\IntakeRefusal;

/**
 * In-memory occupancy and digest binding for tests. Not a container service.
 */
final class MemoryDispatchKeyStore implements DispatchKeyStoreInterface {

  /**
   * Occupancy by key: reserved, uncertain or sent.
   *
   * @var array<string, string>
   */
  private array $occupancy = [];

  /**
   * Digest bound to each key.
   *
   * @var array<string, string>
   */
  private array $digests = [];

  /**
   * Key bound to each digest.
   *
   * @var array<string, string>
   */
  private array $digestKeys = [];

  /**
   * {@inheritdoc}
   */
  public function reserve(string $key, string $intent_digest = ''): void {
    if ($key === '' || $intent_digest === '') {
      throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
    }
    $bound = $this->digestKeys[$intent_digest] ?? NULL;
    if ($bound !== NULL && $bound !== $key) {
      throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
    }
    $state = $this->occupancy[$key] ?? NULL;
    if ($state === 'sent') {
      throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
    }
    if ($state !== NULL) {
      if (($this->digests[$key] ?? '') !== $intent_digest) {
        throw new IntakeRefusal(IntakeRefusal::NOT_FOUND);
      }
      return;
    }
    $this->occupancy[$key] = 'reserved';
    $this->digests[$key] = $intent_digest;
    $this->digestKeys[$intent_digest] = $key;
  }

  /**
   * {@inheritdoc}
   */
  public function occupancy(?string $key): ?string {
    if ($key === NULL || $key === '') {
      return NULL;
    }
    return $this->occupancy[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function rememberUncertain(string $key): void {
    $this->occupancy[$key] = 'uncertain';
  }

  /**
   * {@inheritdoc}
   */
  public function rememberSent(string $key): void {
    $this->occupancy[$key] = 'sent';
  }

}
