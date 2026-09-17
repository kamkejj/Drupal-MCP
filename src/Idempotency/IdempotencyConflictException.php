<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

/**
 * Indicates reuse of an idempotency key for a different payload.
 */
final class IdempotencyConflictException extends IdempotencyException {

  public function __construct() {
    parent::__construct(
      'The idempotency key was already used with a different payload.',
      'idempotency_payload_mismatch',
    );
  }

}
