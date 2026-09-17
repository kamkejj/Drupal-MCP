<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Diagnostics\ApprovedDrushCommands;
use PHPUnit\Framework\TestCase;

/**
 * Tests the server-defined Drush diagnostic contracts.
 */
final class ApprovedDrushCommandsTest extends TestCase {

  /**
   * Tests that status output keeps only explicitly approved fields.
   */
  public function testStatusProjectionDropsUnapprovedOutput(): void {
    $spec = ApprovedDrushCommands::all()['status'];

    $result = ($spec->project)([
      'drupal-version' => '11.4.6',
      'php-version' => '8.4.20',
      'db-username' => 'should_not_escape',
      'uri' => 'https://should_not_escape',
    ]);

    $this->assertSame([
      'drupal-version' => '11.4.6',
      'php-version' => '8.4.20',
    ], $result);
  }

  /**
   * Tests that pm:list rejects values outside its advertised enum.
   */
  public function testPmListRefusesTypeOutsideAdvertisedEnumBeforeExecution(): void {
    $spec = ApprovedDrushCommands::all()['pm:list'];

    $argv = ($spec->argv)([
      'type' => 'module --root=/unsafe --uri=http://example.test',
    ], []);

    $this->assertNull($argv);
  }

  /**
   * Tests that config:get only accepts site-approved names.
   */
  public function testConfigGetOnlyBuildsArgvForSiteApprovedName(): void {
    $spec = ApprovedDrushCommands::all()['config:get'];
    $settings = ['drush_config_names' => ['system.site', 'system.performance']];

    $this->assertSame(
      ['system.site', '--format=json'],
      ($spec->argv)(['name' => 'system.site'], $settings),
    );
    $this->assertNull(($spec->argv)(['name' => 'other.configuration'], $settings));
    $this->assertNull(($spec->argv)(['name' => 'system.performance'], $settings));
  }

  /**
   * Tests that config:get projects only server-approved properties.
   */
  public function testConfigGetDropsUnapprovedProperties(): void {
    $spec = ApprovedDrushCommands::all()['config:get'];

    $result = ($spec->project)([
      'uuid' => 'should-not-escape',
      'name' => 'Example site',
      'mail' => 'private@example.com',
      'slogan' => 'An example',
      'page' => [
        '403' => '/private-denied',
        '404' => '/private-missing',
        'front' => '/home',
      ],
      'default_langcode' => 'en',
      '_core' => ['default_config_hash' => 'should-not-escape'],
    ], ['name' => 'system.site'], ['drush_config_names' => ['system.site']]);

    $this->assertSame([
      'name' => 'Example site',
      'slogan' => 'An example',
      'page' => ['front' => '/home'],
      'default_langcode' => 'en',
    ], $result);
  }

}
