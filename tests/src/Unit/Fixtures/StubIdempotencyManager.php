<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit\Fixtures;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\PrivateKey;
use Drupal\Core\State\StateInterface;
use Drupal\drupal_mcp\Idempotency\IdempotencyManager;

/**
 * Builds a real IdempotencyManager over an in-memory store.
 *
 * The manager is final, so tests that need its behaviour construct a real
 * instance backed by this state-holding, time-controllable store.
 */
final class StubIdempotencyManager {

  /**
   * Builds the manager and initializes its backing state.
   *
   * @param array|null $entries
   *   Stored entries, passed by reference for assertions.
   * @param int|null $now
   *   Current time, passed by reference for time travel.
   *
   * @return \Drupal\drupal_mcp\Idempotency\IdempotencyManager
   *   The manager under an in-memory store.
   */
  public static function create(?array &$entries = NULL, ?int &$now = NULL): IdempotencyManager {
    $entries = [];
    $now = 1000;

    $store = new class ($entries, $now) implements KeyValueStoreExpirableInterface {

      /**
       * Stored entries, by reference.
       */
      private array $entries;

      /**
       * Current time, by reference.
       */
      private int $now;

      /**
       * Binds the shared state by reference.
       */
      public function __construct(array &$entries, int &$now) {
        $this->entries = &$entries;
        $this->now = &$now;
      }

      /**
       * Returns the fixture collection name.
       */
      public function getCollectionName() {
        return 'drupal_mcp_idempotency';
      }

      /**
       * Tests whether a live entry exists.
       */
      public function has($key) {
        return $this->get($key) !== NULL;
      }

      /**
       * Returns a live entry value.
       */
      public function get($key, $default = NULL): mixed {
        if (isset($this->entries[$key]) && $this->entries[$key]['expire'] <= $this->now) {
          unset($this->entries[$key]);
        }
        return $this->entries[$key]['value'] ?? $default;
      }

      /**
       * Returns live entry values.
       */
      public function getMultiple(array $keys) {
        $values = [];
        foreach ($keys as $key) {
          if ($this->has($key)) {
            $values[$key] = $this->get($key);
          }
        }
        return $values;
      }

      /**
       * Returns every live entry value.
       */
      public function getAll() {
        return $this->getMultiple(array_keys($this->entries));
      }

      /**
       * Yields every live entry key.
       */
      public function getAllKeys(): iterable {
        return array_keys($this->entries);
      }

      /**
       * Stores an entry without expiry.
       */
      public function set($key, $value) {
        $this->entries[$key] = ['value' => $value, 'expire' => PHP_INT_MAX];
      }

      /**
       * Stores an entry only when the key is unused.
       */
      public function setIfNotExists($key, $value) {
        if ($this->has($key)) {
          return FALSE;
        }
        $this->set($key, $value);
        return TRUE;
      }

      /**
       * Stores multiple entries without expiry.
       */
      public function setMultiple(array $data) {
        foreach ($data as $key => $value) {
          $this->set($key, $value);
        }
      }

      /**
       * Renames one entry.
       */
      public function rename($key, $new_key) {
        if (isset($this->entries[$key])) {
          $this->entries[$new_key] = $this->entries[$key];
          unset($this->entries[$key]);
        }
      }

      /**
       * Stores an entry expiring after the given TTL.
       */
      public function setWithExpire($key, $value, $expire) {
        $this->entries[$key] = ['value' => $value, 'expire' => $this->now + $expire];
      }

      /**
       * Stores an entry only when the key is unused.
       */
      public function setWithExpireIfNotExists($key, $value, $expire) {
        if ($this->has($key)) {
          return FALSE;
        }
        $this->setWithExpire($key, $value, $expire);
        return TRUE;
      }

      /**
       * Stores multiple entries expiring after the given TTL.
       */
      public function setMultipleWithExpire(array $data, $expire) {
        foreach ($data as $key => $value) {
          $this->setWithExpire($key, $value, $expire);
        }
      }

      /**
       * Deletes one entry.
       */
      public function delete($key) {
        unset($this->entries[$key]);
      }

      /**
       * Deletes multiple entries.
       */
      public function deleteMultiple(array $keys) {
        foreach ($keys as $key) {
          unset($this->entries[$key]);
        }
      }

      /**
       * Deletes every entry.
       */
      public function deleteAll() {
        $this->entries = [];
      }

    };

    $factory = new class ($store) implements KeyValueExpirableFactoryInterface {

      /**
       * The store every collection resolves to.
       */
      public function __construct(private readonly KeyValueStoreExpirableInterface $store) {}

      /**
       * Returns the shared store.
       */
      public function get($collection) {
        return $this->store;
      }

    };

    $time = new class ($now) implements TimeInterface {

      /**
       * Current time, by reference.
       */
      private int $now;

      /**
       * Binds the shared time by reference.
       */
      public function __construct(int &$now) {
        $this->now = &$now;
      }

      /**
       * Returns the controllable current time.
       */
      public function getCurrentTime(): int {
        return $this->now;
      }

      /**
       * Returns the controllable request time.
       */
      public function getRequestTime(): int {
        return $this->now;
      }

      /**
       * Returns the controllable request micro time.
       */
      public function getRequestMicroTime(): float {
        return (float) $this->now;
      }

      /**
       * Returns the controllable current micro time.
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->now;
      }

    };

    return new IdempotencyManager(
      $factory,
      new NullLockBackend(),
      $time,
      new ConfigFactoryStub(['drupal_mcp.settings' => ['idempotency_ttl' => 300]]),
      new PrivateKey(new class() implements StateInterface {

        /**
         * Stored state values.
         */
        private array $state = ['system.private_key' => 'test-private-key'];

        /**
         * Returns one state value.
         */
        public function get($key, $default = NULL): mixed {
          return $this->state[$key] ?? $default;
        }

        /**
         * Returns multiple state values.
         */
        public function getMultiple(array $keys) {
          return array_intersect_key($this->state, array_flip($keys));
        }

        /**
         * Stores one state value.
         */
        public function set($key, $value) {
          $this->state[$key] = $value;
        }

        /**
         * Stores multiple state values.
         */
        public function setMultiple(array $data) {
          $this->state = $data + $this->state;
        }

        /**
         * Deletes one state value.
         */
        public function delete($key) {
          unset($this->state[$key]);
        }

        /**
         * Deletes multiple state values.
         */
        public function deleteMultiple(array $keys) {
          foreach ($keys as $key) {
            unset($this->state[$key]);
          }
        }

        /**
         * Deletes every state value.
         */
        public function deleteAll() {
          $this->state = [];
        }

        /**
         * Does not cache.
         */
        public function resetCache() {}

        /**
         * Returns no request-set values.
         */
        public function getValuesSetDuringRequest(string $key): ?array {
          return NULL;
        }

      }),
    );
  }

}
