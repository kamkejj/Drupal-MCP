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
 * Content (node) read tools bounded by the node bundle allowlist.
 */
final class ContentToolProvider implements ToolProviderInterface {

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
        name: 'drupal_content_list',
        title: 'List content',
        description: 'Pages through published content of one explicitly enabled node type, filtered by the caller\'s view access. Use drupal_content_types_list to discover types; a type not enabled on this site is rejected.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'type' => ['type' => 'string', 'description' => 'Node type machine name.'],
            'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
            'limit' => [
              'type' => 'integer',
              'minimum' => 1,
              'description' => 'Page size, clamped to the server maximum.',
            ],
            'title_contains' => [
              'type' => 'string',
              'description' => 'Optional case-insensitive substring filter on the title.',
            ],
          ],
          'required' => ['type'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->contentList(
          $args['type'],
          $args['cursor'] ?? NULL,
          isset($args['limit']) ? (int) $args['limit'] : NULL,
          $args['title_contains'] ?? NULL,
        ),
        family: 'content',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_content_get',
        title: 'Read content',
        description: 'Returns one content item with metadata and per-field values the caller may view. Text passes through Drupal\'s text filtering; reference labels appear only when the referenced entity is viewable.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'id' => ['type' => 'integer', 'minimum' => 1],
          ],
          'required' => ['id'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->contentGet((int) $args['id']),
        family: 'content',
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
  private function contentList(string $type, ?string $cursor, ?int $limit, ?string $titleContains): array {
    $pageLimit = Limits::clamp($this->configFactory, $limit);
    $enabled = $this->enabledBundles();
    if (!\in_array($type, $enabled, TRUE)) {
      throw new ToolCallException(sprintf('Content type "%s" is not exposed through MCP.', $type));
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $account = $this->currentUser->getAccount();
    $page = $this->pager->page(
      $storage,
      $account,
      $cursor,
      $pageLimit,
      json_encode(['content', $type, $titleContains], JSON_THROW_ON_ERROR),
      function (int $offset, int $length) use ($storage, $type, $titleContains): array {
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('type', $type)
          ->sort('nid', 'ASC')
          ->range($offset, $length);
        if ($titleContains !== NULL) {
          $query->condition('title', $titleContains, 'CONTAINS');
        }
        return $query->execute();
      },
      function ($node) use ($account): array {
        $meta = EntityProjection::entityMeta($node, $account);
        unset($meta['uuid']);
        return $meta;
      },
    );
    return [
      'type' => $type,
      'limit' => $pageLimit,
    ] + $page;
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function contentGet(int $id): array {
    $node = $this->entityTypeManager->getStorage('node')->load($id);
    if ($node === NULL) {
      throw new ToolCallException(sprintf('Content item %d does not exist.', $id));
    }
    if (!\in_array($node->bundle(), $this->enabledBundles(), TRUE)) {
      throw new ToolCallException(sprintf('Content type "%s" is not exposed through MCP.', $node->bundle()));
    }
    $account = $this->currentUser->getAccount();
    if (!$node->access('view', $account)) {
      throw new ToolCallException(sprintf('Content item %d is not accessible.', $id));
    }
    $projected = EntityProjection::fieldValues($node, $account);
    return [
      'item' => EntityProjection::entityMeta($node, $account),
      'fields' => $projected['values'],
      'fields_withheld' => $projected['fields_withheld'],
    ];
  }

  /**
   * Executes the operation.
   *
   * @return list<string>
   *   The operation result.
   */
  private function enabledBundles(): array {
    return array_values((array) ($this->configFactory->get('drupal_mcp.settings')->get('node_bundles') ?? []));
  }

}
