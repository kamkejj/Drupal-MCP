<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Shared, allowlisted projections for entities and their definitions.
 *
 * Every read tool projects data through this class so that access filtering
 * and field policies live in exactly one place:
 *
 * - Entity values are never exposed through toArray(); fields are projected
 *   individually, only after per-field "view" access passes.
 * - Text fields are rendered through Drupal's filtered-text pipeline with
 *   their own format, preserving core filtering semantics.
 * - Entity references expose ids plus labels only when the referenced entity
 *   is itself viewable by the caller.
 * - Field-definition summaries describe structure, never storage settings.
 */
final class EntityProjection {

  /**
   * Field types whose raw scalar values are safe to project.
   */
  private const SCALAR_TYPES = [
    'boolean', 'integer', 'decimal', 'float', 'timestamp', 'string',
    'email', 'language', 'uuid', 'created', 'changed', 'datetime',
    'duration', 'timespan', 'list_string', 'list_integer', 'list_float',
    'telephone', 'color', 'version',
  ];

  /**
   * Text field types rendered through the filtered-text pipeline.
   */
  private const FILTERED_TEXT_TYPES = [
    'text', 'text_long', 'text_with_summary',
  ];

  /**
   * A structural summary of an entity type definition.
   *
   * @return array<string, mixed>
   *   Entity type identity, ownership, and modeling metadata.
   */
  public static function entityType(EntityTypeInterface $type): array {
    return [
      'id' => $type->id(),
      'label' => (string) $type->getLabel(),
      'group' => $type->getGroup(),
      'provider' => $type->getProvider(),
      'entity_class' => $type->getClass() === EntityTypeInterface::class ? NULL : basename(str_replace('\\', '/', $type->getClass())),
      'revisionable' => $type->isRevisionable(),
      'translatable' => $type->isTranslatable(),
      'bundle_entity_type' => $type->getBundleEntityType(),
      'field_ui_base_route' => $type->get('field_ui_base_route') ? TRUE : NULL,
    ];
  }

  /**
   * A structural summary of a field definition.
   *
   * @return array<string, mixed>
   *   Field identity, type, cardinality, access, and safe settings.
   */
  public static function fieldDefinition(FieldDefinitionInterface $definition): array {
    $settings = [];
    $storage = $definition->getFieldStorageDefinition();
    // Only structural settings; storage settings can embed internal paths.
    if ($storage->getType() === 'entity_reference' && $storage->getSetting('target_type')) {
      $settings['target_type'] = $storage->getSetting('target_type');
    }
    return [
      'name' => $definition->getName(),
      'type' => $definition->getType(),
      'label' => (string) $definition->getLabel(),
      'required' => $definition->isRequired(),
      'cardinality' => $storage->getCardinality() === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED ? -1 : $storage->getCardinality(),
      'translatable' => $definition->isTranslatable(),
      'description' => $definition->getDescription() ?: NULL,
      'settings' => $settings ?: NULL,
      'computed' => $definition->isComputed(),
    ];
  }

  /**
   * Projects entity identity and access-checked metadata fields.
   *
   * @return array<string, mixed>
   *   Entity identity and safe status/timestamp metadata.
   */
  public static function entityMeta(EntityInterface $entity, AccountInterface $account): array {
    $data = [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => (string) $entity->id(),
      'bundle' => $entity->bundle(),
      'langcode' => $entity->language()?->getId(),
      'uuid' => $entity->uuid(),
    ];

    if (!$entity instanceof FieldableEntityInterface) {
      return $data;
    }

    $labelField = $entity->getEntityType()->getKey('label');
    if ($labelField && self::canViewField($entity, $labelField, $account)) {
      $data['label'] = $entity->label() !== NULL ? (string) $entity->label() : NULL;
    }
    foreach (['status', 'created', 'changed'] as $fieldName) {
      if (self::canViewField($entity, $fieldName, $account)) {
        $data[$fieldName] = $entity->get($fieldName)->value;
      }
    }
    return $data;
  }

