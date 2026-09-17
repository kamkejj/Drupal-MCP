<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupal_mcp\Diagnostics\ApprovedDrushCommands;
use Drupal\drupal_mcp\Diagnostics\DrushRunner;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * The typed Drush diagnostics tool (family: drush).
 *
 * One tool, server-defined command IDs. This is not a shell: callers choose
 * an approved command ID and typed parameters; the argv, output projection,
 * runtime bounds, and audit are fixed server-side. Requires "administer
 * site configuration" in addition to the MCP read gates, and the command
 * family must be enabled with the specific command approved in settings.
 */
final class DrushToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly DrushRunner $runner,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $specs = ApprovedDrushCommands::all();
    $enabled = (array) ($this->configFactory->get('drupal_mcp.settings')->get('drush_commands') ?? []);
    $enum = array_values(array_intersect(array_keys($specs), $enabled));
    if ($enum === []) {
      // Fail closed: with no approved commands, do not advertise the tool.
      return [];
    }

    // Union of the enabled commands' typed parameters. Which subset applies
    // is decided per call in run() against the selected command's contract.
    $parameters = [];
    foreach ($enum as $id) {
      foreach ($specs[$id]->parameters as $name => $schema) {
        $parameters[$name] = $schema;
      }
    }

    return [
      new ToolDefinition(
        name: 'drupal_drush_run',
        title: 'Run approved Drush diagnostic',
        description: 'Runs one site-approved, non-destructive Drush diagnostic command with typed parameters. Only command IDs approved by the site administrator are accepted; unknown arguments are rejected before execution. Privileged: requires "administer site configuration".',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'command' => ['type' => 'string', 'enum' => $enum, 'description' => 'Approved diagnostic command ID.'],
            'arguments' => (object) [
              'type' => 'object',
              'description' => 'Typed parameters for the selected command.',
              'properties' => (object) $parameters,
              'additionalProperties' => FALSE,
            ],
          ],
          'required' => ['command'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->run((string) ($args['command'] ?? ''), (array) ($args['arguments'] ?? [])),
        family: 'drush',
        extraPermissions: ['administer site configuration'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function run(string $commandId, array $arguments): array {
    $settings = $this->configFactory->get('drupal_mcp.settings');
    $enabled = (array) ($settings->get('drush_commands') ?? []);
    $specs = ApprovedDrushCommands::all();

    if (!isset($specs[$commandId]) || !\in_array($commandId, $enabled, TRUE)) {
      throw new ToolCallException(sprintf('Drush diagnostic "%s" is not approved on this site.', $commandId));
    }
    return $this->runner->run($specs[$commandId], $arguments);
  }

}
