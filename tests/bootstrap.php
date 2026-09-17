<?php

/**
 * @file
 * Bootstraps module unit and kernel tests.
 */

declare(strict_types=1);

$drupal_root = getenv('DRUPAL_ROOT') ?: dirname(__DIR__, 4);
require $drupal_root . '/core/tests/bootstrap.php';

$project_autoloader = $drupal_root . '/autoload.php';
$autoloader = require $project_autoloader;

// Prepend worktree paths so Composer's installed module copy cannot shadow the
// code under test when dependencies live in a separate Drupal checkout.
$autoloader->addPsr4('Drupal\\drupal_mcp\\', dirname(__DIR__) . '/src', TRUE);
$autoloader->addPsr4('Drupal\\Tests\\drupal_mcp\\', __DIR__ . '/src', TRUE);

return $autoloader;
