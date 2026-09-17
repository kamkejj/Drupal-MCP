<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

/**
 * Server-defined safe projections for Drush configuration diagnostics.
 */
final class ApprovedConfigProjections {

  /**
   * Curated configuration names and the only properties they may expose.
   */
  private const PROJECTIONS = [
    'system.site' => [
      'label' => 'Site identity and front page',
      'properties' => ['name', 'slogan', 'page.front', 'default_langcode'],
    ],
  ];

  /**
   * Returns labels keyed by configuration name for administrative forms.
   *
   * @return array<string, string>
   *   Curated configuration names and labels.
   */
  public static function options(): array {
    return array_map(
      static fn (array $projection): string => $projection['label'],
      self::PROJECTIONS,
    );
  }

  /**
   * Checks whether a configuration object has a server-defined projection.
   *
   * @param string $name
   *   Configuration object name.
   */
  public static function supports(string $name): bool {
    return isset(self::PROJECTIONS[$name]);
  }

  /**
   * Projects one configuration object through its approved property paths.
   *
   * @param string $name
   *   Configuration object name.
   * @param array<string, mixed> $output
   *   Parsed Drush configuration output.
   *
   * @return array<string, mixed>
   *   Safe projected configuration values, or an empty array when unsupported.
   */
  public static function project(string $name, array $output): array {
    $projection = self::PROJECTIONS[$name] ?? NULL;
    if ($projection === NULL) {
      return [];
    }

    $result = [];
    foreach ($projection['properties'] as $path) {
      $segments = explode('.', $path);
      $value = $output;
      foreach ($segments as $segment) {
        if (!\is_array($value) || !\array_key_exists($segment, $value)) {
          continue 2;
        }
        $value = $value[$segment];
      }

      $target = &$result;
      foreach ($segments as $segment) {
        if (!isset($target[$segment]) || !\is_array($target[$segment])) {
          $target[$segment] = [];
        }
        $target = &$target[$segment];
      }
      $target = $value;
      unset($target);
    }
    return $result;
  }

}
