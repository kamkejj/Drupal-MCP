<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Schema\ToolAnnotations;

/**
 * Site and caller identity tools (family: site).
 */
final class SiteToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly McpAccessPolicy $accessPolicy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    $noArgs = [
      'type' => 'object',
      'properties' => new \stdClass(),
      'additionalProperties' => FALSE,
    ];

    return [
      new ToolDefinition(
        name: 'drupal_site_info',
        title: 'Drupal site information',
        description: 'Returns allowlisted public identity information about this Drupal site (name, slogan, versions). No filesystem paths, email addresses, or secrets.',
        inputSchema: $noArgs,
        handler: fn (): array => $this->siteInfo(),
        family: 'site',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_whoami',
        title: 'Current MCP caller identity',
        description: 'Returns the OAuth-authenticated user identity, granted MCP scopes, client, and effective MCP capabilities of the current caller. Omits email and other personal fields.',
        inputSchema: $noArgs,
        handler: fn (): array => $this->whoami(),
        family: 'site',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function siteInfo(): array {
    $system = $this->configFactory->get('system.site');
    return [
      'site_name' => $system->get('name'),
      'site_slogan' => $system->get('slogan') ?: NULL,
      'drupal_version' => \Drupal::VERSION,
      'default_langcode' => $this->configFactory->get('system.site')->get('langcode') ?: 'en',
      'install_profile' => $this->configFactory->get('core.extension')->get('profile') ?? NULL,
      'mcp' => [
        'read_only' => TRUE,
        'endpoint' => 'mcp',
        'required_scope' => $this->configFactory->get('drupal_mcp.settings')->get('read_scope') ?: 'mcp:read',
      ],
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function whoami(): array {
    $account = $this->currentUser->getAccount();
    if (!$account instanceof TokenAuthUser) {
      // Unreachable behind the endpoint gate; tools fail closed regardless.
      throw new \RuntimeException('The caller could not be identified.');
    }

    $consumer = $account->getConsumer();
    return [
      'uid' => (int) $account->id(),
      'name' => $account->getDisplayName(),
      'roles' => array_values($account->getRoles()),
      'granted_scopes' => $this->accessPolicy->grantedScopes($account),
      'client' => [
        'id' => $consumer->getClientId(),
        'label' => $consumer->label(),
      ],
      'mcp_capabilities' => [
        'read' => TRUE,
        'diagnostics' => $account->hasPermission('administer site configuration'),
      ],
    ];
  }

}
