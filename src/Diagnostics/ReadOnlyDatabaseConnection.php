<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Drupal\Core\Site\Settings;

/**
 * Opens the deployment-controlled SELECT-only diagnostics connection.
 */
final class ReadOnlyDatabaseConnection {

  private const MARIADB_TIMEOUT_SQL = 'SET SESSION max_statement_time = 5';
  private const MYSQL_TIMEOUT_SQL = 'SET SESSION MAX_EXECUTION_TIME = 5000';

  /**
   * Cached SELECT-only database connection.
   */
  private ?\PDO $connection = NULL;

  /**
   * Executes the operation.
   */
  public function get(): \PDO {
    if ($this->connection instanceof \PDO) {
      return $this->connection;
    }

    $settings = Settings::get('drupal_mcp_database');
    if (!\is_array($settings)) {
      throw new \RuntimeException('The restricted diagnostics database is not configured.');
    }
    foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
      if (!isset($settings[$key]) || $settings[$key] === '') {
        throw new \RuntimeException('The restricted diagnostics database configuration is incomplete.');
      }
    }

    $this->connection = new \PDO(
      sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'], $settings['port'], $settings['database']),
      (string) $settings['username'],
      (string) $settings['password'],
      [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => FALSE,
      ],
    );
    $serverVersion = (string) $this->connection->getAttribute(\PDO::ATTR_SERVER_VERSION);
    if (str_contains($serverVersion, 'MariaDB')) {
      $this->connection->query(self::MARIADB_TIMEOUT_SQL);
    }
    else {
      $this->connection->query(self::MYSQL_TIMEOUT_SQL);
    }
    return $this->connection;
  }

}
