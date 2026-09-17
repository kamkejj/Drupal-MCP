<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Idempotency\IdempotencyConflictException;
use Drupal\drupal_mcp\Idempotency\IdempotencyInProgressException;
use Drupal\drupal_mcp\Idempotency\IdempotencyManager;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mutation\TaxonomyMutationException;
use Drupal\drupal_mcp\Mutation\TaxonomyTermMutator;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Defines the narrow MCP adapter for taxonomy term mutations.
 */
final class TaxonomyMutationToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly TaxonomyTermMutator $mutator,
    private readonly IdempotencyManager $idempotency,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $description = [
      'type' => 'object',
      'properties' => (object) [
        'value' => ['type' => 'string'],
        'format' => ['type' => 'string'],
      ],
      'required' => ['value', 'format'],
      'additionalProperties' => FALSE,
    ];
    $fields = [
      'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
      'description' => $description,
      'parents' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'uniqueItems' => TRUE],
      'weight' => ['type' => 'integer', 'minimum' => -1000, 'maximum' => 1000],
    ];
    $key = ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._~-]+$'];
    $writableVocabularies = array_values((array) (
      $this->configFactory->get('drupal_mcp.settings')->get('writable_vocabularies') ?? []
    ));

    $tools = [
      new ToolDefinition(
        name: 'drupal_term_create',
        title: 'Create taxonomy term',
        description: 'Creates one term in an explicitly enabled writable vocabulary.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) (['vocabulary' => ['type' => 'string', 'enum' => $writableVocabularies]] + $fields + ['idempotency_key' => $key]),
          'required' => ['vocabulary', 'name', 'idempotency_key'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $arguments): array => $this->execute('drupal_term_create', $arguments, fn (): array => $this->mutator->create($arguments)),
        family: 'taxonomy_mutation',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
      new ToolDefinition(
        name: 'drupal_term_update',
        title: 'Update taxonomy term',
        description: 'Updates approved fields on one writable taxonomy term with an exact revision precondition.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
            'changes' => [
              'type' => 'object',
              'properties' => (object) $fields,
              'minProperties' => 1,
              'additionalProperties' => FALSE,
            ],
            'idempotency_key' => $key,
          ],
          'required' => ['id', 'expected_revision_id', 'changes', 'idempotency_key'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $arguments): array => $this->execute('drupal_term_update', $arguments, fn (): array => $this->mutator->update($arguments)),
        family: 'taxonomy_mutation',
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
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
            'confirm_term_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
          ],
          'required' => ['id', 'expected_revision_id', 'confirm_term_name'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $arguments): array => $this->executeDelete($arguments),
        family: 'taxonomy_mutation',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: TRUE, idempotentHint: FALSE),
        capability: OperationCapability::Write,
      );
    }

    return $tools;
  }

  /**
   * Executes the deliberately non-idempotent destructive mutation.
   */
  private function executeDelete(array $arguments): array {
    if (!$this->currentUser->getAccount() instanceof TokenAuthUser) {
      throw new ToolCallException('MCP write capability is required.');
    }
    try {
      return $this->mutator->delete($arguments);
    }
    catch (TaxonomyMutationException $exception) {
      throw new ToolCallException($exception->getMessage());
    }
    catch (\Throwable) {
      throw new ToolCallException('The taxonomy mutation could not be completed safely.');
    }
  }

  /**
   * Executes one idempotent mutation and converts failures safely.
   */
  private function execute(string $tool, array $arguments, callable $operation): array {
    $key = $arguments['idempotency_key'] ?? NULL;
    if (!\is_string($key) || !preg_match('/^[A-Za-z0-9._~-]{8,128}$/D', $key)) {
      throw new ToolCallException('Invalid idempotency key.');
    }
    $account = $this->currentUser->getAccount();
    if (!$account instanceof TokenAuthUser) {
      throw new ToolCallException('MCP write capability is required.');
    }
    $payload = $arguments;
    unset($payload['idempotency_key']);
    try {
      $result = $this->idempotency->execute(
        (string) $account->id(),
        (string) $account->getConsumer()->uuid(),
        $tool,
        $key,
        $payload,
        $operation,
      );
      $value = $result->result;
      if (\is_array($value)) {
        $value['idempotency_replayed'] = $result->replayed;
      }
      return $value;
    }
    catch (TaxonomyMutationException $exception) {
      throw new ToolCallException($exception->getMessage());
    }
    catch (IdempotencyConflictException) {
      throw new ToolCallException('The idempotency key was already used with a different request.');
    }
    catch (IdempotencyInProgressException) {
      throw new ToolCallException('A mutation with this idempotency key is already in progress; retry later.');
    }
    catch (\Throwable) {
      throw new ToolCallException('The taxonomy mutation could not be completed safely.');
    }
  }

}
