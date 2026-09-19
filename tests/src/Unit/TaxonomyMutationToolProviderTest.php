<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mutation\EntityMutatorInterface;
use Drupal\drupal_mcp\Mutation\MutationExecutor;
use Drupal\drupal_mcp\Tool\TaxonomyMutationToolProvider;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\StubIdempotencyManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests destructive taxonomy tool catalogue policy.
 */
final class TaxonomyMutationToolProviderTest extends TestCase {

  /**
   * Tests deletion is absent unless its independent switch is enabled.
   */
  public function testDeleteDefinitionRequiresDestructiveEnablement(): void {
    $disabled = $this->provider(FALSE)->tools();
    $this->assertNotContains('drupal_term_delete', array_column($disabled, 'name'));

    $enabled = $this->provider(TRUE)->tools();
    $definitions = [];
    foreach ($enabled as $definition) {
      $definitions[$definition->name] = $definition;
    }
    $this->assertArrayHasKey('drupal_term_delete', $definitions);
    $delete = $definitions['drupal_term_delete'];
    $this->assertTrue($delete->annotations->destructiveHint);
    $this->assertFalse($delete->annotations->idempotentHint);
    $this->assertSame(ToolFamily::TaxonomyMutation, $delete->family);
    $this->assertSame(
      ['id', 'expected_revision_id', 'confirm_term_name'],
      $delete->inputSchema['required'],
    );
    $this->assertArrayNotHasKey('confirm', (array) $delete->inputSchema['properties']);
    $this->assertArrayNotHasKey('idempotency_key', (array) $delete->inputSchema['properties']);
  }

  /**
   * Builds a provider with a no-op mutator behind the executor.
   */
  private function provider(bool $destructive): TaxonomyMutationToolProvider {
    $entries = NULL;
    $now = NULL;
    $mutator = new class implements EntityMutatorInterface {

      /**
       * {@inheritdoc}
       */
      public function create(AccountInterface $account, array $command): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function update(AccountInterface $account, array $command): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function delete(AccountInterface $account, array $command): array {
        return [];
      }

    };
    return new TaxonomyMutationToolProvider(
      $mutator,
      new MutationExecutor(
        StubIdempotencyManager::create($entries, $now),
      ),
      new ConfigFactoryStub([
        'drupal_mcp.settings' => [
          'destructive_mutations' => ['taxonomy_terms' => $destructive],
        ],
      ]),
    );
  }

}
