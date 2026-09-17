<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Test-only entity and field access hooks.
 */
final class DrupalMcpTestHooks {

  /**
   * Denies configured operations for entities listed in test state.
   */
  #[Hook('entity_access')]
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $operations = \Drupal::state()->get('drupal_mcp_test.denied_entity_operations', ['view']);
    if (!in_array($operation, $operations, TRUE)) {
      return AccessResult::neutral();
    }

    $denied = \Drupal::state()->get('drupal_mcp_test.denied_entities', []);
    $entity_key = $entity->getEntityTypeId() . ':' . $entity->id();
    return AccessResult::forbiddenIf(in_array($entity_key, $denied, TRUE))
      ->addCacheableDependency($entity)
      ->cachePerUser();
  }

  /**
   * Denies configured operations for fields listed in test state.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $operations = \Drupal::state()->get('drupal_mcp_test.denied_field_operations', ['view']);
    if (!in_array($operation, $operations, TRUE)) {
      return AccessResult::neutral();
    }

    $denied = \Drupal::state()->get('drupal_mcp_test.denied_fields', []);
    return AccessResult::forbiddenIf(in_array($field_definition->getName(), $denied, TRUE))
      ->cachePerUser();
  }

}
