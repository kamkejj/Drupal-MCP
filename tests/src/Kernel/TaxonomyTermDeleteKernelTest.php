<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\drupal_mcp\Mutation\TaxonomyMutationException;
use Drupal\drupal_mcp\Mutation\TaxonomyTermMutator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests guarded taxonomy term deletion against real entity storage.
 */
#[Group('drupal_mcp')]
final class TaxonomyTermDeleteKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'serialization',
    'path_alias',
    'text',
    'filter',
    'taxonomy',
    'drupal_mcp',
  ];

  /**
   * The mutation boundary under test.
   */
  private TaxonomyTermMutator $mutator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['system', 'user', 'filter', 'taxonomy', 'drupal_mcp']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->container->get('config.factory')->getEditable('drupal_mcp.settings')
      ->set('mutation_families.taxonomy', TRUE)
      ->set('writable_vocabularies', ['tags'])
      ->set('destructive_mutations.taxonomy_terms', TRUE)
      ->save();
    $this->mutator = new TaxonomyTermMutator(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('config.factory'),
      $this->container->get('lock'),
      $this->container->get('logger.channel.drupal_mcp'),
    );
  }

  /**
   * Tests native denial, target checks, references, and safe tombstones.
   */
  public function testDeleteSafeguardsAndTombstone(): void {
    Role::create(['id' => 'term_deleter', 'label' => 'Term deleter', 'permissions' => ['delete terms in tags']])->save();
    $allowed = User::create(['name' => 'allowed', 'status' => 1, 'roles' => ['term_deleter']]);
    $allowed->save();
    $term = Term::create(['vid' => 'tags', 'name' => 'Secret label']);
    $term->save();
    $command = [
      'id' => (int) $term->id(),
      'expected_revision_id' => (int) $term->getRevisionId(),
      'confirm_term_name' => 'Secret label',
    ];

    $this->assertFailure('revision_conflict', fn (): array => $this->mutator->delete($allowed, array_replace($command, ['expected_revision_id' => 999999])));
    $this->assertFailure('target_mismatch', fn (): array => $this->mutator->delete($allowed, array_replace($command, ['confirm_term_name' => 'Wrong'])));

    // A caller without delete access on a non-writable vocabulary gets the
    // access denial, never the allowlist answer.
    Vocabulary::create(['vid' => 'readonly', 'name' => 'Read only'])->save();
    $locked = Term::create(['vid' => 'readonly', 'name' => 'Locked']);
    $locked->save();
    $bystander = User::create(['name' => 'bystander', 'status' => 1]);
    $bystander->save();
    $this->assertFailure('entity_access_denied', fn (): array => $this->mutator->delete($bystander, [
      'id' => (int) $locked->id(),
      'expected_revision_id' => (int) $locked->getRevisionId(),
      'confirm_term_name' => 'Locked',
    ]));

    $child = Term::create(['vid' => 'tags', 'name' => 'Child', 'parent' => [['target_id' => $term->id()]]]);
    $child->save();
    $this->assertFailure('target_referenced', fn (): array => $this->mutator->delete($allowed, $command));
    $child->delete();

    $result = $this->mutator->delete($allowed, $command);
    $this->assertSame([
      'id' => (int) $term->id(),
      'vocabulary' => 'tags',
      'deleted_revision_id' => (int) $term->getRevisionId(),
      'status' => 'deleted',
    ], $result);
    $this->assertArrayNotHasKey('name', $result);
    $this->assertNull(Term::load($term->id()));
  }

  /**
   * Asserts a categorized safe failure.
   */
  private function assertFailure(string $category, callable $operation): void {
    try {
      $operation();
      $this->fail('Expected taxonomy mutation failure.');
    }
    catch (TaxonomyMutationException $exception) {
      $this->assertSame($category, $exception->category);
      $this->assertStringNotContainsString('Secret label', $exception->getMessage());
    }
  }

}
