<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Tests\drupal_mcp\Traits\TokenCallerTrait;
use Drupal\drupal_mcp\Mutation\TaxonomyMutationException;
use Drupal\drupal_mcp\Mutation\TaxonomyTermMutator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests taxonomy mutations against Drupal's real entity and access systems.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class TaxonomyMutationKernelTest extends KernelTestBase {

  use TokenCallerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'options',
    'taxonomy',
    'serialization',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'drupal_mcp',
    'drupal_mcp_test',
  ];

  /**
   * The mutation service under test. */
  private TaxonomyTermMutator $mutator;

  /**
   * The authorized taxonomy writer. */
  private UserInterface $writer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['system', 'user', 'filter', 'taxonomy', 'drupal_mcp']);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'readonly', 'name' => 'Read only'])->save();
    FilterFormat::create([
      'format' => 'mcp_text',
      'name' => 'MCP text',
      'weight' => 0,
      'filters' => [],
    ])->save();
    Role::create([
      'id' => 'mcp_writer',
      'label' => 'MCP writer',
      'permissions' => [
        'access mcp write',
        'administer taxonomy',
        'use text format mcp_text',
      ],
    ])->save();
    $this->createUser('root-placeholder', []);
    $this->writer = $this->createUser('writer', ['mcp_writer']);
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('mutation_families.taxonomy', TRUE)
      ->set('writable_vocabularies', ['tags', 'article'])
      ->save();
    $this->mutator = $this->container->get('drupal_mcp.taxonomy_term_mutator');
  }

  /**
   * Tests create projection, formatted text, parents, and configured gates.
   */
  public function testCreateAndConfigurationGates(): void {
    $parent = $this->term('Parent');
    $created = $this->mutator->create($this->caller(), [
      'vocabulary' => 'tags',
      'name' => ' Child ',
      'description' => ['value' => '<em>safe</em>', 'format' => 'mcp_text'],
      'parents' => [(int) $parent->id()],
      'weight' => 4,
    ]);
    $this->assertSame('Child', $created['fields']['name']);
    $this->assertSame(['value' => '<em>safe</em>', 'format' => 'mcp_text'], $created['fields']['description']);
    $this->assertSame([(int) $parent->id()], $created['fields']['parents']);
    $this->assertSame(4, $created['fields']['weight']);

    $article = $this->mutator->create($this->caller(), [
      'vocabulary' => 'article',
      'name' => 'PHP',
    ]);
    $this->assertSame('article', $article['vocabulary']);
    $this->assertSame('PHP', $article['fields']['name']);

    $this->config('drupal_mcp.settings')->set('mutation_families.taxonomy', FALSE)->save();
    $this->assertCreateFailure('mutation_disabled', ['vocabulary' => 'tags', 'name' => 'Disabled']);
    $this->config('drupal_mcp.settings')->set('mutation_families.taxonomy', TRUE)->save();
    $this->assertCreateFailure('target_not_writable', ['vocabulary' => 'readonly', 'name' => 'No']);
    $this->assertCreateFailure('target_not_writable', ['vocabulary' => 'unknown', 'name' => 'No']);
  }

  /**
   * Tests native create/update access and edit field access.
   */
  public function testNativeAndFieldAccessDenials(): void {
    $unprivileged = $this->createUser('unprivileged', []);
    $this->assertCreateFailure('entity_access_denied', ['vocabulary' => 'tags', 'name' => 'Denied'], $unprivileged);

    $term = $this->term('Existing');
    $this->assertFailure('entity_access_denied', fn () => $this->mutator->update($this->caller($unprivileged), [
      'id' => (int) $term->id(),
      'expected_revision_id' => (int) $term->id(),
      'changes' => ['name' => 'Denied update'],
    ]));

    // A caller without update access on a non-writable vocabulary gets the
    // access denial, never the allowlist or revision answer.
    $unwritable = $this->term('Unwritable', 'readonly');
    $this->assertFailure('entity_access_denied', fn () => $this->mutator->update($this->caller($unprivileged), [
      'id' => (int) $unwritable->id(),
      'expected_revision_id' => (int) $unwritable->id(),
      'changes' => ['name' => 'Probe'],
    ]));

    $this->container->get('state')->set('drupal_mcp_test.denied_fields', ['name']);
    $this->container->get('state')->set('drupal_mcp_test.denied_field_operations', ['edit']);
    $this->resetAccessCaches();
    $this->assertFailure('field_not_writable', fn () => $this->mutator->update($this->caller(), [
      'id' => (int) $term->id(),
      'expected_revision_id' => (int) $term->id(),
      'changes' => ['name' => 'Blocked field'],
    ]));
  }

  /**
   * Tests malformed formats, values, unknown fields, and duplicate names.
   */
  public function testInputValidationAndDuplicateName(): void {
    $this->term('Duplicate');
    $this->assertCreateFailure('duplicate', ['vocabulary' => 'tags', 'name' => 'Duplicate']);
    $this->assertCreateFailure('invalid_value', ['vocabulary' => 'tags', 'name' => ' ']);
    $this->assertCreateFailure('invalid_value', ['vocabulary' => 'tags', 'name' => 'Heavy', 'weight' => 1001]);
    $this->assertCreateFailure('field_not_writable', ['vocabulary' => 'tags', 'name' => 'Extra', 'status' => 1]);
    $this->assertFailure('invalid_text_format', fn () => $this->mutator->create($this->caller(), [
      'vocabulary' => 'tags',
      'name' => 'Bad format',
      'description' => ['value' => 'x', 'format' => 'missing'],
    ]));
    $this->assertFailure('invalid_text_format', fn () => $this->mutator->create($this->caller(), [
      'vocabulary' => 'tags',
      'name' => 'Malformed format',
      'description' => ['value' => 'x'],
    ]));
  }

  /**
   * Tests parent validity, access, duplication, self-reference, and cycles.
   */
  public function testParentReferenceRules(): void {
    $parent = $this->term('Parent');
    $other = $this->term('Other', 'readonly');
    $this->assertCreateFailure('invalid_reference', [
      'vocabulary' => 'tags',
      'name' => 'Missing',
      'parents' => [999999],
    ]);
    $this->assertCreateFailure('invalid_reference', [
      'vocabulary' => 'tags',
      'name' => 'Cross',
      'parents' => [(int) $other->id()],
    ]);
    $this->assertCreateFailure('invalid_reference', [
      'vocabulary' => 'tags',
      'name' => 'Duplicate parent',
      'parents' => [(int) $parent->id(), (int) $parent->id()],
    ]);

    $child = $this->term('Child', 'tags', [(int) $parent->id()]);
    $this->assertFailure('invalid_reference', fn () => $this->mutator->update($this->caller(), [
      'id' => (int) $parent->id(),
      'expected_revision_id' => (int) $parent->id(),
      'changes' => ['parents' => [(int) $child->id()]],
    ]));
    $this->assertFailure('invalid_reference', fn () => $this->mutator->update($this->caller(), [
      'id' => (int) $parent->id(),
      'expected_revision_id' => (int) $parent->id(),
      'changes' => ['parents' => [(int) $parent->id()]],
    ]));

    $this->container->get('state')->set('drupal_mcp_test.denied_entities', ['taxonomy_term:' . $child->id()]);
    $this->container->get('state')->set('drupal_mcp_test.denied_entity_operations', ['view']);
    $this->resetAccessCaches();
    $this->assertFailure('invalid_reference', fn () => $this->mutator->create($this->caller(), [
      'vocabulary' => 'tags',
      'name' => 'Hidden parent',
      'parents' => [(int) $child->id()],
    ]));
  }

  /**
   * Tests update preconditions, no-op detection, and failed-update atomicity.
   */
  public function testUpdateRevisionAndAtomicity(): void {
    $term = $this->term('Original');
    $id = (int) $term->id();
    $this->assertUpdateFailure('not_found', [
      'id' => 999999,
      'expected_revision_id' => 999999,
      'changes' => ['name' => 'x'],
    ]);
    $this->assertUpdateFailure('revision_conflict', [
      'id' => $id,
      'expected_revision_id' => $id + 1,
      'changes' => ['name' => 'x'],
    ]);
    $this->assertUpdateFailure('revision_conflict', ['id' => $id, 'changes' => ['name' => 'x']]);
    $this->assertUpdateFailure('no_changes', [
      'id' => $id,
      'expected_revision_id' => $id,
      'changes' => ['name' => 'Original'],
    ]);

    $this->assertFailure('invalid_reference', fn () => $this->mutator->update($this->caller(), [
      'id' => $id,
      'expected_revision_id' => $id,
      'changes' => ['name' => 'Must not leak', 'parents' => [999999]],
    ]));
    $reloaded = Term::load($id);
    $this->assertInstanceOf(TermInterface::class, $reloaded);
    $this->assertSame('Original', $reloaded->label());

    $updated = $this->mutator->update($this->caller(), [
      'id' => $id,
      'expected_revision_id' => $id,
      'changes' => ['name' => 'Updated'],
    ]);
    $this->assertSame('Updated', $updated['fields']['name']);
  }

  /**
   * Creates and saves a taxonomy term fixture. */
  private function term(string $name, string $vocabulary = 'tags', array $parents = []): TermInterface {
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
      'parent' => array_map(static fn (int $id): array => ['target_id' => $id], $parents),
    ]);
    $term->save();
    return $term;
  }

  /**
   * Returns the explicit mutation caller, defaulting to the writer. */
  private function caller(?UserInterface $as = NULL) {
    return $this->tokenCaller($as ?? $this->writer);
  }

  /**
   * Creates and saves a user fixture. */
  private function createUser(string $name, array $roles): UserInterface {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Asserts a categorized mutation failure. */
  private function assertFailure(string $category, callable $operation): void {
    try {
      $operation();
      $this->fail(sprintf('Expected taxonomy mutation failure "%s".', $category));
    }
    catch (TaxonomyMutationException $exception) {
      $this->assertSame($category, $exception->category);
    }
  }

  /**
   * Asserts a categorized create failure. */
  private function assertCreateFailure(string $category, array $command, ?UserInterface $as = NULL): void {
    $this->assertFailure($category, fn () => $this->mutator->create($this->caller($as), $command));
  }

  /**
   * Asserts a categorized update failure. */
  private function assertUpdateFailure(string $category, array $command, ?UserInterface $as = NULL): void {
    $this->assertFailure($category, fn () => $this->mutator->update($this->caller($as), $command));
  }

  /**
   * Resets taxonomy access and entity caches. */
  private function resetAccessCaches(): void {
    $this->container->get('entity_type.manager')->getAccessControlHandler('taxonomy_term')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->resetCache();
  }

}
