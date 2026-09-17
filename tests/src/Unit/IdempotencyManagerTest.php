<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\PrivateKey;
use Drupal\drupal_mcp\Idempotency\IdempotencyConflictException;
use Drupal\drupal_mcp\Idempotency\IdempotencyInProgressException;
use Drupal\drupal_mcp\Idempotency\IdempotencyManager;
use Drupal\drupal_mcp\Idempotency\IdempotencyResultTooLargeException;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use PHPUnit\Framework\TestCase;

/**
 * Tests persisted idempotent execution through its public interface.
 */
final class IdempotencyManagerTest extends TestCase {

  /**
   * Tests recursive canonicalization while preserving list and scalar meaning.
   */
  public function testCanonicalPayloadDigest(): void {
    $manager = $this->manager($entries, $now);

    $this->assertSame(
      $manager->payloadDigest(['nested' => ['z' => 1, 'a' => TRUE], 'list' => [2, '2']]),
      $manager->payloadDigest(['list' => [2, '2'], 'nested' => ['a' => TRUE, 'z' => 1]]),
    );
    $this->assertNotSame($manager->payloadDigest([1, 2]), $manager->payloadDigest([2, 1]));
    $this->assertNotSame($manager->payloadDigest(1), $manager->payloadDigest('1'));
    $this->assertNotSame($manager->payloadDigest(['01' => 'value']), $manager->payloadDigest(['value']));
  }

  /**
   * Tests first execution and completed replay.
   */
  public function testExecutesOnceAndReplaysCompletedResult(): void {
    $manager = $this->manager($entries, $now);
    $calls = 0;
    $operation = function () use (&$calls): array {
      $calls++;
      return ['id' => 42];
    };

    $first = $manager->execute('7', 'consumer-a', 'content_create', 'opaque', ['b' => 2, 'a' => 1], $operation);
    $second = $manager->execute('7', 'consumer-a', 'content_create', 'opaque', ['a' => 1, 'b' => 2], $operation);

    $this->assertFalse($first->replayed);
    $this->assertTrue($second->replayed);
    $this->assertSame(['id' => 42], $second->result);
    $this->assertSame(1, $calls);
    $this->assertSame(1300, reset($entries)['expire']);
  }

  /**
   * Tests that a key cannot be reused with a different payload.
   */
  public function testRejectsPayloadMismatch(): void {
    $manager = $this->manager($entries, $now);
    $manager->execute('7', 'client', 'create', 'key', ['title' => 'first'], fn (): array => ['id' => 1]);

    $this->expectException(IdempotencyConflictException::class);
    $this->expectExceptionMessage('different payload');
    $manager->execute('7', 'client', 'create', 'key', ['title' => 'second'], fn (): array => ['id' => 2]);
  }

  /**
   * Tests rejection of a persisted active execution.
   */
  public function testRejectsActiveInProgressEntry(): void {
    $manager = $this->manager($entries, $now);
    $identity = $manager->identityDigest('7', 'client', 'create', 'key');
    $entries[$identity] = [
      'value' => ['state' => 'in_progress', 'payload_digest' => $manager->payloadDigest(['x' => 1])],
      'expire' => 1300,
    ];

    $this->expectException(IdempotencyInProgressException::class);
    $manager->execute('7', 'client', 'create', 'key', ['x' => 1], fn (): array => []);
  }

  /**
   * Tests isolation across every caller identity dimension.
   */
  public function testIdentityIsolation(): void {
    $manager = $this->manager($entries, $now);
    $base = $manager->identityDigest('1', 'client-a', 'tool-a', 'key');

    $this->assertNotSame($base, $manager->identityDigest('2', 'client-a', 'tool-a', 'key'));
    $this->assertNotSame($base, $manager->identityDigest('1', 'client-b', 'tool-a', 'key'));
    $this->assertNotSame($base, $manager->identityDigest('1', 'client-a', 'tool-b', 'key'));
    $this->assertNotSame($base, $manager->identityDigest('1', 'client-a', 'tool-a', 'other'));
    $this->assertStringNotContainsString('key', $base);
  }

  /**
   * Tests that failed operations remove their marker and may be retried.
   */
  public function testFailedExecutionCanBeRetried(): void {
    $manager = $this->manager($entries, $now);
    try {
      $manager->execute('1', 'client', 'tool', 'key', [], static function (): never {
        throw new \RuntimeException('failed');
      });
      $this->fail('Expected operation failure.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('failed', $exception->getMessage());
    }

    $retried = $manager->execute('1', 'client', 'tool', 'key', [], fn (): string => 'ok');
    $this->assertSame('ok', $retried->result);
    $this->assertFalse($retried->replayed);
  }

  /**
   * Tests that oversized successful results are not retained.
   */
  public function testOversizedResultIsRejectedAndCanBeRetried(): void {
    $manager = $this->manager($entries, $now);
    try {
      $manager->execute('1', 'client', 'tool', 'key', [], fn (): string => str_repeat('x', 65536));
      $this->fail('Expected oversized result failure.');
    }
    catch (IdempotencyResultTooLargeException) {
      $this->assertSame([], $entries);
    }

    $this->assertSame('ok', $manager->execute('1', 'client', 'tool', 'key', [], fn (): string => 'ok')->result);
  }

  /**
   * Tests practical expiration behavior supplied by the expirable store.
   */
  public function testExpiredCompletedEntryExecutesAgain(): void {
    $manager = $this->manager($entries, $now);
    $calls = 0;
    $operation = function () use (&$calls): int {
      return ++$calls;
    };
    $this->assertSame(1, $manager->execute('1', 'client', 'tool', 'key', [], $operation)->result);
    $now += 301;
    $this->assertSame(2, $manager->execute('1', 'client', 'tool', 'key', [], $operation)->result);
  }

  /**
   * Builds a manager with an in-memory expirable store.
   *
   * @param array<string, array{value: mixed, expire: int}>|null $entries
   *   Stored entries, passed by reference.
   * @param int|null $now
   *   Current time, passed by reference.
   */
  private function manager(?array &$entries, ?int &$now): IdempotencyManager {
    $entries = [];
    $now = 1000;
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturnCallback(function (string $key) use (&$entries, &$now): mixed {
      if (isset($entries[$key]) && $entries[$key]['expire'] <= $now) {
        unset($entries[$key]);
      }
      return $entries[$key]['value'] ?? NULL;
    });
    $store->method('setWithExpire')->willReturnCallback(function (string $key, mixed $value, int $expire) use (&$entries, &$now): void {
      $entries[$key] = ['value' => $value, 'expire' => $now + $expire];
    });
    $store->method('delete')->willReturnCallback(function (string $key) use (&$entries): void {
      unset($entries[$key]);
    });
    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')->with('drupal_mcp_idempotency')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => $now);

    $privateKey = $this->createMock(PrivateKey::class);
    $privateKey->method('get')->willReturn('test-private-key');

    return new IdempotencyManager(
      $factory,
      $lock,
      $time,
      new ConfigFactoryStub(['drupal_mcp.settings' => ['idempotency_ttl' => 300]]),
      $privateKey,
    );
  }

}
