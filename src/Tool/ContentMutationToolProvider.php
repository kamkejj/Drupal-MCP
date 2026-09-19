<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\drupal_mcp\Mutation\EntityMutatorInterface;
use Drupal\drupal_mcp\Mutation\MutationExecutor;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Schema\ToolAnnotations;

/**
 * Defines the narrow MCP adapter for node mutations.
 */
final class ContentMutationToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityMutatorInterface $mutator,
    private readonly MutationExecutor $executor,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $key = ToolSchema::idempotencyKey();
    $settings = $this->configFactory->get('drupal_mcp.settings');
    // Writable bundles are a strict subset of the bundles exposed for reads.
    $writableBundles = array_values(array_intersect(
      array_values((array) ($settings->get('writable_node_bundles') ?? [])),
      array_values((array) ($settings->get('node_bundles') ?? [])),
    ));
    // ponytail: scalar/text/reference field mapping only; exotic field
    // types fall back to an opaque scalar slot; add typed schemas when needed.
    $fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]];
    foreach ($writableBundles as $bundle) {
      foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
        if ($name === 'title' || isset($fields[$name]) || str_starts_with($name, 'revision_')) {
          continue;
        }
        $fields[$name] = match ($definition->getType()) {
          'text', 'text_long', 'text_with_summary' => ToolSchema::formattedText(),
          'entity_reference', 'entity_reference_revisions' => [
            'oneOf' => [
              ['type' => 'integer', 'minimum' => 1],
              ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1],
            ],
          ],
          'boolean' => ['type' => 'boolean'],
          'integer' => ['type' => 'integer'],
          'float', 'decimal' => ['type' => 'number'],
          default => ['type' => 'string'],
        };
      }
    }

    return [
      new ToolDefinition(
        name: 'drupal_content_create',
        title: 'Create content',
        description: 'Creates one node in an explicitly enabled writable node type.',
        inputSchema: ToolSchema::object(
          ['type' => ['type' => 'string', 'enum' => $writableBundles]] + $fields + ['idempotency_key' => $key],
          ['type', 'title', 'idempotency_key'],
        ),
        handler: fn (array $arguments, TokenAuthUser $caller): array => $this->executor->execute(
          $caller,
          'drupal_content_create',
          $arguments,
          fn (): array => $this->mutator->create($caller, $arguments),
        ),
        family: ToolFamily::NodeMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
      new ToolDefinition(
        name: 'drupal_content_update',
        title: 'Update content',
        description: 'Updates approved fields on one writable node with an exact revision precondition.',
        inputSchema: ToolSchema::object([
          'id' => ['type' => 'integer', 'minimum' => 1],
          'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
          'changes' => ToolSchema::object($fields) + ['minProperties' => 1],
          'idempotency_key' => $key,
        ], ['id', 'expected_revision_id', 'changes', 'idempotency_key']),
        handler: fn (array $arguments, TokenAuthUser $caller): array => $this->executor->execute(
          $caller,
          'drupal_content_update',
          $arguments,
          fn (): array => $this->mutator->update($caller, $arguments),
        ),
        family: ToolFamily::NodeMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
    ];
  }

}
