<?php

/**
 * @file
 * Provisions local OAuth fixtures for MCP testing.
 */

declare(strict_types=1);

use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Provisions local read/write OAuth fixtures for manual and conformance tests.
 *
 * Run with: drush php:script scripts/mcp-oauth-setup.php.
 */
$definitions = [
  'read' => [
    'role' => 'mcp_reader',
    'username' => 'mcp_reader_user',
    'scope_id' => 'mcp_read',
    'scope' => 'mcp:read',
    'permissions' => ['access mcp read', 'grant simple_oauth codes'],
  ],
  'write' => [
    'role' => 'mcp_writer',
    'username' => 'mcp_writer_user',
    'scope_id' => 'mcp_write',
    'scope' => 'mcp:write',
    'permissions' => [
      'access mcp write',
      'administer taxonomy',
      'grant simple_oauth codes',
    ],
  ],
];
$credentials = [];
foreach ($definitions as $name => $definition) {
  $role = Role::load($definition['role']) ?? Role::create([
    'id' => $definition['role'],
    'label' => sprintf('MCP %s fixture', $name),
  ]);
  foreach ($definition['permissions'] as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();

  $scope = Oauth2Scope::load($definition['scope_id']) ?? Oauth2Scope::create([
    'id' => $definition['scope_id'],
    'name' => $definition['scope'],
  ]);
  $scope
    ->set('description', sprintf('Local MCP %s fixture scope', $name))
    ->set('granularity_id', Oauth2ScopeInterface::GRANULARITY_ROLE)
    ->set('granularity_configuration', ['role' => $definition['role']])
    ->set('grant_types', [
      'authorization_code' => ['status' => TRUE, 'description' => ''],
      'refresh_token' => ['status' => TRUE, 'description' => ''],
    ])
    ->save();

  $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $definition['username']]);
  $user = reset($users) ?: User::create(['name' => $definition['username'], 'status' => 1]);
  $password = bin2hex(random_bytes(18));
  $user->setPassword($password);
  $user->addRole($definition['role']);
  $user->activate()->save();
  $credentials[] = $definition['username'] . ': ' . $password;
}

$consumers = \Drupal::entityTypeManager()->getStorage('consumer')->loadByProperties(['client_id' => 'mcp-inspector']);
$consumer = reset($consumers) ?: Consumer::create([
  'label' => 'MCP local PKCE fixture',
  'client_id' => 'mcp-inspector',
  'user_id' => 1,
]);
$consumer
  ->set('confidential', FALSE)
  ->set('pkce', TRUE)
  ->set('third_party', TRUE)
  ->set('automatic_authorization', FALSE)
  ->set('redirect', ['http://127.0.0.1:6274/oauth/callback'])
  ->set('grant_types', ['authorization_code', 'refresh_token'])
  ->set('scopes', [['scope_id' => 'mcp_read'], ['scope_id' => 'mcp_write']])
  ->save();

$projectRoot = dirname((string) \Drupal::root());
$directory = $projectRoot . '/secrets';
if (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
  throw new RuntimeException('Unable to create the secrets directory.');
}
$file = $directory . '/mcp-test-credentials.txt';
file_put_contents($file, implode(PHP_EOL, $credentials) . PHP_EOL, LOCK_EX);
chmod($file, 0600);
printf("Provisioned read/write OAuth fixtures. Credentials: %s\n", $file);
