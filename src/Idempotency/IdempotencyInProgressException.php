<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

/**
 * Indicates that an operation with this identity is still active.
 */
final class IdempotencyInProgressException extends IdempotencyException {

  public function __construct() {
    parent::__construct(
      'An operation with this idempotency key is already in progress.',
      'idempotency_in_progress',
    );
  }

}
