<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolRegistry;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\AccountStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ToolProviderStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Tests per-request tool visibility.
 */
final class ToolRegistryTest extends TestCase {

  /**
   * Tests non-token accounts fail closed and definitions default to read.
   */
  public function testNonTokenAccountsFailClosedAndCapabilityDefaultsToRead(): void {
    $settings = new ConfigFactoryStub([
      'drupal_mcp.settings' => [
        'families' => ['diagnostic' => TRUE, 'disabled' => FALSE],
      ],
    ]);
    $provider = new ToolProviderStub([
      new ToolDefinition('visible', 'Visible', 'Visible tool.', [], fn (): array => [], 'diagnostic'),
      new ToolDefinition(
        'missing_native_permission',
        'Missing native permission',
        'Missing native permission.',
        [],
        fn (): array => [],
        'diagnostic',
        ['access comments'],
      ),
      new ToolDefinition('disabled_family', 'Disabled', 'Disabled family.', [], fn (): array => [], 'disabled'),
      new ToolDefinition(
        'missing_extra_permission',
        'Missing extra permission',
        'Missing extra permission.',
        [],
        fn (): array => [],
        'diagnostic',
        ['administer filters'],
      ),
    ]);
    $registry = new ToolRegistry($settings, [$provider], new NullLogger());

    $this->assertSame(OperationCapability::Read, $provider->tools()[0]->capability);
    $this->assertSame([], $registry->toolsForAccount(AccountStub::withPermissions(
      'access mcp read',
      'administer site configuration',
    )));
    $this->assertSame([], $registry->toolsForAccount(AccountStub::withPermissions('authenticated content')));
    $this->assertNull($registry->toolForAccount(
      AccountStub::withPermissions('access mcp read', 'administer site configuration'),
      'missing_extra_permission',
    ));
  }

}
