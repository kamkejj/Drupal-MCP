<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

/**
 * Result of an idempotent operation.
 */
final readonly class IdempotencyResult {

  public function __construct(
    public mixed $result,
    public bool $replayed,
  ) {}

}
