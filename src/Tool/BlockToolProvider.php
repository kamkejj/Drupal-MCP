<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;

/**
 * Reusable block content inspection tools.
 *
 * Only reusable block content entities, never block placement configuration
 * or Layout Builder internals. Core access requires published, reusable
 * blocks (or the block library permissions).
 */
final class BlockToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityReadTools $reads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      $this->reads->listTool(
        name: 'drupal_blocks_list',
        title: 'List reusable blocks',
        description: 'Pages through reusable block content (body text blocks) the caller may view. Does not expose block placement or layout configuration.',
        family: ToolFamily::Blocks,
        entityTypeId: 'block_content',
        contextSeed: 'blocks',
        filters: [
          'type' => [
            'schema' => ['type' => 'string', 'description' => 'Optional block content type machine name, e.g. "basic".'],
            'field' => 'type',
            'operator' => EntityReadTools::OP_EQUALS,
          ],
        ],
        project: EntityReadTools::metaProject(),
      ),
      $this->reads->getTool(
        name: 'drupal_block_get',
        title: 'Read reusable block',
        description: 'Returns one reusable block content item with metadata and body text rendered through Drupal\'s text filtering.',
        family: ToolFamily::Blocks,
        entityTypeId: 'block_content',
        label: 'Block',
      ),
    ];
  }

}
