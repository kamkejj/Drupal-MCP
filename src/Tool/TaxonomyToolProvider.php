<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\taxonomy\VocabularyInterface;
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
 * Taxonomy vocabulary and term inspection tools.
 *
 * Vocabulary definitions are administrative configuration; term data is
 * bounded by the vocabulary allowlist and per-term view access.
 */
final class TaxonomyToolProvider implements ToolProviderInterface {

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
        name: 'drupal_vocabularies_list',
        title: 'Vocabularies',
        description: 'Lists taxonomy vocabularies with label and description. Term data exposure is limited to the vocabularies enabled for MCP.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE],
        handler: fn (): array => $this->vocabulariesList(),
        family: 'taxonomy',
        extraPermissions: ['administer taxonomy'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_vocabulary_get',
        title: 'Vocabulary detail',
        description: 'Returns one vocabulary: label, description, whether hierarchy is used, and weight.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['vid' => ['type' => 'string']],
          'required' => ['vid'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->vocabularyGet($args['vid']),
        family: 'taxonomy',
        extraPermissions: ['administer taxonomy'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_terms_list',
        title: 'List taxonomy terms',
        description: 'Pages through taxonomy terms of one explicitly enabled vocabulary, filtered by the caller\'s view access.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [
            'vid' => ['type' => 'string', 'description' => 'Vocabulary machine name, e.g. "tags".'],
            'cursor' => ['type' => 'string', 'description' => 'Opaque cursor returned by the previous page.'],
            'limit' => ['type' => 'integer', 'minimum' => 1],
            'name_contains' => ['type' => 'string'],
          ],
          'required' => ['vid'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->termsList(
          $args['vid'],
          $args['cursor'] ?? NULL,
          isset($args['limit']) ? (int) $args['limit'] : NULL,
          $args['name_contains'] ?? NULL,
        ),
        family: 'taxonomy',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_term_get',
        title: 'Read taxonomy term',
        description: 'Returns one taxonomy term with metadata, description (filtered), and parent references viewable by the caller.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['id' => ['type' => 'integer', 'minimum' => 1]],
          'required' => ['id'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->termGet((int) $args['id']),
        family: 'taxonomy',
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
  private function vocabulariesList(): array {
    $out = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $vid => $vocabulary) {
      \assert($vocabulary instanceof VocabularyInterface);
      $out[] = [
        'id' => (string) $vid,
        'label' => (string) $vocabulary->label(),
        'description' => $vocabulary->getDescription() ?: NULL,
        'hierarchy' => $vocabulary->get('hierarchy'),
        'weight' => $vocabulary->get('weight'),
        'exposed_to_mcp' => \in_array((string) $vid, $this->enabledVocabularies(), TRUE),
      ];
    }
    return ['count' => \count($out), 'vocabularies' => $out];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function vocabularyGet(string $vid): array {
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    if ($vocabulary === NULL) {
      throw new ToolCallException(sprintf('Unknown vocabulary "%s".', $vid));
    }
    \assert($vocabulary instanceof VocabularyInterface);
    return [
      'id' => (string) $vocabulary->id(),
      'label' => (string) $vocabulary->label(),
      'description' => $vocabulary->getDescription() ?: NULL,
      'hierarchy' => $vocabulary->get('hierarchy'),
      'weight' => $vocabulary->get('weight'),
      'exposed_to_mcp' => \in_array((string) $vocabulary->id(), $this->enabledVocabularies(), TRUE),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function termsList(string $vid, ?string $cursor, ?int $limit, ?string $nameContains): array {
    if (!\in_array($vid, $this->enabledVocabularies(), TRUE)) {
      throw new ToolCallException(sprintf('Vocabulary "%s" is not exposed through MCP.', $vid));
    }
    $pageLimit = Limits::clamp($this->configFactory, $limit);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $account = $this->currentUser->getAccount();
    $page = $this->pager->page(
      $storage,
      $account,
      $cursor,
      $pageLimit,
      json_encode(['terms', $vid, $nameContains], JSON_THROW_ON_ERROR),
      function (int $offset, int $length) use ($storage, $vid, $nameContains): array {
        $query = $storage->getQuery()
          ->accessCheck(TRUE)
          ->condition('vid', $vid)
          ->sort('tid', 'ASC')
          ->range($offset, $length);
        if ($nameContains !== NULL) {
          $query->condition('name', $nameContains, 'CONTAINS');
        }
        return $query->execute();
      },
      fn ($term): array => ['id' => (int) $term->id(), 'name' => (string) $term->label()],
    );
    return ['vid' => $vid, 'limit' => $pageLimit] + $page;
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function termGet(int $id): array {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
    if ($term === NULL) {
      throw new ToolCallException(sprintf('Term %d does not exist.', $id));
    }
    if (!\in_array($term->bundle(), $this->enabledVocabularies(), TRUE)) {
      throw new ToolCallException('This vocabulary is not exposed through MCP.');
    }
    $account = $this->currentUser->getAccount();
    if (!$term->access('view', $account)) {
      throw new ToolCallException(sprintf('Term %d is not accessible.', $id));
    }
    $projected = EntityProjection::fieldValues($term, $account);
    return [
      'item' => EntityProjection::entityMeta($term, $account),
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
  private function enabledVocabularies(): array {
    return array_values((array) ($this->configFactory->get('drupal_mcp.settings')->get('vocabularies') ?? []));
  }

}
