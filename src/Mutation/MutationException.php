<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

/**
 * A stable, safe mutation failure shared by every mutator.
 *
 * The message is safe for the client; the category is a stable
 * machine-readable classification for audit records.
 */
class MutationException extends \RuntimeException {

  public function __construct(
    public readonly string $category,
    string $safeMessage,
  ) {
    parent::__construct($safeMessage);
  }

}
