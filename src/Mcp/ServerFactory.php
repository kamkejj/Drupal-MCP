<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountInterface;
use Mcp\Server;
use Psr\Log\LoggerInterface;

/**
 * Builds a per-request MCP server whose tool set is filtered for the caller.
 *
 * The server is deliberately never cached or shared between requests: the
 * advertised tools depend on the authenticated account, and every request is
 * reauthorized by the controller before dispatch.
 */
final class ServerFactory {

  public function __construct(
    private readonly ToolRegistry $toolRegistry,
    private readonly SessionStore $sessionStore,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Builds the server for a request by a specific account.
   */
  public function forAccount(AccountInterface $account): Server {
    $settings = $this->configFactory->get('drupal_mcp.settings');
    $version = $this->moduleExtensionList->getExtensionInfo('drupal_mcp')['version'] ?? '0.1.0';

    $builder = Server::builder()
      ->setServerInfo(
        'drupal-mcp',
        $version,
        description: 'Bounded inspection and explicitly enabled mutations for this Drupal site.',
        title: 'Drupal MCP',
      )
      ->setInstructions('Inspect permitted site data and use only explicitly enabled mutation tools. Available tools are filtered for the authenticated account.')
      ->setSession($this->sessionStore)
      ->setPaginationLimit(max(1, (int) $settings->get('pagination_limit')))
      ->setLogger($this->logger);

    foreach ($this->toolRegistry->toolsForAccount($account) as $definition) {
      $builder->addTool(
        $definition->handler,
        $definition->name,
        $definition->title,
        $definition->description,
        $definition->annotations,
        $definition->inputSchema,
      );
    }

    return $builder->build();
  }

}
