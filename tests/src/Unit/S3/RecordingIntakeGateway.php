<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit\S3;

use Drupal\agency_intake\IntakeDispatchGatewayInterface;

/**
 * Records dispatch attempts, including uncertain throws.
 */
final class RecordingIntakeGateway implements IntakeDispatchGatewayInterface {

  /**
   * Number of dispatch attempts, including uncertain throws.
   */
  public int $sends = 0;

  /**
   * Dispatch keys passed to the gateway, in order.
   *
   * @var list<string>
   */
  public array $keys = [];

  /**
   * Throwable to raise on the next dispatch, then cleared.
   */
  public ?\Throwable $next = NULL;

  /**
   * {@inheritdoc}
   */
  public function dispatch(string $dispatch_key, mixed $intent): void {
    $this->sends++;
    $this->keys[] = $dispatch_key;
    if ($this->next instanceof \Throwable) {
      $throwable = $this->next;
      $this->next = NULL;
      throw $throwable;
    }
  }

}
