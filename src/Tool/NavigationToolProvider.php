<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\Core\Url;
use Drupal\path_alias\PathAliasInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Menu tree and path alias inspection tools.
 *
 * Menu trees are evaluated with core's link access manipulator so
 * administrative or otherwise inaccessible links never appear. Aliases are
 * administrative configuration and fail closed behind the native permission.
 */
final class NavigationToolProvider implements ToolProviderInterface {

  /**
   * Maximum number of links one menu response may contain.
   */
  private const MAX_MENU_LINKS = 200;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MenuLinkTreeInterface $menuLinkTree,
    private readonly EntityReadTools $reads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      new ToolDefinition(
        name: 'drupal_menu_get',
        title: 'Read menu tree',
        description: 'Returns one menu\'s tree of links as visible to the caller. Inaccessible links are removed by Drupal\'s menu link access evaluation; raw plugin-defined admin links stay hidden unless the caller may see them.',
        inputSchema: ToolSchema::object([
          'menu' => ['type' => 'string', 'description' => 'Menu machine name, e.g. "main", "footer", "account".'],
          'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9],
        ], ['menu']),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->menuGet($args['menu'], isset($args['max_depth']) ? (int) $args['max_depth'] : NULL),
        family: ToolFamily::Menus,
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      $this->reads->listTool(
        name: 'drupal_aliases_list',
        title: 'List path aliases',
        description: 'Pages through path aliases whose targets the caller can access. Administrative: requires the "administer url aliases" permission; this fails closed because alias entities have no view access of their own.',
        family: ToolFamily::Aliases,
        entityTypeId: 'path_alias',
        contextSeed: 'aliases',
        filters: [
          'alias_prefix' => [
            'schema' => ['type' => 'string'],
            'field' => 'alias',
            'operator' => EntityReadTools::OP_STARTS_WITH,
          ],
        ],
        project: fn ($alias, TokenAuthUser $caller): ?array => $this->projectAlias($alias, $caller),
        extraPermissions: ['administer url aliases'],
        // Alias entities carry no view access of their own; the per-row
        // projector is the authoritative target-access filter.
        accessCheck: FALSE,
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function menuGet(string $menu, ?int $maxDepth): array {
    if ($this->entityTypeManager->getStorage('menu')->load($menu) === NULL) {
      throw new ToolCallException(sprintf('Unknown menu "%s".', $menu));
    }

    // Neutral parameters: route-derived parameters would prune every submenu
    // whose parent is not flagged "expanded", because the MCP endpoint route
    // is never part of an active menu trail.
    $parameters = (new MenuTreeParameters())->onlyEnabledLinks();
    if ($maxDepth !== NULL) {
      $parameters->setMaxDepth($maxDepth);
    }
    $elements = $this->menuLinkTree->load($menu, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $elements = $this->menuLinkTree->transform($elements, $manipulators);

    $state = new \stdClass();
    $links = $this->projectTree($elements, $state);
    return [
      'menu' => $menu,
      'links' => $links,
      'link_count' => $state->count ?? 0,
      'truncated' => $state->truncated ?? FALSE,
    ];
  }

  /**
   * Projects accessible menu-link tree elements.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $elements
   *   Transformed menu-link tree elements.
   * @param \stdClass $state
   *   Accumulator carrying the emitted-link count and truncation flag.
   *
   * @return list<array<string, mixed>>
   *   Accessible menu-link projections.
   */
  private function projectTree(array $elements, \stdClass $state): array {
    $out = [];
    foreach ($elements as $element) {
      if (($state->count ?? 0) >= self::MAX_MENU_LINKS) {
        $state->truncated = TRUE;
        return $out;
      }
      if (!$element->access->isAllowed()) {
        continue;
      }
      $link = $element->link;
      $url = $link->getUrlObject();
      $entry = [
        'plugin_id' => $link->getPluginId(),
        'title' => (string) $link->getTitle(),
        'description' => $link->getDescription() ?: NULL,
        'enabled' => $link->isEnabled(),
        'weight' => $link->getWeight(),
        'url' => $url->isRouted() ? $url->toString() : NULL,
        'external_url' => $url->isRouted() ? NULL : $url->toString(),
      ];
      $state->count = ($state->count ?? 0) + 1;
      if ($element->subtree !== []) {
        $entry['children'] = $this->projectTree($element->subtree, $state);
      }
      $out[] = $entry;
    }
    return $out;
  }

  /**
   * Projects one alias whose target the caller may access, else NULL.
   *
   * @return array<string, mixed>|null
   *   The operation result.
   */
  private function projectAlias(PathAliasInterface $alias, TokenAuthUser $caller): ?array {
    $path = '/' . ltrim((string) $alias->getPath(), '/');
    try {
      $url = Url::fromUri('internal:' . $path);
      if (!$url->access($caller)) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return [
      'id' => (int) $alias->id(),
      'alias' => '/' . ltrim((string) $alias->getAlias(), '/'),
      'path' => $path,
      'langcode' => $alias->language()->getId(),
    ];
  }

}
