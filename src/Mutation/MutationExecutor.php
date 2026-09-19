<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\drupal_mcp\Idempotency\IdempotencyConflictException;
use Drupal\drupal_mcp\Idempotency\IdempotencyInProgressException;
use Drupal\drupal_mcp\Idempotency\IdempotencyManager;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;

/**
 * Executes mutations behind the shared idempotency and safety envelope.
 *
 * This is the one execution policy for every mutation tool: idempotency key
 * validation, replay-flag stitching, and the mapping from internal failures
 * to safe tool errors. Providers declare schemas, hand over the caller the
 * endpoint authenticated, and delegate here; they never re-implement this
 * ladder. The write-capability account check is the handler contract
 * itself: only the token-authenticated caller reaches this executor.
 */
final class MutationExecutor {

  public function __construct(
    private readonly IdempotencyManager $idempotency,
  ) {}

  /**
   * Executes one idempotent mutation and converts failures safely.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The authenticated account invoking the mutation.
   * @param string $tool
   *   The tool name, scoping the idempotency key namespace.
   * @param array<string, mixed> $arguments
   *   The tool arguments; the idempotency key is consumed here.
   * @param callable(): array<string, mixed> $operation
   *   The mutator invocation to execute under the idempotency envelope.
   *
   * @return array<string, mixed>
   *   The operation result, with idempotency_replayed appended.
   */
  public function execute(TokenAuthUser $caller, string $tool, array $arguments, callable $operation): array {
    $key = $arguments['idempotency_key'] ?? NULL;
    if (!\is_string($key) || !preg_match('/^[A-Za-z0-9._~-]{8,128}$/D', $key)) {
      throw new ToolCallException('Invalid idempotency key.');
    }
    $payload = $arguments;
    unset($payload['idempotency_key']);
    try {
      $result = $this->idempotency->execute(
        (string) $caller->id(),
        (string) $caller->getConsumer()->uuid(),
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
    catch (MutationException $exception) {
      throw new ToolCallException($exception->getMessage());
    }
    catch (IdempotencyConflictException) {
      throw new ToolCallException('The idempotency key was already used with a different request.');
    }
    catch (IdempotencyInProgressException) {
      throw new ToolCallException('A mutation with this idempotency key is already in progress; retry later.');
    }
    catch (\Throwable) {
      throw new ToolCallException('The mutation could not be completed safely.');
    }
  }

  /**
   * Executes one deliberately non-idempotent destructive mutation.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The authenticated account invoking the mutation.
   * @param array<string, mixed> $arguments
   *   The tool arguments.
   * @param callable(): array<string, mixed> $operation
   *   The mutator invocation to execute.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function executeDestructive(TokenAuthUser $caller, array $arguments, callable $operation): array {
    try {
      return $operation();
    }
    catch (MutationException $exception) {
      throw new ToolCallException($exception->getMessage());
    }
    catch (\Throwable) {
      throw new ToolCallException('The mutation could not be completed safely.');
    }
  }

}
