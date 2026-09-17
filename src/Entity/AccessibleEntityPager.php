<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Mcp\Exception\ToolCallException;

/**
 * Builds stable bounded pages after per-entity access filtering.
 *
 * Core only implements SQL-level entity-query access filtering for nodes, so
 * callers pass accessCheck(TRUE) as defense in depth while the per-entity
 * $entity->access('view') check in page() is the authoritative filter for
 * every entity type.
 */
final class AccessibleEntityPager {

  /**
   * Maximum offset a cursor may carry, bounding deep OFFSET scans.
   */
  private const MAX_OFFSET = 100000;

  /**
   * Builds one access-filtered page.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Entity storage used to load candidate IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose entity view access is enforced.
   * @param string|null $cursor
   *   Opaque continuation cursor, or NULL for the first page.
   * @param int $limit
   *   Maximum number of accessible projections to return.
   * @param string $context
   *   Stable context bound into generated cursors.
   * @param callable(int, int): array<int|string, int|string> $query
   *   Callback returning candidate entity IDs from an offset and batch size.
   * @param callable(\Drupal\Core\Entity\EntityInterface): mixed $project
   *   Callback producing a response item, or NULL to omit the entity.
   *
   * @return array{count: int, items: list<mixed>, next_cursor: ?string}
   *   One bounded page and its continuation cursor.
   */
  public function page(EntityStorageInterface $storage, AccountInterface $account, ?string $cursor, int $limit, string $context, callable $query, callable $project): array {
    $offset = $this->decodeCursor($cursor, $context);
    $items = [];
    $scanned = 0;
    $maxScan = max(100, $limit * 10);
    $exhausted = FALSE;

    while (\count($items) < $limit && $scanned < $maxScan) {
      $batchSize = min(max(20, $limit * 2), $maxScan - $scanned);
      $ids = array_values($query($offset, $batchSize));
      if ($ids === []) {
        $exhausted = TRUE;
        break;
      }

      $entities = $storage->loadMultiple($ids);
      $consumed = 0;
      foreach ($ids as $id) {
        $offset++;
        $scanned++;
        $consumed++;
        $entity = $entities[$id] ?? NULL;
        if (!$entity instanceof EntityInterface || !$entity->access('view', $account)) {
          continue;
        }
        $projected = $project($entity);
        if ($projected === NULL) {
          continue;
        }
        $items[] = $projected;
        if (\count($items) === $limit || $scanned === $maxScan) {
          break;
        }
      }

      if ($consumed === \count($ids) && \count($ids) < $batchSize) {
        $exhausted = TRUE;
        break;
      }
    }

    return [
      'count' => \count($items),
      'items' => $items,
      'next_cursor' => $exhausted ? NULL : $this->encodeCursor($offset, $context),
    ];
  }

  /**
   * Decodes a server-signed continuation cursor.
   *
   * The payload is HMAC-signed with the site hash salt so clients cannot
   * forge offsets, and the offset is capped so even a legitimately paginating
   * client cannot drive arbitrarily deep OFFSET scans.
   */
  private function decodeCursor(?string $cursor, string $context): int {
    if ($cursor === NULL || $cursor === '') {
      return 0;
    }
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), TRUE);
    $data = $decoded === FALSE ? NULL : json_decode($decoded, TRUE);
    if (!\is_array($data)
      || !isset($data['offset'], $data['context'], $data['signature'])
      || !\is_int($data['offset'])
      || $data['offset'] < 0
      || $data['offset'] > self::MAX_OFFSET
      || !hash_equals($this->signature($data['offset'], (string) $data['context']), (string) $data['signature'])
      || !hash_equals(hash('sha256', $context), (string) $data['context'])) {
      throw new ToolCallException('The pagination cursor is invalid for this request.');
    }
    return $data['offset'];
  }

  /**
   * Encodes a server-signed continuation cursor.
   */
  private function encodeCursor(int $offset, string $context): string {
    $contextHash = hash('sha256', $context);
    $encoded = json_encode([
      'offset' => $offset,
      'context' => $contextHash,
      'signature' => $this->signature($offset, $contextHash),
    ], JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
  }

  /**
   * HMAC binding one offset to one query context.
   */
  private function signature(int $offset, string $contextHash): string {
    return hash_hmac('sha256', $offset . ':' . $contextHash, Settings::getHashSalt());
  }

}
