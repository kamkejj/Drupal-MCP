<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\drupal_mcp\Mcp\OperationCapability;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\drupal_mcp\Mcp\ToolSchema;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Schema\ToolAnnotations;

/**
 * Site and caller identity tools (family: site).
 */
final class SiteToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly McpAccessPolicy $accessPolicy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      new ToolDefinition(
        name: 'drupal_site_info',
        title: 'Drupal site information',
        description: 'Returns allowlisted public identity information about this Drupal site (name, slogan, versions). No filesystem paths, email addresses, or secrets.',
        inputSchema: ToolSchema::object([]),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->siteInfo(),
        family: ToolFamily::Site,
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
      new ToolDefinition(
        name: 'drupal_whoami',
        title: 'Current MCP caller identity',
        description: 'Returns the OAuth-authenticated user identity, granted MCP scopes, client, and effective MCP capabilities of the current caller. Omits email and other personal fields.',
        inputSchema: ToolSchema::object([]),
        handler: fn (array $args, TokenAuthUser $caller): array => $this->whoami($caller),
        family: ToolFamily::Site,
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
        'required_scope' => OperationCapability::Read->resolveScope($this->configFactory),
      ],
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function whoami(TokenAuthUser $caller): array {
    $consumer = $caller->getConsumer();
    return [
      'uid' => (int) $caller->id(),
      'name' => $caller->getDisplayName(),
      'roles' => array_values($caller->getRoles()),
      'granted_scopes' => $this->accessPolicy->grantedScopes($caller),
      'client' => [
        'id' => $consumer->getClientId(),
        'label' => $consumer->label(),
      ],
      'mcp_capabilities' => [
        'read' => TRUE,
        'diagnostics' => $caller->hasPermission('administer site configuration'),
      ],
    ];
  }

}
