<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Idempotency;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\PrivateKey;

/**
 * Executes mutations once and persists bounded replay results.
 *
 * Caller keys are transformed with a keyed digest before storage or locking.
 * Failed executions remove their in-progress marker, allowing a later retry.
 */
final class IdempotencyManager {

  private const COLLECTION = 'drupal_mcp_idempotency';
  private const DEFAULT_TTL = 86400;
  private const MINIMUM_TTL = 60;
  private const MAXIMUM_RESULT_BYTES = 65536;

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * Executes an operation once for the supplied caller identity and payload.
   */
  public function execute(
    string $uid,
    string $clientId,
    string $toolName,
    string $callerKey,
    mixed $payload,
    callable $operation,
  ): IdempotencyResult {
    $identity = $this->identityDigest($uid, $clientId, $toolName, $callerKey);
    $payloadDigest = $this->payloadDigest($payload);
    $lockName = self::COLLECTION . ':' . $identity;
    $configuredTtl = $this->configFactory->get('drupal_mcp.settings')->get('idempotency_ttl');
    $ttl = max(self::MINIMUM_TTL, (int) ($configuredTtl ?? self::DEFAULT_TTL));

    if (!$this->lock->acquire($lockName, $ttl)) {
      throw new IdempotencyInProgressException();
    }

    $store = $this->keyValueExpirable->get(self::COLLECTION);
    try {
      $entry = $store->get($identity);
      if (\is_array($entry)) {
        if (!hash_equals((string) ($entry['payload_digest'] ?? ''), $payloadDigest)) {
          throw new IdempotencyConflictException();
        }
        if (($entry['state'] ?? NULL) === 'complete' && \array_key_exists('result', $entry)) {
          return new IdempotencyResult($entry['result'], TRUE);
        }
        if (($entry['state'] ?? NULL) === 'in_progress') {
          throw new IdempotencyInProgressException();
        }
      }

      $store->setWithExpire($identity, [
        'state' => 'in_progress',
        'payload_digest' => $payloadDigest,
        'started' => $this->time->getCurrentTime(),
      ], $ttl);

      try {
        $result = $operation();
        $encodedResult = serialize($result);
        if (\strlen($encodedResult) > self::MAXIMUM_RESULT_BYTES) {
          throw new IdempotencyResultTooLargeException();
        }
      }
      catch (\Throwable $exception) {
        $store->delete($identity);
        throw $exception;
      }

      $store->setWithExpire($identity, [
        'state' => 'complete',
        'payload_digest' => $payloadDigest,
        'result' => $result,
        'completed' => $this->time->getCurrentTime(),
      ], $ttl);
      return new IdempotencyResult($result, FALSE);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Returns a non-reversible identity that isolates every caller dimension.
   */
  public function identityDigest(string $uid, string $clientId, string $toolName, string $callerKey): string {
    $identity = json_encode([$uid, $clientId, $toolName, $callerKey], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return hash_hmac('sha256', $identity, $this->privateKey->get());
  }

  /**
   * Returns a digest of a deterministic, type-preserving payload encoding.
   */
  public function payloadDigest(mixed $payload): string {
    return hash('sha256', json_encode(
      $this->canonicalize($payload),
      JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
  }

  /**
   * Canonicalizes maps recursively while preserving list order and scalars.
   */
  private function canonicalize(mixed $value): mixed {
    if (!\is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      return array_map($this->canonicalize(...), $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as &$item) {
      $item = $this->canonicalize($item);
    }
    unset($item);
    return $value;
  }

}
