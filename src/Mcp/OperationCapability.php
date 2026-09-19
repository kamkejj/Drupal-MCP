<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The authorization capability required by an MCP operation.
 */
enum OperationCapability: string {

  case Read = 'read';
  case Write = 'write';

  /**
   * Returns the configuration key containing this capability's OAuth scope.
   */
  public function scopeConfigKey(): string {
    return $this === self::Read ? 'read_scope' : 'write_scope';
  }

  /**
   * Returns the default OAuth scope for this capability.
   */
  public function defaultScope(): string {
    return $this === self::Read ? 'mcp:read' : 'mcp:write';
  }

  /**
   * Returns the Drupal permission required for this capability.
   */
  public function permission(): string {
    return 'access mcp ' . $this->value;
  }

  /**
   * Returns the configured OAuth scope for this capability.
   *
   * Falls back to the capability's default when the settings key is absent
   * or empty. This is the single resolution point; callers must not
   * re-implement the fallback.
   */
  public function resolveScope(ConfigFactoryInterface $configFactory): string {
    $configured = $configFactory->get('drupal_mcp.settings')->get($this->scopeConfigKey());
    return (string) ($configured ?: $this->defaultScope());
  }

}
