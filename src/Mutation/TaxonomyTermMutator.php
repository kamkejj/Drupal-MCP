<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mutation;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the complete policy and persistence boundary for taxonomy mutations.
 */
final class TaxonomyTermMutator implements EntityMutatorInterface {

  public const WRITABLE_FIELDS = ['name', 'description', 'parent', 'weight'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(AccountInterface $account, array $command): array {
    $started = microtime(TRUE);
    $vocabulary = (string) ($command['vocabulary'] ?? '');
    try {
      $this->assertEnabledVocabulary($vocabulary);
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $access = $this->entityTypeManager->getAccessControlHandler('taxonomy_term')
        ->createAccess($vocabulary, $account, [], TRUE);
      if (!$access->isAllowed()) {
        throw $this->failure('entity_access_denied', 'Taxonomy term creation is not permitted.');
      }

      $values = ['vid' => $vocabulary];
      $term = $storage->create($values);
      \assert($term instanceof TermInterface);
      $this->applyFields($account, $term, $command, TRUE);
      $this->assertUniqueName($term);
      $this->validate($term);
      $this->prepareRevision($term);
      $term->save();
      $result = $this->project($term);
      $this->audit('create', 'success', $vocabulary, (int) $term->id(), $result['revision_id'], $started, $command, $account);
      return $result;
    }
    catch (TaxonomyMutationException $exception) {
      $this->audit('create', $exception->category, $vocabulary, NULL, NULL, $started, $command, $account);
      throw $exception;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function update(AccountInterface $account, array $command): array {
    $started = microtime(TRUE);
    $id = (int) ($command['id'] ?? 0);
    $lockName = 'drupal_mcp:taxonomy_term:' . $id;
    if ($id < 1 || !$this->lock->acquire($lockName, 30.0)) {
      throw $this->failure('temporary_failure', 'The taxonomy term is temporarily unavailable; retry later.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $storage->resetCache([$id]);
      $term = $storage->load($id);
      if (!$term instanceof TermInterface) {
        throw $this->failure('not_found', 'The taxonomy term was not found or is inaccessible.');
      }
      $vocabulary = $term->bundle();
      // Entity access is checked before the vocabulary allowlist and revision
      // precondition so callers without update access cannot probe which
      // vocabularies are writable or learn the current revision id.
      if (!$term->access('update', $account)) {
        throw $this->failure('entity_access_denied', 'The taxonomy term was not found or is inaccessible.');
      }
      $this->assertEnabledVocabulary($vocabulary);
      $expected = (int) ($command['expected_revision_id'] ?? 0);
      $current = $this->revisionId($term);
      if ($expected < 1 || $expected !== $current) {
        throw $this->failure('revision_conflict', sprintf('Revision conflict; current revision is %d.', $current));
      }
      $changes = $command['changes'] ?? NULL;
      if (!\is_array($changes) || $changes === []) {
        throw $this->failure('invalid_value', 'At least one change is required.');
      }
      $before = $this->mutableValues($term);
      $this->applyFields($account, $term, $changes, FALSE);
      $this->assertUniqueName($term);
      if ($before === $this->mutableValues($term)) {
        throw $this->failure('no_changes', 'The requested update does not change the taxonomy term.');
      }
      $this->validate($term);
      $this->prepareRevision($term);
      $term->save();
      $result = $this->project($term);
      $this->audit('update', 'success', $vocabulary, $id, $result['revision_id'], $started, $command, $account);
      return $result;
    }
    catch (TaxonomyMutationException $exception) {
      // Do not leave unsaved field changes in the static entity cache. A later
      // operation in this request must observe persisted state, not a failed
      // mutation's in-memory entity.
      $storage->resetCache([$id]);
      $this->audit('update', $exception->category, $vocabulary ?? NULL, $id ?: NULL, NULL, $started, $command, $account);
      throw $exception;
    }
    catch (\Throwable $exception) {
      // Saving can fail after presave hooks have changed the entity. Evict it
      // for the same atomicity guarantee, while preserving the original error
      // for the adapter's safe generic response.
      $storage->resetCache([$id]);
      $this->audit('update', 'unexpected_failure', $vocabulary ?? NULL, $id ?: NULL, NULL, $started, $command, $account);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(AccountInterface $account, array $command): array {
    $started = microtime(TRUE);
    $id = (int) ($command['id'] ?? 0);
    $lockName = 'drupal_mcp:taxonomy_term:' . $id;
    if ($id < 1 || !$this->lock->acquire($lockName, 30.0)) {
      throw $this->failure('temporary_failure', 'The taxonomy term is temporarily unavailable; retry later.');
    }

    try {
      $settings = $this->configFactory->get('drupal_mcp.settings');
      if (!(bool) $settings->get('destructive_mutations.taxonomy_terms')) {
        throw $this->failure('destructive_disabled', 'Taxonomy term deletion is disabled.');
      }
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $storage->resetCache([$id]);
      $term = $storage->load($id);
      if (!$term instanceof TermInterface) {
        throw $this->failure('not_found', 'The taxonomy term was not found or is inaccessible.');
      }
      $vocabulary = $term->bundle();
      // As with update: delete access first, so the vocabulary allowlist and
      // revision precondition never answer unauthorized probes.
      if (!$term->access('delete', $account)) {
        throw $this->failure('entity_access_denied', 'The taxonomy term was not found or is inaccessible.');
      }
      $this->assertEnabledVocabulary($vocabulary);
      $current = $this->revisionId($term);
      if ((int) ($command['expected_revision_id'] ?? 0) !== $current) {
        throw $this->failure('revision_conflict', sprintf('Revision conflict; current revision is %d.', $current));
      }
      $confirmation = $command['confirm_term_name'] ?? NULL;
      if (!\is_string($confirmation) || $confirmation !== (string) $term->label()) {
        throw $this->failure('target_mismatch', 'The confirmation name does not match the current taxonomy term.');
      }
      $this->assertNotReferenced($id);
      $term->delete();
      $result = [
        'id' => $id,
        'vocabulary' => $vocabulary,
        'deleted_revision_id' => $current,
        'status' => 'deleted',
      ];
      $this->audit('delete', 'success', $vocabulary, $id, $current, $started, $command, $account);
      return $result;
    }
    catch (TaxonomyMutationException $exception) {
      $this->audit('delete', $exception->category, $vocabulary ?? NULL, $id ?: NULL, NULL, $started, $command, $account);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Fails closed if any entity field stores a reference to the target term.
   */
  private function assertNotReferenced(int $termId): void {
    try {
      $definitions = $this->entityTypeManager->getDefinitions();
      foreach ($definitions as $entityTypeId => $entityType) {
        if (!$entityType instanceof ContentEntityTypeInterface) {
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        foreach ($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId) as $fieldName => $field) {
          if (!$this->targetsTaxonomyTerms($field)) {
            continue;
          }
          $query = $storage->getQuery()->accessCheck(FALSE)->condition($fieldName . '.target_id', $termId)->range(0, 1);
          if ($query->execute() !== []) {
            throw $this->failure('target_referenced', 'The taxonomy term cannot be deleted because it is referenced.');
          }
        }
      }
    }
    catch (TaxonomyMutationException $exception) {
      throw $exception;
    }
    catch (\Throwable) {
      throw $this->failure('reference_safety_unknown', 'The taxonomy term cannot be deleted because reference safety could not be established.');
    }
  }

  /**
   * Determines whether a field can reference taxonomy terms.
   */
  private function targetsTaxonomyTerms(FieldStorageDefinitionInterface $field): bool {
    return $field->getType() === 'entity_reference'
      && $field->getSetting('target_type') === 'taxonomy_term';
  }

  /**
   * Applies the fixed field allowlist and checks edit access.
   */
  private function applyFields(AccountInterface $account, TermInterface $term, array $input, bool $creating): void {
    $mapping = ['parents' => 'parent'];
    $allowedInput = $creating
      ? ['vocabulary', 'name', 'description', 'parents', 'weight', 'idempotency_key', '_session', '_request']
      : ['name', 'description', 'parents', 'weight'];
    $unknown = array_diff(array_keys($input), $allowedInput);
    if ($unknown !== []) {
      throw $this->failure('field_not_writable', 'The request contains a field that is not writable.');
    }
    if ($creating && (!isset($input['name']) || !\is_string($input['name']) || trim($input['name']) === '')) {
      throw $this->failure('invalid_value', 'A non-empty term name is required.');
    }

    foreach (['name', 'description', 'parents', 'weight'] as $inputName) {
      if (!array_key_exists($inputName, $input)) {
        continue;
      }
      $fieldName = $mapping[$inputName] ?? $inputName;
      if (!$term->get($fieldName)->access('edit', $account)) {
        throw $this->failure('field_not_writable', sprintf('Field "%s" is not writable.', $inputName));
      }
      $value = $input[$inputName];
      if ($inputName === 'name') {
        if (!\is_string($value) || trim($value) === '') {
          throw $this->failure('invalid_value', 'The term name must be a non-empty string.');
        }
        $term->set('name', trim($value));
      }
      elseif ($inputName === 'description') {
        $term->set('description', $this->normalizeDescription($account, $value));
      }
      elseif ($inputName === 'parents') {
        $term->set('parent', $this->normalizeParents($account, $term, $value));
      }
      elseif ($inputName === 'weight') {
        if (!\is_int($value) || $value < -1000 || $value > 1000) {
          throw $this->failure('invalid_value', 'Weight must be an integer between -1000 and 1000.');
        }
        $term->set('weight', $value);
      }
    }
  }

  /**
   * Normalizes and authorizes a formatted description.
   */
  private function normalizeDescription(AccountInterface $account, mixed $value): array {
    if (!\is_array($value) || !\is_string($value['value'] ?? NULL) || !\is_string($value['format'] ?? NULL)) {
      throw $this->failure('invalid_text_format', 'Description requires string value and format properties.');
    }
    $format = $this->entityTypeManager->getStorage('filter_format')->load($value['format']);
    if (!$format instanceof FilterFormatInterface || !$format->access('use', $account)) {
      throw $this->failure('invalid_text_format', 'The requested text format is not available.');
    }
    return ['value' => $value['value'], 'format' => $value['format']];
  }

  /**
   * Normalizes and validates parent references.
   */
  private function normalizeParents(AccountInterface $account, TermInterface $term, mixed $value): array {
    if (!\is_array($value) || !array_is_list($value)) {
      throw $this->failure('invalid_reference', 'Parents must be a list of taxonomy term IDs.');
    }
    $ids = [];
    foreach ($value as $id) {
      if (!\is_int($id) || $id < 1 || isset($ids[$id]) || (int) $term->id() === $id) {
        throw $this->failure('invalid_reference', 'A parent reference is invalid or duplicated.');
      }
      $ids[$id] = TRUE;
    }
    $parents = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_keys($ids));
    if (count($parents) !== count($ids)) {
      throw $this->failure('invalid_reference', 'A parent reference is invalid or inaccessible.');
    }
    foreach ($parents as $parent) {
      if (!$parent instanceof TermInterface || $parent->bundle() !== $term->bundle()
        || !$parent->access('view', $account)
        || ($term->id() && $this->isDescendant((int) $term->id(), (int) $parent->id()))) {
        throw $this->failure('invalid_reference', 'A parent reference is invalid or inaccessible.');
      }
    }
    return array_map(static fn (int $id): array => ['target_id' => $id], array_keys($ids));
  }

  /**
   * Checks whether a parent candidate descends from the target.
   */
  private function isDescendant(int $termId, int $candidateId): bool {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $seen = [];
    $pending = [$candidateId];
    while ($pending !== []) {
      $id = array_pop($pending);
      if ($id === $termId) {
        return TRUE;
      }
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $candidate = $storage->load($id);
      if ($candidate instanceof TermInterface) {
        foreach ($candidate->get('parent') as $parent) {
          if ((int) $parent->target_id > 0) {
            $pending[] = (int) $parent->target_id;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Rejects duplicate names within the vocabulary.
   */
  private function assertUniqueName(TermInterface $term): void {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $term->bundle())
      ->condition('name', (string) $term->label());
    if ($term->id()) {
      $query->condition('tid', (int) $term->id(), '<>');
    }
    if ($query->range(0, 1)->execute() !== []) {
      throw $this->failure('duplicate', 'A taxonomy term with this name already exists in the vocabulary.');
    }
  }

  /**
   * Validates the complete entity.
   */
  private function validate(TermInterface $term): void {
    $violations = $term->validate();
    if ($violations->count() > 0) {
      throw $this->failure('validation_failed', 'The taxonomy term failed validation.');
    }
  }

  /**
   * Enforces fail-closed mutation and vocabulary configuration.
   */
  private function assertEnabledVocabulary(string $vocabulary): void {
    $settings = $this->configFactory->get('drupal_mcp.settings');
    if (!(bool) $settings->get('mutation_families.taxonomy')) {
      throw $this->failure('mutation_disabled', 'Taxonomy mutations are disabled.');
    }
    $allowed = array_values((array) ($settings->get('writable_vocabularies') ?? []));
    if (!\in_array($vocabulary, $allowed, TRUE)) {
      throw $this->failure('target_not_writable', 'The vocabulary is not writable through MCP.');
    }
  }

  /**
   * Prepares a new revision when the entity type supports revisions.
   */
  private function prepareRevision(TermInterface $term): void {
    if ($term->getEntityType()->isRevisionable()) {
      $term->setNewRevision(TRUE);
      if (method_exists($term, 'setRevisionLogMessage')) {
        $term->setRevisionLogMessage('Updated by Drupal MCP taxonomy mutation.');
      }
    }
  }

  /**
   * Returns the optimistic-concurrency revision identifier.
   */
  private function revisionId(TermInterface $term): int {
    return $term->getEntityType()->isRevisionable() ? (int) $term->getRevisionId() : (int) $term->id();
  }

  /**
   * Projects only approved mutation result fields.
   */
  private function project(TermInterface $term): array {
    $description = $term->get('description')->first();
    return [
      'id' => (int) $term->id(),
      'vocabulary' => $term->bundle(),
      'revision_id' => $this->revisionId($term),
      'changed' => $term->hasField('changed') ? (int) $term->get('changed')->value : NULL,
      'fields' => [
        'name' => (string) $term->label(),
        'description' => $description ? [
          'value' => (string) $description->value,
          'format' => (string) $description->format,
        ] : NULL,
        'parents' => array_values(array_map(
          static fn (array $item): int => (int) $item['target_id'],
          $term->get('parent')->getValue(),
        )),
        'weight' => (int) $term->get('weight')->value,
      ],
    ];
  }

  /**
   * Returns canonical mutable values for no-op detection.
   */
  private function mutableValues(TermInterface $term): array {
    return [
      'name' => $term->get('name')->getValue(),
      'description' => $term->get('description')->getValue(),
      'parent' => $term->get('parent')->getValue(),
      'weight' => $term->get('weight')->getValue(),
    ];
  }

  /**
   * Creates a categorized safe failure.
   */
  private function failure(string $category, string $message): TaxonomyMutationException {
    return new TaxonomyMutationException($category, $message);
  }

  /**
   * Records content-free mutation audit metadata.
   */
  private function audit(string $operation, string $outcome, ?string $vocabulary, ?int $termId, ?int $revisionId, float $started, array $command, AccountInterface $account): void {
    $consumer = method_exists($account, 'getConsumer') ? $account->getConsumer()->getClientId() : NULL;
    $key = \is_string($command['idempotency_key'] ?? NULL) ? $command['idempotency_key'] : '';
    $this->logger->notice('MCP taxonomy mutation audit.', [
      'uid' => $account->id(),
      'consumer' => $consumer,
      'operation' => $operation,
      'vocabulary' => $vocabulary,
      'term_id' => $termId,
      'revision_id' => $revisionId,
      'idempotency_digest' => $key === '' ? NULL : substr(hash('sha256', $key), 0, 12),
      'outcome' => $outcome,
      'duration_ms' => (int) ((microtime(TRUE) - $started) * 1000),
    ]);
  }

}
