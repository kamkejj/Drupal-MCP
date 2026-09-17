<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\node\NodeTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupal_mcp\Entity\EntityProjection;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\user\RoleInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Entity type, schema, content-type, field, and role inspection tools.
 *
 * These are structural tools: they describe definitions, not records.
 * Authentication-related entity types are excluded from discovery.
 */
final class EntitySchemaToolProvider implements ToolProviderInterface {

  /**
   * Reviewed entity types whose structural definitions may be discovered.
   */
  private const SUPPORTED_TYPES = [
    'block_content', 'block_content_type',
    'entity_form_display', 'entity_view_display',
    'file', 'menu', 'menu_link_content',
    'node', 'node_type', 'path_alias',
    'taxonomy_term', 'taxonomy_vocabulary',
    'user', 'user_role', 'view',
  ];

  /**
   * Fieldable content entity types whose fields may be inspected.
   */
  private const FIELDABLE_TYPES = [
    'node', 'taxonomy_term', 'block_content', 'user', 'file',
    'menu_link_content', 'path_alias',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $empty = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE];

    return [
      new ToolDefinition(
        name: 'drupal_entity_types_list',
        title: 'Entity types',
        description: 'Lists the entity types on this site (content and configuration) with structural metadata. Authentication and token-related entity types are excluded.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'group' => [
              'type' => 'string',
              'enum' => ['content', 'configuration'],
              'description' => 'Optional filter by entity group.',
            ],
          ],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->entityTypesList($args['group'] ?? NULL),
        family: 'entity_schema',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_schema_get',
        title: 'Entity type schema',
        description: 'Returns the definition and bundles of one supported fieldable entity type. Field-level structure is available through drupal_fields_list for callers with configuration permission. Authentication and token entity types are not supported.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'entity_type' => ['type' => 'string', 'enum' => self::FIELDABLE_TYPES],
            'bundle' => [
              'type' => 'string',
              'description' => 'Optional bundle machine name for bundle-specific fields.',
            ],
          ],
          'required' => ['entity_type'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->schemaGet($args['entity_type'], $args['bundle'] ?? NULL),
        family: 'entity_schema',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_content_types_list',
        title: 'Content types',
        description: 'Lists node types (content types) with label, description, and preview settings. An empty list is valid: this site may have no content types.',
        inputSchema: $empty,
        handler: fn (): array => $this->contentTypesList(),
        family: 'entity_schema',
        extraPermissions: ['administer content types'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_content_type_get',
        title: 'Content type detail',
        description: 'Returns one content type with its field definitions and which view/form displays customize it. Does not expose display markup or raw configuration.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'type' => ['type' => 'string', 'description' => 'Content type machine name, e.g. "article".'],
          ],
          'required' => ['type'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->contentTypeGet($args['type']),
        family: 'entity_schema',
        extraPermissions: ['administer content types'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_fields_list',
        title: 'Field definitions',
        description: 'Lists field definitions (name, type, cardinality, required, target type) for one supported fieldable entity type and optional bundle.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'entity_type' => ['type' => 'string', 'enum' => self::FIELDABLE_TYPES],
            'bundle' => ['type' => 'string'],
          ],
          'required' => ['entity_type'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->fieldsList($args['entity_type'], $args['bundle'] ?? NULL),
        family: 'entity_schema',
        extraPermissions: ['administer content types'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_roles_list',
        title: 'Roles',
        description: 'Lists user roles with label, administrative flag, locked flag, and assigned permissions. Inspection only; roles can never be changed through MCP in this release.',
        inputSchema: $empty,
        handler: fn (): array => $this->rolesList(),
        family: 'entity_schema',
        extraPermissions: ['administer permissions'],
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
  private function entityTypesList(?string $group): array {
    $out = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!\in_array($id, self::SUPPORTED_TYPES, TRUE)) {
        continue;
      }
      if ($group !== NULL && $definition->getGroup() !== $group) {
        continue;
      }
      $out[] = EntityProjection::entityType($definition);
    }
    return ['count' => \count($out), 'entity_types' => $out];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function schemaGet(string $entityTypeId, ?string $bundle): array {
    if (!\in_array($entityTypeId, self::FIELDABLE_TYPES, TRUE)) {
      throw new ToolCallException(sprintf('Entity type "%s" is not inspectable.', $entityTypeId));
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($definition === NULL) {
      throw new ToolCallException(sprintf('Unknown entity type "%s".', $entityTypeId));
    }

    $bundles = [];
    foreach ($this->bundleInfo->getBundleInfo($entityTypeId) as $bundleId => $info) {
      $bundles[] = ['id' => $bundleId, 'label' => (string) $info['label']];
    }
    if ($bundle !== NULL) {
      $bundles = array_values(array_filter($bundles, fn (array $b): bool => $b['id'] === $bundle));
      if ($bundles === []) {
        throw new ToolCallException(sprintf('Unknown bundle "%s" for entity type "%s".', $bundle, $entityTypeId));
      }
    }

    return [
      'entity_type' => EntityProjection::entityType($definition),
      'bundles' => $bundles,
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function contentTypesList(): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $id => $type) {
      \assert($type instanceof NodeTypeInterface);
      $out[] = [
        'id' => (string) $id,
        'label' => (string) $type->label(),
        'description' => $type->getDescription() ?: NULL,
        'display_submitted' => $type->displaySubmitted(),
        'new_revision' => $type->shouldCreateNewRevision(),
        'preview_mode' => $type->getPreviewMode(),
      ];
    }
    return ['count' => \count($out), 'content_types' => $out];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function contentTypeGet(string $type): array {
    $nodeType = $this->entityTypeManager->getStorage('node_type')->load($type);
    if ($nodeType === NULL) {
      throw new ToolCallException(sprintf('Unknown content type "%s".', $type));
    }
    \assert($nodeType instanceof NodeTypeInterface);

    $fields = [];
    foreach ($this->fieldInfo->getFieldDefinitions('node', $type) as $field) {
      $fields[] = EntityProjection::fieldDefinition($field);
    }

    $displays = ['view_modes_with_custom_display' => [], 'form_modes_with_custom_settings' => []];
    foreach ($this->entityTypeManager->getStorage('entity_view_display')->loadMultiple() as $display) {
      /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display */
      if ($display->getTargetEntityTypeId() === 'node' && $display->getTargetBundle() === $type && $display->status()) {
        $displays['view_modes_with_custom_display'][] = $display->getMode();
      }
    }
    foreach ($this->entityTypeManager->getStorage('entity_form_display')->loadMultiple() as $display) {
      /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $display */
      if ($display->getTargetEntityTypeId() === 'node' && $display->getTargetBundle() === $type && $display->status()) {
        $displays['form_modes_with_custom_settings'][] = $display->getMode();
      }
    }

    return [
      'content_type' => [
        'id' => (string) $nodeType->id(),
        'label' => (string) $nodeType->label(),
        'description' => $nodeType->getDescription() ?: NULL,
        'display_submitted' => $nodeType->displaySubmitted(),
        'new_revision' => $nodeType->shouldCreateNewRevision(),
        'preview_mode' => $nodeType->getPreviewMode(),
      ],
      'fields' => $fields,
      'displays' => $displays,
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function fieldsList(string $entityTypeId, ?string $bundle): array {
    if (!\in_array($entityTypeId, self::FIELDABLE_TYPES, TRUE)) {
      throw new ToolCallException(sprintf('Entity type "%s" is not inspectable.', $entityTypeId));
    }
    if ($this->entityTypeManager->getDefinition($entityTypeId, FALSE) === NULL) {
      throw new ToolCallException(sprintf('Unknown entity type "%s".', $entityTypeId));
    }
    if ($bundle !== NULL && !isset($this->bundleInfo->getBundleInfo($entityTypeId)[$bundle])) {
      throw new ToolCallException(sprintf('Unknown bundle "%s" for entity type "%s".', $bundle, $entityTypeId));
    }

    $definitions = $bundle !== NULL
      ? $this->fieldInfo->getFieldDefinitions($entityTypeId, $bundle)
      : $this->fieldInfo->getBaseFieldDefinitions($entityTypeId);

    $out = [];
    foreach ($definitions as $field) {
      $out[] = EntityProjection::fieldDefinition($field);
    }
    return ['entity_type' => $entityTypeId, 'bundle' => $bundle, 'count' => \count($out), 'fields' => $out];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function rolesList(): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $id => $role) {
      \assert($role instanceof RoleInterface);
      $out[] = [
        'id' => (string) $id,
        'label' => (string) $role->label(),
        'is_admin' => $role->isAdmin(),
        'is_locked' => \in_array((string) $id, [RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID], TRUE),
        'permissions' => $role->getPermissions(),
      ];
    }
    return ['count' => \count($out), 'roles' => $out];
  }

}
