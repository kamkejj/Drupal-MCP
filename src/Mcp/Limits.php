<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Clamps requested page sizes to the configured server maximum.
 */
final class Limits {

  /**
   * Clamps a requested page size to the configured pagination maximum.
   */
  public static function clamp(ConfigFactoryInterface $configFactory, ?int $requested): int {
    $max = max(1, (int) ($configFactory->get('drupal_mcp.settings')->get('pagination_limit') ?: 20));
    if ($requested !== NULL && $requested > 0) {
      return min($requested, $max);
    }
    return $max;
  }

}
