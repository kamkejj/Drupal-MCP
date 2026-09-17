<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

/**
 * Base exception for stable idempotency failures.
 */
abstract class IdempotencyException extends \RuntimeException {

  public function __construct(
    string $message,
    public readonly string $errorCode,
  ) {
    parent::__construct($message);
  }

}
