<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

/**
 * One approved, server-defined Drush diagnostic command.
 *
 * A spec fixes everything a caller may influence: the Drush command name,
 * which typed parameters it accepts, how they become a fixed argv, and how
 * the output is projected before it leaves the server. Anything not in the
 * spec is rejected before a subprocess exists.
 */
final class DrushCommandSpec {

  /**
   * Executes the operation.
   *
   * @param string $id
   *   Server-defined command ID used by callers (e.g. "status").
   * @param string $drushCommand
   *   The literal Drush command name (e.g. "status", "pm:list").
   * @param string $description
   *   Human description advertised in the tool schema.
   * @param array<string, array<string, mixed>> $parameters
   *   JSON-Schema fragment per accepted parameter name.
   * @param \Closure $argv
   *   Fn(array $arguments, array $settings): ?list<string> — builds the
   *   fixed argv tail from validated arguments, or NULL to refuse.
   * @param \Closure $project
   *   fn(array $parsedOutput, array $arguments, array $settings): array —
   *   allowlist projection of parsed JSON output. Keys not carried over are
   *   dropped.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $drushCommand,
    public readonly string $description,
    public readonly array $parameters,
    public readonly \Closure $argv,
    public readonly \Closure $project,
  ) {}

}
