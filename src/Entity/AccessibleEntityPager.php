<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_mcp\Mcp\SignedCursor;

/**
 * Builds stable bounded pages after per-entity access filtering.
 *
 * Core only implements SQL-level entity-query access filtering for nodes, so
 * callers pass accessCheck(TRUE) as defense in depth while the per-entity
 * $entity->access('view') check in page() is the authoritative filter for
 * every entity type.
 */
final class AccessibleEntityPager {

  public function __construct(
    private readonly SignedCursor $cursor,
  ) {}

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
    $offset = $this->cursor->decode($cursor, $context);
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
      'next_cursor' => $exhausted ? NULL : $this->cursor->encode($offset, $context),
    ];
  }

}
