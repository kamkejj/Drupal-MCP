<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\drupal_mcp\Mcp\Limits;
use Drupal\drupal_mcp\Mcp\SignedCursor;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Executes structured reads against approved curated database views.
 */
final class DatabaseInspector {

  private const MAX_RESULT_BYTES = 262144;

  private const LOCK_NAME = 'drupal_mcp.diagnostics.database';

  private const LOCK_TTL_SECONDS = 15.0;

  public function __construct(
    private readonly ReadOnlyDatabaseConnection $connection,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Limits $limits,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly SignedCursor $cursor,
  ) {}

  /**
   * Executes the operation.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The account invoking the inspection.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function tables(TokenAuthUser $caller): array {
    $started = microtime(TRUE);
    $audit = $this->auditContext($caller, 'drupal_database_tables');
    try {
      $surfaces = [];
      foreach ($this->enabledSurfaces($caller) as $surface) {
        $surfaces[] = [
          'name' => $surface->view,
          'label' => $surface->label,
          'description' => $surface->description,
        ];
      }
      $this->audit($audit, $started, 'success', NULL);
      return ['count' => \count($surfaces), 'surfaces' => $surfaces];
    }
    catch (\Throwable) {
      $this->audit($audit, $started, 'failure', 'inspection_failure');
      throw new ToolCallException('The restricted database surface catalogue could not be read.');
    }
  }

  /**
   * Executes the operation.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The account invoking the inspection.
   * @param string $name
   *   Approved database surface name.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function describe(TokenAuthUser $caller, string $name): array {
    $started = microtime(TRUE);
    $audit = $this->auditContext($caller, 'drupal_database_describe', $name);
    try {
      $surface = $this->surface($caller, $name);
      $result = [
        'name' => $surface->view,
        'label' => $surface->label,
        'description' => $surface->description,
        'columns' => $surface->columns,
      ];
      $this->audit($audit, $started, 'success', NULL);
      return $result;
    }
    catch (ToolCallException $exception) {
      $this->audit($audit, $started, 'failure', 'validation_failure');
      throw $exception;
    }
    catch (\Throwable) {
      $this->audit($audit, $started, 'failure', 'inspection_failure');
      throw new ToolCallException('The restricted database surface could not be described.');
    }
  }

  /**
   * Executes one bounded, structured read.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The account invoking the inspection.
   * @param string $name
   *   Approved database surface name.
   * @param list<string> $columns
   *   Requested projection columns.
   * @param list<array{column: string, operator: string, value: mixed}> $filters
   *   Typed equality and comparison filters.
   * @param array{column: string, direction?: string}|null $sort
   *   Optional approved-column sort.
   * @param int|null $requestedLimit
   *   Requested page size before clamping.
   * @param string|null $cursor
   *   Opaque continuation cursor bound to this query shape.
   *
   * @return array<string, mixed>
   *   Rows, selected columns, count, and continuation cursor.
   */
  public function query(TokenAuthUser $caller, string $name, array $columns, array $filters, ?array $sort, ?int $requestedLimit, ?string $cursor): array {
    $started = microtime(TRUE);
    $audit = $this->auditContext($caller, 'drupal_database_query', $name);
    try {
      $acquired = $this->lock->acquire(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
    }
    catch (\Throwable) {
      $this->audit($audit, $started, 'failure', 'concurrency_control_failure');
      throw new ToolCallException('The restricted database query could not start safely.');
    }
    if (!$acquired) {
      $this->audit($audit, $started, 'failure', 'concurrency_limit');
      throw new ToolCallException('Another restricted database query is already running.');
    }

    $failureCategory = 'validation_failure';
    try {
      $surface = $this->surface($caller, $name);
      $columns = $columns === [] ? array_keys($surface->columns) : array_values(array_unique($columns));
      foreach ($columns as $column) {
        $this->assertColumn($surface, $column);
      }

      $where = [];
      $parameters = [];
      foreach ($filters as $filter) {
        $column = (string) ($filter['column'] ?? '');
        $operator = (string) ($filter['operator'] ?? '');
        $this->assertColumn($surface, $column);
        $value = $this->typedValue($surface, $column, $filter['value'] ?? NULL);
        $quoted = $this->quoteIdentifier($column);
        $sqlOperator = match ($operator) {
          'eq' => '=',
          'neq' => '<>',
          'lt' => '<',
          'lte' => '<=',
          'gt' => '>',
          'gte' => '>=',
          default => throw new ToolCallException(sprintf('Unsupported database filter operator "%s".', $operator)),
        };
        $where[] = sprintf('%s %s ?', $quoted, $sqlOperator);
        $parameters[] = $value;
      }

      $orderColumn = $sort['column'] ?? array_key_first($surface->columns);
      $this->assertColumn($surface, (string) $orderColumn);
      $direction = strtoupper((string) ($sort['direction'] ?? 'ASC'));
      if (!\in_array($direction, ['ASC', 'DESC'], TRUE)) {
        throw new ToolCallException('Database sort direction must be ASC or DESC.');
      }

      $limit = $this->limits->clamp($requestedLimit);
      $context = json_encode([$name, $columns, $filters, $sort], JSON_THROW_ON_ERROR);
      $offset = $this->cursor->decode($cursor, $context);
      $sql = sprintf(
        'SELECT %s FROM %s%s ORDER BY %s %s LIMIT %d OFFSET %d',
        implode(', ', array_map($this->quoteIdentifier(...), $columns)),
        $this->quoteIdentifier($surface->view),
        $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        $this->quoteIdentifier((string) $orderColumn),
        $direction,
        $limit + 1,
        $offset,
      );

      $failureCategory = 'query_failure';
      $pdo = $this->connection->get();
      $pdo->exec('START TRANSACTION READ ONLY');
      $statement = $pdo->prepare($sql);
      $statement->execute($parameters);
      $rows = $statement->fetchAll();
      $pdo->commit();

      $hasMore = \count($rows) > $limit;
      if ($hasMore) {
        array_pop($rows);
      }
      $failureCategory = 'output_limit';
      if (strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > self::MAX_RESULT_BYTES) {
        throw new ToolCallException('The restricted database result exceeded the allowed size.');
      }

      $this->audit($audit, $started, 'success', NULL);
      return [
        'surface' => $name,
        'columns' => $columns,
        'count' => \count($rows),
        'rows' => $rows,
        'next_cursor' => $hasMore ? $this->cursor->encode($offset + $limit, $context) : NULL,
      ];
    }
    catch (ToolCallException $exception) {
      if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->audit($audit, $started, 'failure', $failureCategory);
      throw $exception;
    }
    catch (\Throwable) {
      if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $this->audit($audit, $started, 'failure', $failureCategory);
      throw new ToolCallException('The restricted database query failed.');
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  /**
   * Executes the operation.
   *
   * @return array<string, \Drupal\drupal_mcp\Diagnostics\DatabaseSurface>
   *   The operation result.
   */
  private function enabledSurfaces(TokenAuthUser $caller): array {
    $approved = array_values((array) ($this->configFactory->get('drupal_mcp.settings')->get('database_surfaces') ?? []));
    $configured = array_intersect_key(ApprovedDatabaseSurfaces::all(), array_flip($approved));
    return array_filter(
      $configured,
      fn (DatabaseSurface $surface): bool => $surface->allows($caller),
    );
  }

  /**
   * Executes the operation.
   */
  private function surface(TokenAuthUser $caller, string $name): DatabaseSurface {
    $surfaces = $this->enabledSurfaces($caller);
    if (!isset($surfaces[$name])) {
      throw new ToolCallException(sprintf('Database surface "%s" is not approved.', $name));
    }
    return $surfaces[$name];
  }

  /**
   * Executes the operation.
   */
  private function assertColumn(DatabaseSurface $surface, string $column): void {
    if (!isset($surface->columns[$column])) {
      throw new ToolCallException(sprintf('Column "%s" is not available on database surface "%s".', $column, $surface->view));
    }
  }

  /**
   * Executes the operation.
   */
  private function typedValue(DatabaseSurface $surface, string $column, mixed $value): int|string {
    return match ($surface->columns[$column]) {
      'int' => \is_int($value) ? $value : throw new ToolCallException(sprintf('Column "%s" requires an integer value.', $column)),
      'string' => \is_string($value) ? $value : throw new ToolCallException(sprintf('Column "%s" requires a string value.', $column)),
      default => throw new ToolCallException('Unsupported database column type.'),
    };
  }

  /**
   * Executes the operation.
   */
  private function quoteIdentifier(string $identifier): string {
    return '`' . $identifier . '`';
  }

  /**
   * Executes the operation.
   *
   * @return array<string, int|string|null>
   *   The operation result.
   */
  private function auditContext(TokenAuthUser $caller, string $operationId, ?string $surface = NULL): array {
    return [
      'uid' => (int) $caller->id(),
      'consumer' => $caller->getConsumer()->getClientId(),
      'operation_id' => $operationId,
      'surface' => $surface,
      'request_id' => $this->requestStack->getCurrentRequest()?->headers->get('X-Request-ID') ?: Uuid::v4()->toRfc4122(),
    ];
  }

  /**
   * Records a privileged database inspection outcome.
   *
   * @param array<string, int|string|null> $context
   *   Caller, operation, surface, and request identifiers.
   * @param float $started
   *   Unix microtime when inspection began.
   * @param string $outcome
   *   Outcome classification.
   * @param string|null $failureCategory
   *   Optional failure classification.
   */
  private function audit(array $context, float $started, string $outcome, ?string $failureCategory): void {
    $this->logger->notice('MCP privileged database inspection completed.', $context + [
      'outcome' => $outcome,
      'failure_category' => $failureCategory,
      'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
    ]);
  }

}
