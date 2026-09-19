<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Mcp\Schema\ToolAnnotations;

/**
 * An explicitly registered MCP tool exposed by this module.
 *
 * Tools are never discovered by scanning; each provider returns explicit
 * definitions and the registry filters them per caller before the server is
 * built for the current request. Handlers receive the caller explicitly:
 * they are closures taking the schema-validated argument bag and the
 * authenticated TokenAuthUser for this request, and never read the ambient
 * account proxy.
 */
final class ToolDefinition {

  public function __construct(
    public readonly string $name,
    public readonly string $title,
    public readonly string $description,
    public readonly array $inputSchema,
    public readonly \Closure $handler,
    public readonly ToolFamily $family,
    public readonly array $extraPermissions = [],
    public readonly ?ToolAnnotations $annotations = NULL,
    public readonly OperationCapability $capability = OperationCapability::Read,
  ) {}

  /**
   * Returns the same definition with a replacement handler.
   *
   * Used by the registry to wrap handlers (for example with error auditing)
   * without re-declaring every definition field at the wrap site.
   */
  public function withHandler(\Closure $handler): self {
    return new self(
      $this->name,
      $this->title,
      $this->description,
      $this->inputSchema,
      $handler,
      $this->family,
      $this->extraPermissions,
      $this->annotations,
      $this->capability,
    );
  }

}
