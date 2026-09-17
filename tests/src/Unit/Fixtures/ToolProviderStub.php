<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit\Fixtures;

use Drupal\drupal_mcp\Mcp\ToolProviderInterface;

/**
 * Explicit tool provider used by registry unit tests.
 */
final class ToolProviderStub implements ToolProviderInterface {

  public function __construct(private readonly array $definitions) {}

  /**
   * Returns the explicit tool definitions.
   */
  public function tools(): array {
    return $this->definitions;
  }

}
