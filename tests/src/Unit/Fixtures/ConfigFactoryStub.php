<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit\Fixtures;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;

/**
 * Immutable configuration factory for unit tests.
 */
final class ConfigFactoryStub implements ConfigFactoryInterface {

  public function __construct(private readonly array $configuration) {}

  /**
   * Returns a read-only configuration object.
   */
  public function get($name) {
    return new class ($this->configuration[$name] ?? []) {

      public function __construct(private readonly array $configuration) {}

      /**
       * Returns a configuration value.
       */
      public function get(string $key): mixed {
        $value = $this->configuration;
        foreach (explode('.', $key) as $part) {
          if (!\is_array($value) || !array_key_exists($part, $value)) {
            return NULL;
          }
          $value = $value[$part];
        }
        return $value;
      }

    };
  }

  /**
   * Throws because mutable configuration is unnecessary.
   */
  public function getEditable($name) {
    throw new \RuntimeException('Mutable configuration is not needed by this test.');
  }

  /**
   * Returns no preloaded configuration.
   */
  public function loadMultiple(array $names) {
    return [];
  }

  /**
   * Returns the factory.
   */
  public function reset($name = NULL) {
    return $this;
  }

  /**
   * Does not support renaming.
   */
  public function rename($old_name, $new_name) {
    return $this;
  }

  /**
   * Returns no cache keys.
   */
  public function getCacheKeys() {
    return [];
  }

  /**
   * Returns the factory.
   */
  public function clearStaticCache() {
    return $this;
  }

  /**
   * Returns no names.
   */
  public function listAll($prefix = '') {
    return [];
  }

  /**
   * Ignores configuration overrides.
   */
  public function addOverride(ConfigFactoryOverrideInterface $config_factory_override) {}

}
