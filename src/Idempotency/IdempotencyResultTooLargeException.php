<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

/**
 * Indicates that a successful result is too large to retain safely.
 */
final class IdempotencyResultTooLargeException extends IdempotencyException {

  public function __construct() {
    parent::__construct(
      'The operation result exceeds the idempotency replay limit.',
      'idempotency_result_too_large',
    );
  }

}
