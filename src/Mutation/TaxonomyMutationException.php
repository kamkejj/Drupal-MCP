<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

/**
 * A stable, safe taxonomy mutation failure.
 */
final class TaxonomyMutationException extends \RuntimeException {

  public function __construct(
    public readonly string $category,
    string $safeMessage,
  ) {
    parent::__construct($safeMessage);
  }

}
