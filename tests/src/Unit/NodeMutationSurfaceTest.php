<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the node mutation config surface stays fail-closed and covered.
 */
final class NodeMutationSurfaceTest extends TestCase {

  /**
   * Tests shipped defaults keep node mutations disabled and nothing writable.
   */
  public function testShippedDefaultsAreFailClosed(): void {
    $settings = Yaml::parseFile(__DIR__ . '/../../../config/install/drupal_mcp.settings.yml');
    $this->assertFalse($settings['mutation_families']['node']);
    $this->assertSame([], $settings['writable_node_bundles']);
  }

  /**
   * Tests the typed configuration schema covers the node mutation keys.
   */
  public function testSchemaCoversNodeMutationKeys(): void {
    $schema = Yaml::parseFile(__DIR__ . '/../../../config/schema/drupal_mcp.schema.yml');
    $mapping = $schema['drupal_mcp.settings']['mapping'];

    $this->assertSame('boolean', $mapping['mutation_families']['mapping']['node']['type']);
    $this->assertSame('sequence', $mapping['writable_node_bundles']['type']);
    $this->assertSame('string', $mapping['writable_node_bundles']['sequence']['type']);
  }

}
