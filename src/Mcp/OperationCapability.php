<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

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

}
