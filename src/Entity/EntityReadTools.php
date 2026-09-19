<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupal_mcp\Mcp\Limits;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Builds the recurring entity read tools: one paged list, one entity get.
 *
 * This is the read-side counterpart of the mutation executor: providers
 * declare the tool identity, their filter arguments (schema plus an explicit
 * condition operator), and their projections, while this module owns
 * everything recurring — the strict schema wrapper (cursor + limit +
 * filters), argument unpacking, page-size clamping, the cursor-context
 * binding (seed plus every declared filter value, so a cursor from one
 * filter combination never serves another), the not-found and view-access
 * denials, and the response envelopes. Lists always page in entity-id order
 * taken from the entity type definition, and every condition is applied
 * through the entity query's parameterized condition API with a fixed
 * operator allowlist.
 */
final class EntityReadTools {

  /**
   * Allowed condition operators over filter values.
   */
  public const OP_EQUALS = '=';
  public const OP_CONTAINS = 'CONTAINS';
  public const OP_STARTS_WITH = 'STARTS_WITH';

  /**
   * Operators a declared filter may use.
   */
  private const ALLOWED_OPERATORS = [self::OP_EQUALS, self::OP_CONTAINS, self::OP_STARTS_WITH];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccessibleEntityPager $pager,
    private readonly Limits $limits,
  ) {}

  /**
   * Defines one access-filtered, cursor-paged list tool.
   *
   * @param string $name
   *   Tool name exposed to MCP clients.
   * @param string $title
   *   Human-readable tool title.
   * @param string $description
   *   Tool description exposed to MCP clients.
   * @param \Drupal\drupal_mcp\Mcp\ToolFamily $family
   *   The tool family gating exposure.
   * @param string $entityTypeId
   *   Entity type id being listed.
   * @param string $contextSeed
   *   Stable seed bound into generated cursors.
   * @param array<string, array> $filters
   *   Filter declarations keyed by argument name, each with a "schema"
   *   fragment, the entity "field" to filter, and one allowed "operator".
   *   Declaration order is the cursor-context order after the seed.
   * @param callable $project
   *   Projection callback producing one response row or NULL to omit the
   *   entity.
   * @param list<string> $requiredFilters
   *   Filter argument names the caller must supply.
   * @param array<string> $extraPermissions
   *   Additional Drupal permissions required beyond the family gates.
   * @param callable|null $filterGuard
   *   Rejects filter combinations before querying, e.g. allowlists.
   * @param callable|null $extraResponse
   *   Extra keys merged into the response before the limit echo.
   * @param list<array{0: string, 1: string|int, 2: string}> $fixedConditions
   *   Module-defined conditions as [field, value, operator] triples that
   *   never involve caller input, e.g. excluding the anonymous account.
   * @param bool $accessCheck
   *   Whether candidate queries run with entity access checking; the
   *   per-entity view check always applies regardless.
   */
  public function listTool(
    string $name,
    string $title,
    string $description,
    ToolFamily $family,
    string $entityTypeId,
    string $contextSeed,
    array $filters,
    callable $project,
    array $requiredFilters = [],
    array $extraPermissions = [],
    ?callable $filterGuard = NULL,
    ?callable $extraResponse = NULL,
    array $fixedConditions = [],
    bool $accessCheck = TRUE,
  ): ToolDefinition {
    foreach ($filters as $filter) {
      if (!isset($filter['field'], $filter['operator'])
        || !\in_array($filter['operator'], self::ALLOWED_OPERATORS, TRUE)) {
        throw new \InvalidArgumentException('Each list filter needs a field and an allowed operator.');
      }
    }

    $schema = ToolSchema::object(
      $this->filterSchemas($filters) + [
        'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
        'limit' => ['type' => 'integer', 'minimum' => 1],
      ],
      $requiredFilters,
    );

    return new ToolDefinition(
      name: $name,
      title: $title,
      description: $description,
      inputSchema: $schema,
      handler: fn (array $args, TokenAuthUser $caller): array => $this->list(
        $caller,
        $entityTypeId,
        $contextSeed,
        $filters,
        $project,
        $filterGuard,
        $extraResponse,
        $fixedConditions,
        $accessCheck,
        $args,
      ),
      family: $family,
      extraPermissions: $extraPermissions,
      annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
    );
  }

  /**
   * Defines one single-entity read tool with view-access enforcement.
   *
   * @param string $name
   *   Tool name exposed to MCP clients.
   * @param string $title
   *   Human-readable tool title.
   * @param string $description
   *   Tool description exposed to MCP clients.
   * @param \Drupal\drupal_mcp\Mcp\ToolFamily $family
   *   The tool family gating exposure.
   * @param string $entityTypeId
   *   Entity type id being read.
   * @param string $label
   *   Human label used in the not-found and not-accessible messages.
   * @param array<string> $extraPermissions
   *   Additional Drupal permissions required beyond the family gates.
   * @param callable|null $guard
   *   Rejects the loaded entity after the view-access check, e.g. bundle
   *   allowlists, so callers without view access never learn allowlist
   *   details or existence beyond the standard not-accessible denial.
   * @param callable|null $project
   *   Produces the response; defaults to the entity-projection envelope
   *   (item metadata, field values, withheld fields).
   */
  public function getTool(
    string $name,
    string $title,
    string $description,
    ToolFamily $family,
    string $entityTypeId,
    string $label,
    array $extraPermissions = [],
    ?callable $guard = NULL,
    ?callable $project = NULL,
  ): ToolDefinition {
    return new ToolDefinition(
      name: $name,
      title: $title,
      description: $description,
      inputSchema: ToolSchema::object([
        'id' => ['type' => 'integer', 'minimum' => 1],
      ], ['id']),
      handler: fn (array $args, TokenAuthUser $caller): array => $this->entity(
        $caller,
        $entityTypeId,
        $label,
        (int) ($args['id'] ?? 0),
        $guard,
        $project,
      ),
      family: $family,
      extraPermissions: $extraPermissions,
      annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
    );
  }

  /**
   * A list projection carrying entity metadata without its uuid.
   *
   * @return callable(\Drupal\Core\Entity\EntityInterface, \Drupal\simple_oauth\Authentication\TokenAuthUser): array
   *   The projection callback.
   */
  public static function metaProject(): callable {
    return static function (EntityInterface $entity, TokenAuthUser $caller): array {
      $meta = EntityProjection::entityMeta($entity, $caller);
      unset($meta['uuid']);
      return $meta;
    };
  }

  /**
   * A list projection carrying the entity id and one label field.
   *
   * @return callable(\Drupal\Core\Entity\EntityInterface): array
   *   The projection callback.
   */
  public static function labelProject(string $labelKey = 'name'): callable {
    return static function (EntityInterface $entity) use ($labelKey): array {
      $row = ['id' => (int) $entity->id()];
      $row[$labelKey] = (string) $entity->label();
      return $row;
    };
  }

  /**
   * The default get projection: metadata plus field values and withholding.
   *
   * @return callable(\Drupal\Core\Entity\EntityInterface, \Drupal\simple_oauth\Authentication\TokenAuthUser): array
   *   The projection callback.
   */
  public static function fieldProject(): callable {
    return static function (EntityInterface $entity, TokenAuthUser $caller): array {
      $projected = EntityProjection::fieldValues($entity, $caller);
      return [
        'item' => EntityProjection::entityMeta($entity, $caller),
        'fields' => $projected['values'],
        'fields_withheld' => $projected['fields_withheld'],
      ];
    };
  }

  /**
   * Executes one paged list behind the shared read policy.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The authenticated account invoking the tool.
   * @param string $entityTypeId
   *   Entity type id being listed.
   * @param string $contextSeed
   *   Stable seed bound into generated cursors.
   * @param array<string, array> $filters
   *   Declared filters; also drives cursor-context ordering.
   * @param callable $project
   *   Projection callback producing one response row or NULL to omit the
   *   entity.
   * @param callable|null $filterGuard
   *   Rejects filter combinations before querying.
   * @param callable|null $extraResponse
   *   Extra keys merged into the response before the limit echo.
   * @param list<array{0: string, 1: string|int, 2: string}> $fixedConditions
   *   Module-defined [field, value, operator] triples without caller input.
   * @param bool $accessCheck
   *   Whether candidate queries run with entity access checking.
   * @param array<string, mixed> $args
   *   The raw tool arguments.
   *
   * @return array<string, mixed>
   *   Extra response keys, the limit echo, and one bounded page.
   */
  private function list(
    TokenAuthUser $caller,
    string $entityTypeId,
    string $contextSeed,
    array $filters,
    callable $project,
    ?callable $filterGuard,
    ?callable $extraResponse,
    array $fixedConditions,
    bool $accessCheck,
    array $args,
  ): array {
    $values = [];
    foreach (array_keys($filters) as $filterName) {
      $values[$filterName] = $args[$filterName] ?? NULL;
    }
    if ($filterGuard !== NULL) {
      $filterGuard($values);
    }

    $pageLimit = $this->limits->clamp(isset($args['limit']) ? (int) $args['limit'] : NULL);
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $context = json_encode([$contextSeed, ...array_values($values)], JSON_THROW_ON_ERROR);
    $page = $this->pager->page(
      $storage,
      $caller,
      $args['cursor'] ?? NULL,
      $pageLimit,
      $context,
      fn (int $offset, int $length): array => $this->candidateIds($storage, $filters, $values, $fixedConditions, $accessCheck, $offset, $length),
      // The pager projects entities; the caller joins for two-argument
      // projections and is simply ignored by single-argument ones.
      fn ($entity): mixed => $project($entity, $caller),
    );
    $extras = $extraResponse !== NULL ? $extraResponse($values) : [];
    return $extras + ['limit' => $pageLimit] + $page;
  }

  /**
   * Builds one id-ordered candidate batch through the condition API.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Entity storage building the candidate query.
   * @param array<string, array> $filters
   *   Declared filters with their fields and operators.
   * @param array<string, mixed> $values
   *   Schema-validated filter values keyed by argument name; NULL omits
   *   the condition.
   * @param list<array{0: string, 1: string|int, 2: string}> $fixedConditions
   *   Module-defined [field, value, operator] triples without caller input.
   * @param bool $accessCheck
   *   Whether the candidate query runs with entity access checking.
   * @param int $offset
   *   Batch offset derived from the signed cursor.
   * @param int $length
   *   Batch length derived from the clamped page size.
   *
   * @return list<int|string>
   *   Candidate entity ids for the pager to load and filter.
   */
  private function candidateIds(EntityStorageInterface $storage, array $filters, array $values, array $fixedConditions, bool $accessCheck, int $offset, int $length): array {
    // Batch bounds are always non-negative and non-empty regardless of what
    // the decoded cursor carried.
    $offset = max(0, $offset);
    $length = max(1, $length);
    $idKey = $storage->getEntityType()->getKey('id');
    $entityQuery = $storage->getQuery()
      ->accessCheck($accessCheck)
      ->sort($idKey, 'ASC')
      ->range($offset, $length);
    foreach ($fixedConditions as [$field, $value, $operator]) {
      $entityQuery->condition($field, $value, $operator);
    }
    foreach ($filters as $filterName => $filter) {
      $value = $values[$filterName] ?? NULL;
      if ($value !== NULL) {
        $entityQuery->condition($filter['field'], $value, $filter['operator']);
      }
    }
    return array_values($entityQuery->execute());
  }

  /**
   * Loads, guards, and projects one entity behind the shared read policy.
   */
  private function entity(
    TokenAuthUser $caller,
    string $entityTypeId,
    string $label,
    int $id,
    ?callable $guard,
    ?callable $project,
  ): array {
    $loaded = $this->entityTypeManager->getStorage($entityTypeId)->load($id);
    if ($loaded === NULL) {
      throw new ToolCallException(sprintf('%s %d does not exist.', $label, $id));
    }
    // View access is checked before the bundle guard so a caller without
    // access cannot probe allowlist state one id at a time.
    if (!$loaded->access('view', $caller)) {
      throw new ToolCallException(sprintf('%s %d is not accessible.', $label, $id));
    }
    if ($guard !== NULL) {
      $guard($loaded);
    }
    $project ??= self::fieldProject();
    return $project($loaded, $caller);
  }

  /**
   * Returns the schema fragments of the declared filters in order.
   *
   * @return array<string, array>
   *   Schema fragments keyed by argument name.
   */
  private function filterSchemas(array $filters): array {
    $schemas = [];
    foreach ($filters as $filterName => $filter) {
      $schemas[$filterName] = $filter['schema'] ?? ['type' => 'string'];
    }
    return $schemas;
  }

}
