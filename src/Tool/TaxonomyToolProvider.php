<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\taxonomy\VocabularyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\taxonomy\TermInterface;
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityReadTools $reads,
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
        inputSchema: ToolSchema::object([]),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->vocabulariesList(),
        family: ToolFamily::Taxonomy,
        extraPermissions: ['administer taxonomy'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_vocabulary_get',
        title: 'Vocabulary detail',
        description: 'Returns one vocabulary: label, description, whether hierarchy is used, and weight.',
        inputSchema: ToolSchema::object([
          'vid' => ['type' => 'string'],
        ], ['vid']),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->vocabularyGet($args['vid']),
        family: ToolFamily::Taxonomy,
        extraPermissions: ['administer taxonomy'],
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      $this->reads->listTool(
        name: 'drupal_terms_list',
        title: 'List taxonomy terms',
        description: 'Pages through taxonomy terms of one explicitly enabled vocabulary, filtered by the caller\'s view access.',
        family: ToolFamily::Taxonomy,
        entityTypeId: 'taxonomy_term',
        contextSeed: 'terms',
        filters: [
          'vid' => [
            'schema' => ['type' => 'string', 'description' => 'Vocabulary machine name, e.g. "tags".'],
            'field' => 'vid',
            'operator' => EntityReadTools::OP_EQUALS,
          ],
          'name_contains' => [
            'schema' => ['type' => 'string'],
            'field' => 'name',
            'operator' => EntityReadTools::OP_CONTAINS,
          ],
        ],
        project: EntityReadTools::labelProject(),
        requiredFilters: ['vid'],
        filterGuard: function (array $values): void {
          $this->assertExposedVocabulary($values['vid']);
        },
        extraResponse: fn (array $values): array => ['vid' => $values['vid']],
      ),
      $this->reads->getTool(
        name: 'drupal_term_get',
        title: 'Read taxonomy term',
        description: 'Returns one taxonomy term with metadata, description (filtered), and parent references viewable by the caller.',
        family: ToolFamily::Taxonomy,
        entityTypeId: 'taxonomy_term',
        label: 'Term',
        guard: function (TermInterface $term): void {
          if (!\in_array($term->bundle(), $this->enabledVocabularies(), TRUE)) {
            throw new ToolCallException('This vocabulary is not exposed through MCP.');
          }
        },
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
   * Rejects vocabularies outside the read allowlist.
   */
  private function assertExposedVocabulary(?string $vid): void {
    if ($vid === NULL || !\in_array($vid, $this->enabledVocabularies(), TRUE)) {
      throw new ToolCallException(sprintf('Vocabulary "%s" is not exposed through MCP.', (string) $vid));
    }
  }

  /**
   * Returns the vocabulary allowlist.
   *
   * @return list<string>
   *   The operation result.
   */
  private function enabledVocabularies(): array {
    return array_values((array) ($this->configFactory->get('drupal_mcp.settings')->get('vocabularies') ?? []));
  }

}
