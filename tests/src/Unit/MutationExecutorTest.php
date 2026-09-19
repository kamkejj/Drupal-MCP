<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\consumers\Entity\Consumer;
use Drupal\drupal_mcp\Mutation\MutationException;
use Drupal\drupal_mcp\Mutation\MutationExecutor;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\StubIdempotencyManager;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared mutation execution envelope through its interface.
 */
final class MutationExecutorTest extends TestCase {

  /**
   * Tests key validation runs before any idempotency or storage work.
   */
  public function testRejectsInvalidIdempotencyKey(): void {
    $executor = $this->executor();

    foreach (['short', '', ' spaces pad ', 'ünïcøde-key!!'] as $key) {
      try {
        $executor->execute($this->caller(), 'tool', ['idempotency_key' => $key], fn (): array => []);
        $this->fail('Expected an invalid key rejection.');
      }
      catch (ToolCallException $exception) {
        $this->assertSame('Invalid idempotency key.', $exception->getMessage());
      }
    }
  }

  /**
   * Tests first execution and replay stitch the replay flag.
   */
  public function testExecutesOnceAndStitchesReplayFlag(): void {
    $executor = $this->executor();
    $caller = $this->caller();
    $calls = 0;
    $operation = function () use (&$calls): array {
      $calls++;
      return ['id' => 42];
    };

    $first = $executor->execute($caller, 'tool', ['name' => 'x', 'idempotency_key' => 'abcdefgh'], $operation);
    $second = $executor->execute($caller, 'tool', ['idempotency_key' => 'abcdefgh', 'name' => 'x'], $operation);

    $this->assertFalse($first['idempotency_replayed']);
    $this->assertTrue($second['idempotency_replayed']);
    $this->assertSame(42, $second['id']);
    $this->assertSame(1, $calls);
  }

  /**
   * Tests the idempotency namespace is bound to the caller and consumer.
   */
  public function testKeysAreScopedPerCaller(): void {
    $executor = $this->executor();
    $calls = 0;
    $operation = function () use (&$calls): array {
      $calls++;
      return ['id' => 42];
    };

    $executor->execute($this->caller(), 'tool', ['idempotency_key' => 'abcdefgh'], $operation);
    $executor->execute($this->caller(8, 'other-consumer'), 'tool', ['idempotency_key' => 'abcdefgh'], $operation);

    $this->assertSame(2, $calls);
  }

  /**
   * Tests mutation failures surface their safe message.
   */
  public function testMapsMutationExceptionMessage(): void {
    $executor = $this->executor();

    try {
      $executor->execute($this->caller(), 'tool', ['idempotency_key' => 'abcdefgh'], static fn (): array => throw new MutationException(
        'not_found',
        'The entity was not found or is inaccessible.',
      ));
      $this->fail('Expected a mutation failure.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('The entity was not found or is inaccessible.', $exception->getMessage());
    }
  }

  /**
   * Tests unexpected failures collapse to a generic safe message.
   */
  public function testMapsUnexpectedFailureSafely(): void {
    $executor = $this->executor();
    $caller = $this->caller();
    $boom = static fn (): array => throw new \RuntimeException('database password is hunter2');

    try {
      $executor->execute($caller, 'tool', ['idempotency_key' => 'abcdefgh'], $boom);
      $this->fail('Expected an unexpected failure.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('The mutation could not be completed safely.', $exception->getMessage());
    }

    try {
      $executor->executeDestructive($caller, ['id' => 5], $boom);
      $this->fail('Expected an unexpected failure.');
    }
    catch (ToolCallException $exception) {
      $this->assertSame('The mutation could not be completed safely.', $exception->getMessage());
    }
  }

  /**
   * Tests destructive execution skips the idempotency envelope.
   */
  public function testDestructiveExecutionSkipsIdempotency(): void {
    $executor = $this->executor();
    $caller = $this->caller();
    $calls = 0;
    $operation = function () use (&$calls): array {
      $calls++;
      return ['status' => 'deleted'];
    };

    $result = $executor->executeDestructive($caller, ['id' => 5], $operation);
    $result = $executor->executeDestructive($caller, ['id' => 5], $operation);

    $this->assertSame(['status' => 'deleted'], $result);
    $this->assertSame(2, $calls);
  }

  /**
   * Builds an executor over the stub idempotency manager.
   */
  private function executor(): MutationExecutor {
    $entries = NULL;
    $now = NULL;
    return new MutationExecutor(
      StubIdempotencyManager::create($entries, $now),
    );
  }

  /**
   * Builds a write-capable token caller.
   */
  private function caller(int $uid = 7, string $consumerUuid = 'consumer-uuid'): TokenAuthUser {
    $consumer = $this->createMock(Consumer::class);
    $consumer->method('uuid')->willReturn($consumerUuid);
    $account = $this->createMock(TokenAuthUser::class);
    $account->method('getConsumer')->willReturn($consumer);
    $account->method('id')->willReturn($uid);
    return $account;
  }

}
