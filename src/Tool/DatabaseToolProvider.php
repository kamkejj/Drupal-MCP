<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\drupal_mcp\Diagnostics\DatabaseInspector;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Mcp\Schema\ToolAnnotations;

/**
 * Restricted structured reads over approved database surfaces.
 */
final class DatabaseToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly DatabaseInspector $inspector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $diagnosticPermissions = ['administer site configuration'];
    $annotations = new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE);
    return [
      new ToolDefinition(
        name: 'drupal_database_tables',
        title: 'List approved database surfaces',
        description: 'Lists only curated database views explicitly approved for MCP diagnostics. It never enumerates the underlying Drupal schema.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE],
        handler: fn (): array => $this->inspector->tables(),
        family: 'database',
        extraPermissions: $diagnosticPermissions,
        annotations: $annotations,
      ),
      new ToolDefinition(
        name: 'drupal_database_describe',
        title: 'Describe approved database surface',
        description: 'Returns the allowlisted columns and types for one approved curated database view.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['surface' => ['type' => 'string']],
          'required' => ['surface'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->inspector->describe($args['surface']),
        family: 'database',
        extraPermissions: $diagnosticPermissions,
        annotations: $annotations,
      ),
      new ToolDefinition(
        name: 'drupal_database_query',
        title: 'Query approved database surface',
        description: 'Runs a parameterized structured read against one approved curated view. Caller-supplied SQL, expressions, joins, and functions are not accepted.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'surface' => ['type' => 'string'],
            'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => TRUE],
            'filters' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => (object) [
                  'column' => ['type' => 'string'],
                  'operator' => ['type' => 'string', 'enum' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte']],
                  'value' => ['type' => ['integer', 'string']],
                ],
                'required' => ['column', 'operator', 'value'],
                'additionalProperties' => FALSE,
              ],
            ],
            'sort' => [
              'type' => 'object',
              'properties' => (object) [
                'column' => ['type' => 'string'],
                'direction' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
              ],
              'required' => ['column'],
              'additionalProperties' => FALSE,
            ],
            'limit' => ['type' => 'integer', 'minimum' => 1],
            'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
          ],
          'required' => ['surface'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->inspector->query(
          $args['surface'],
          $args['columns'] ?? [],
          $args['filters'] ?? [],
          $args['sort'] ?? NULL,
          isset($args['limit']) ? (int) $args['limit'] : NULL,
          $args['cursor'] ?? NULL,
        ),
        family: 'database',
        extraPermissions: $diagnosticPermissions,
        annotations: $annotations,
      ),
    ];
  }

}
