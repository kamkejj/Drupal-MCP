<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\node\NodeInterface;
use Mcp\Exception\ToolCallException;

/**
 * Content (node) read tools bounded by the node bundle allowlist.
 */
final class ContentToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityReadTools $reads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      $this->reads->listTool(
        name: 'drupal_content_list',
        title: 'List content',
        description: 'Pages through published content of one explicitly enabled node type, filtered by the caller\'s view access. Use drupal_content_types_list to discover types; a type not enabled on this site is rejected.',
        family: ToolFamily::Content,
        entityTypeId: 'node',
        contextSeed: 'content',
        filters: [
          'type' => [
            'schema' => ['type' => 'string', 'description' => 'Node type machine name.'],
            'field' => 'type',
            'operator' => EntityReadTools::OP_EQUALS,
          ],
          'title_contains' => [
            'schema' => [
              'type' => 'string',
              'description' => 'Optional case-insensitive substring filter on the title.',
            ],
            'field' => 'title',
            'operator' => EntityReadTools::OP_CONTAINS,
          ],
        ],
        project: EntityReadTools::metaProject(),
        requiredFilters: ['type'],
        filterGuard: function (array $values): void {
          $this->assertExposedType($values['type']);
        },
        extraResponse: fn (array $values): array => ['type' => $values['type']],
      ),
      $this->reads->getTool(
        name: 'drupal_content_get',
        title: 'Read content',
        description: 'Returns one content item with metadata and per-field values the caller may view. Text passes through Drupal\'s text filtering; reference labels appear only when the referenced entity is viewable.',
        family: ToolFamily::Content,
        entityTypeId: 'node',
        label: 'Content item',
        guard: function (NodeInterface $node): void {
          $this->assertExposedType($node->bundle());
        },
      ),
    ];
  }

  /**
   * Rejects node types outside the read allowlist.
   */
  private function assertExposedType(?string $type): void {
    $enabled = array_values((array) ($this->configFactory->get('drupal_mcp.settings')->get('node_bundles') ?? []));
    if ($type === NULL || !\in_array($type, $enabled, TRUE)) {
      throw new ToolCallException(sprintf('Content type "%s" is not exposed through MCP.', (string) $type));
    }
  }

}
