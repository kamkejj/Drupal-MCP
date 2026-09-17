<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

/**
 * A service that contributes explicitly defined MCP tools.
 *
 * Implementations are tagged with "drupal_mcp.tool_provider" and must return
 * a finite, explicit list of tool definitions. Enabling a provider never
 * automatically exposes new entity types or capabilities beyond the
 * definitions it declares.
 */
interface ToolProviderInterface {

  /**
   * Returns the tools this provider contributes.
   *
   * @return \Drupal\drupal_mcp\Mcp\ToolDefinition[]
   *   Tool definitions, keyed or unkeyed by tool name.
   */
  public function tools(): array;

}
