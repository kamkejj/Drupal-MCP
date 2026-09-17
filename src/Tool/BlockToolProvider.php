<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Entity\AccessibleEntityPager;
use Drupal\drupal_mcp\Entity\EntityProjection;
use Drupal\drupal_mcp\Mcp\Limits;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Reusable block content inspection tools.
 *
 * Only reusable block content entities, never block placement configuration
 * or Layout Builder internals. Core access requires published, reusable
 * blocks (or the block library permissions).
 */
final class BlockToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccessibleEntityPager $pager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      new ToolDefinition(
        name: 'drupal_blocks_list',
        title: 'List reusable blocks',
        description: 'Pages through reusable block content (body text blocks) the caller may view. Does not expose block placement or layout configuration.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'type' => ['type' => 'string', 'description' => 'Optional block content type machine name, e.g. "basic".'],
            'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
            'limit' => ['type' => 'integer', 'minimum' => 1],
          ],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->blocksList(
          $args['type'] ?? NULL,
          $args['cursor'] ?? NULL,
          isset($args['limit']) ? (int) $args['limit'] : NULL,
        ),
        family: 'blocks',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_block_get',
        title: 'Read reusable block',
        description: 'Returns one reusable block content item with metadata and body text rendered through Drupal\'s text filtering.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['id' => ['type' => 'integer', 'minimum' => 1]],
          'required' => ['id'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->blockGet((int) $args['id']),
        family: 'blocks',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function blocksList(?string $type, ?string $cursor, ?int $limit): array {
    $pageLimit = Limits::clamp($this->configFactory, $limit);
    $storage = $this->entityTypeManager->getStorage('block_content');
    $account = $this->currentUser->getAccount();
    $page = $this->pager->page(
      $storage,
      $account,
      $cursor,
      $pageLimit,
      json_encode(['blocks', $type], JSON_THROW_ON_ERROR),
      function (int $offset, int $length) use ($storage, $type): array {
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          ->sort('id', 'ASC')
          ->range($offset, $length);
        if ($type !== NULL) {
          $query->condition('type', $type);
        }
        return $query->execute();
      },
      function ($block) use ($account): array {
        $meta = EntityProjection::entityMeta($block, $account);
        unset($meta['uuid']);
        return $meta;
      },
    );
    return ['limit' => $pageLimit] + $page;
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function blockGet(int $id): array {
    $block = $this->entityTypeManager->getStorage('block_content')->load($id);
    if ($block === NULL) {
      throw new ToolCallException(sprintf('Block %d does not exist.', $id));
    }
    $account = $this->currentUser->getAccount();
    if (!$block->access('view', $account)) {
      throw new ToolCallException(sprintf('Block %d is not accessible.', $id));
    }
    $projected = EntityProjection::fieldValues($block, $account);
    return [
      'item' => EntityProjection::entityMeta($block, $account),
      'fields' => $projected['values'],
      'fields_withheld' => $projected['fields_withheld'],
    ];
  }

}
