<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The one pagination-limit policy: configured maximum and clamping.
 *
 * Every paging surface resolves its page size through this module, so the
 * default and the ceiling can never drift between tools.
 */
final class Limits {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the configured server-side page-size maximum.
   */
  public function maximum(): int {
    return max(1, (int) ($this->configFactory->get('drupal_mcp.settings')->get('pagination_limit') ?: 20));
  }

  /**
   * Clamps a requested page size to the configured pagination maximum.
   */
  public function clamp(?int $requested): int {
    if ($requested !== NULL && $requested > 0) {
      return min($requested, $this->maximum());
    }
    return $this->maximum();
  }

}
