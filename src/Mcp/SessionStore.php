<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SDK session store backed by Drupal's expirable key-value storage.
 *
 * Handshake-era (2025-11-25) sessions must survive across FPM workers, so
 * they live in the database rather than process memory. Each session is
 * bound to the user ID and OAuth consumer that created it: a session id
 * presented by a different user or consumer is treated as nonexistent.
 */
final class SessionStore implements SessionStoreInterface {

  private const COLLECTION = 'drupal_mcp_session';

  /**
   * The caller bound by forAccount(), if any.
   */
  private ?TokenAuthUser $caller = NULL;

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a store whose identity binding is this caller's.
   *
   * The shared service never resolves sessions on its own: the ServerFactory
   * binds the authenticated caller per request so the user/consumer check
   * below is explicit, not ambient.
   */
  public function forAccount(TokenAuthUser $caller): self {
    $bound = clone $this;
    $bound->caller = $caller;
    return $bound;
  }

  /**
   * {@inheritdoc}
   */
  public function exists(Uuid $id): bool {
    return $this->read($id) !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function read(Uuid $id): string|false {
    $entry = $this->keyValueExpirable->get(self::COLLECTION)->get($id->toRfc4122());
    if (!\is_array($entry) || !\array_key_exists('data', $entry)) {
      return FALSE;
    }
    [$uid, $consumer] = $this->callerIdentity();
    // Defense in depth: the session only resolves for the user and consumer
    // that initialized it, even if the session id leaked.
    if ((string) $entry['uid'] !== $uid || (string) $entry['consumer'] !== $consumer) {
      return FALSE;
    }
    return $entry['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function write(Uuid $id, string $data): bool {
    [$uid, $consumer] = $this->callerIdentity();
    $ttl = max(60, (int) $this->configFactory->get('drupal_mcp.settings')->get('session_ttl'));
    $this->keyValueExpirable->get(self::COLLECTION)->setWithExpire(
      $id->toRfc4122(),
      ['uid' => $uid, 'consumer' => $consumer, 'data' => $data, 'created' => $this->time->getRequestTime()],
      $ttl,
    );
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function destroy(Uuid $id): bool {
    if ($this->read($id) === FALSE) {
      return FALSE;
    }
    $this->keyValueExpirable->get(self::COLLECTION)->delete($id->toRfc4122());
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function gc(): array {
    // Expiry is enforced by the expirable store itself and physical cleanup
    // happens through Drupal's cron garbage collection; there is nothing to
    // collect synchronously here.
    return [];
  }

  /**
   * Returns [user id, consumer uuid] as strings for binding.
   *
   * @return array{0: string, 1: string}
   *   The operation result.
   */
  private function callerIdentity(): array {
    if ($this->caller !== NULL) {
      return [(string) $this->caller->id(), (string) $this->caller->getConsumer()->uuid()];
    }
    return ['0', ''];
  }

}
