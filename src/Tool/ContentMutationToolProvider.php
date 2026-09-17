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
use Drupal\drupal_mcp\Mutation\NodeMutator;
use Drupal\drupal_mcp\Mutation\NodeMutationException;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Defines the narrow MCP adapter for node mutations.
 */
final class ContentMutationToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly NodeMutator $mutator,
    private readonly IdempotencyManager $idempotency,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $body = [
      'type' => 'object',
      'properties' => (object) [
        'value' => ['type' => 'string'],
        'format' => ['type' => 'string'],
      ],
      'required' => ['value', 'format'],
      'additionalProperties' => FALSE,
    ];
    $fields = [
      'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
      'body' => $body,
    ];
    $key = ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._~-]+$'];
    $settings = $this->configFactory->get('drupal_mcp.settings');
    // Writable bundles are a strict subset of the bundles exposed for reads.
    $writableBundles = array_values(array_intersect(
      array_values((array) ($settings->get('writable_node_bundles') ?? [])),
      array_values((array) ($settings->get('node_bundles') ?? [])),
    ));

    return [
      new ToolDefinition(
        name: 'drupal_content_create',
        title: 'Create content',
        description: 'Creates one node in an explicitly enabled writable node type.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) (['type' => ['type' => 'string', 'enum' => $writableBundles]] + $fields + ['idempotency_key' => $key]),
          'required' => ['type', 'title', 'idempotency_key'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $arguments): array => $this->execute('drupal_content_create', $arguments, fn (): array => $this->mutator->create($arguments)),
        family: 'node_mutation',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
      new ToolDefinition(
        name: 'drupal_content_update',
        title: 'Update content',
        description: 'Updates approved fields on one writable node with an exact revision precondition.',
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
        handler: fn (array $arguments): array => $this->execute('drupal_content_update', $arguments, fn (): array => $this->mutator->update($arguments)),
        family: 'node_mutation',
        annotations: new ToolAnnotations(readOnlyHint: FALSE, destructiveHint: FALSE, idempotentHint: TRUE),
        capability: OperationCapability::Write,
      ),
    ];
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
    catch (NodeMutationException $exception) {
      throw new ToolCallException($exception->getMessage());
    }
    catch (IdempotencyConflictException) {
      throw new ToolCallException('The idempotency key was already used with a different request.');
    }
    catch (IdempotencyInProgressException) {
      throw new ToolCallException('A mutation with this idempotency key is already in progress; retry later.');
    }
    catch (\Throwable) {
      throw new ToolCallException('The content mutation could not be completed safely.');
    }
  }

}
