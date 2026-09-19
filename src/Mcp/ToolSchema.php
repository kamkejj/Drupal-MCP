<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

/**
 * Builds the module's recurring JSON-schema input shapes.
 *
 * Tool input schemas are strict objects (additionalProperties false). This
 * helper is the one place those wrapper shapes and the argument schemas
 * shared by several tools are declared, so a contract such as the idempotency
 * key charset can never drift between tools.
 */
final class ToolSchema {

  /**
   * Builds a strict object schema over the given properties.
   *
   * @param array<string, mixed> $properties
   *   JSON-schema property definitions keyed by argument name.
   * @param list<string> $required
   *   Required argument names.
   *
   * @return array<string, mixed>
   *   The complete object schema.
   */
  public static function object(array $properties, array $required = []): array {
    $schema = [
      'type' => 'object',
      'properties' => (object) $properties,
    ];
    if ($required !== []) {
      $schema['required'] = $required;
    }
    return $schema + ['additionalProperties' => FALSE];
  }

  /**
   * The idempotency key argument shared by all mutation tools.
   */
  public static function idempotencyKey(): array {
    return ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._~-]+$'];
  }

  /**
   * The formatted-text argument: a value plus an authorized filter format.
   */
  public static function formattedText(): array {
    return self::object([
      'value' => ['type' => 'string'],
      'format' => ['type' => 'string'],
    ], ['value', 'format']);
  }

}
