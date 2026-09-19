<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
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
 * Defines the narrow MCP adapter for taxonomy term mutations.
 */
final class TaxonomyMutationToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityMutatorInterface $mutator,
    private readonly MutationExecutor $executor,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $fields = [
      'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
      'description' => ToolSchema::formattedText(),
      'parents' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'uniqueItems' => TRUE],
      'weight' => ['type' => 'integer', 'minimum' => -1000, 'maximum' => 1000],
    ];
    $key = ToolSchema::idempotencyKey();
    $writableVocabularies = array_values((array) (
      $this->configFactory->get('drupal_mcp.settings')->get('writable_vocabularies') ?? []
    ));

    $tools = [
      new ToolDefinition(
        name: 'drupal_term_create',
        title: 'Create taxonomy term',
        description: 'Creates one term in an explicitly enabled writable vocabulary.',
        inputSchema: ToolSchema::object(
          ['vocabulary' => ['type' => 'string', 'enum' => $writableVocabularies]] + $fields + ['idempotency_key' => $key],
          ['vocabulary', 'name', 'idempotency_key'],
        ),
        handler: fn (array $arguments, TokenAuthUser $caller): array => $this->executor->execute(
          $caller,
          'drupal_term_create',
          $arguments,
          fn (): array => $this->mutator->create($caller, $arguments),
        ),
        family: ToolFamily::TaxonomyMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
      new ToolDefinition(
        name: 'drupal_term_update',
        title: 'Update taxonomy term',
        description: 'Updates approved fields on one writable taxonomy term with an exact revision precondition.',
        inputSchema: ToolSchema::object([
          'id' => ['type' => 'integer', 'minimum' => 1],
          'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
          'changes' => ToolSchema::object($fields) + ['minProperties' => 1],
          'idempotency_key' => $key,
        ], ['id', 'expected_revision_id', 'changes', 'idempotency_key']),
        handler: fn (array $arguments, TokenAuthUser $caller): array => $this->executor->execute(
          $caller,
          'drupal_term_update',
          $arguments,
          fn (): array => $this->mutator->update($caller, $arguments),
        ),
        family: ToolFamily::TaxonomyMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
    ];

    // The destructive definition itself is omitted, rather than merely denied
    // by its handler, so list and direct lookup share the same fail-closed
    // catalogue boundary.
    if ((bool) $this->configFactory->get('drupal_mcp.settings')->get('destructive_mutations.taxonomy_terms')) {
      $tools[] = new ToolDefinition(
        name: 'drupal_term_delete',
        title: 'Delete taxonomy term',
        description: 'Permanently deletes one unreferenced writable taxonomy term'
          . ' after exact revision and name checks.',
        inputSchema: ToolSchema::object([
          'id' => ['type' => 'integer', 'minimum' => 1],
          'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
          'confirm_term_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        ], ['id', 'expected_revision_id', 'confirm_term_name']),
        handler: fn (array $arguments, TokenAuthUser $caller): array => $this->executor->executeDestructive(
          $caller,
          $arguments,
          fn (): array => $this->mutator->delete($caller, $arguments),
        ),
        family: ToolFamily::TaxonomyMutation,
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: TRUE, idempotentHint: FALSE),
        capability: OperationCapability::Write,
      );
    }

    return $tools;
  }

}