  /**
   * Checks whether one field exists and is viewable by the caller.
   */
  private static function canViewField(FieldableEntityInterface $entity, string $fieldName, AccountInterface $account): bool {
    return $entity->hasField($fieldName) && $entity->get($fieldName)->access('view', $account);
  }

  /**
   * Projects entity field values after per-field view access checks.
   *
   * Only per-field access checks and the field-type projections below decide
   * what is exposed; there is deliberately no unrestricted toArray().
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to project.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The caller on whose behalf values are exposed.
   *
   * @return array{values: array<string, mixed>, fields_withheld: list<string>}
   *   Projected values keyed by field name, plus fields the caller may not
   *   view (names only, when the field is not structurally secret).
   */
  public static function fieldValues(FieldableEntityInterface $entity, AccountInterface $account): array {
    $values = [];
    $withheld = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      $item = $entity->get($name);
      if ($item->access('view', $account) === FALSE) {
        // Record the withholding only for ordinary data fields; hidden
        // internal fields (passwords, tokens) must not be named at all.
        if (\in_array($name, ['pass', 'init', 'timezone', 'session', 'token'], TRUE) || str_contains($name, 'token') || str_contains($name, 'secret')) {
          continue;
        }
        $withheld[] = $name;
        continue;
      }

      $type = $definition->getType();
      if (\in_array($type, self::FILTERED_TEXT_TYPES, TRUE)) {
        $values[$name] = self::filteredText($entity, $name);
      }
      elseif (\in_array($type, self::SCALAR_TYPES, TRUE)) {
        $values[$name] = self::scalarList($entity, $name);
      }
      elseif ($type === 'entity_reference' || $type === 'file' || $type === 'image') {
        $values[$name] = self::referenceList($entity, $name, $account, $definition->getFieldStorageDefinition()->getSetting('target_type') ?? '');
      }
      // Other field types (map, layout_section, ...) are omitted from values;
      // their structure remains visible through the field-definition tools.
    }
    return ['values' => $values, 'fields_withheld' => $withheld];
  }

  /**
   * Renders text items through Drupal's filtered-text pipeline.
   *
   * @return list<array<string, mixed>>
   *   Rendered text and optional summary values.
   */
  private static function filteredText(FieldableEntityInterface $entity, string $name): array {
    $out = [];
    foreach ($entity->get($name) as $item) {
      $entry = [
        'value' => check_markup($item->value ?? '', $item->format ?? 'plain_text', $entity->language()->getId()),
      ];
      if (isset($item->summary)) {
        $entry['summary'] = check_markup($item->summary ?? '', $item->format ?? 'plain_text', $entity->language()->getId());
      }
      $out[] = $entry;
    }
    return $out;
  }

  /**
   * Projects scalar field values as a list.
   *
   * @return list<mixed>
   *   Scalar item values, with missing values represented as NULL.
   */
  private static function scalarList(FieldableEntityInterface $entity, string $name): array {
    $out = [];
    foreach ($entity->get($name) as $item) {
      $out[] = $item->value ?? NULL;
    }
    return $out;
  }

  /**
   * Projects only entity references viewable by the caller.
   *
   * @return list<array<string, mixed>>
   *   Accessible referenced-entity identities and labels.
   */
  private static function referenceList(FieldableEntityInterface $entity, string $name, AccountInterface $account, string $targetType): array {
    $out = [];
    /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $item */
    foreach ($entity->get($name) as $item) {
      $referenced = $item->entity;
      if (!$referenced instanceof EntityInterface || !$referenced->access('view', $account)) {
        continue;
      }
      $entry = [
        'target_type' => $targetType ?: $referenced->getEntityTypeId(),
        'target_id' => isset($item->target_id) ? (string) $item->target_id : NULL,
        'label' => (string) $referenced->label(),
      ];
      $out[] = $entry;
    }
    return $out;
  }

}
