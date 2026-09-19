<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Psr\Log\LoggerInterface;

/**
 * Builds a per-request MCP server whose tool set is filtered for the caller.
 *
 * The server is deliberately never cached or shared between requests: the
 * advertised tools depend on the authenticated account, and every request is
 * reauthorized by the controller before dispatch. This is also where the
 * explicit caller is bound: every handler registered with the SDK receives
 * the account that owns the request, so no tool reads ambient account state.
 */
final class ServerFactory {

  public function __construct(
    private readonly ToolRegistry $toolRegistry,
    private readonly SessionStore $sessionStore,
    private readonly LoggerInterface $logger,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly Limits $limits,
  ) {}

  /**
   * Builds the server for a request by a specific caller.
   */
  public function forAccount(TokenAuthUser $caller): Server {
    $version = $this->moduleExtensionList->getExtensionInfo('drupal_mcp')['version'] ?? '0.1.0';

    $builder = Server::builder()
      ->setServerInfo(
        'drupal-mcp',
        $version,
        description: 'Bounded inspection and explicitly enabled mutations for this Drupal site.',
        title: 'Drupal MCP',
      )
      ->setInstructions('Inspect permitted site data and use only explicitly enabled mutation tools. Available tools are filtered for the authenticated account.')
      ->setSession($this->sessionStore->forAccount($caller))
      ->setPaginationLimit($this->limits->maximum())
      ->setLogger($this->logger);

    foreach ($this->toolRegistry->toolsForAccount($caller) as $definition) {
      $builder->addTool(
        $this->bindCaller($definition->handler, $caller),
        $definition->name,
        $definition->title,
        $definition->description,
        $definition->annotations,
        $definition->inputSchema,
      );
    }

    return $builder->build();
  }

  /**
   * Adapts a (arguments, caller) handler to the SDK's raw-bag invocation.
   *
   * The adapter is bound to the SDK ReferenceHandler scope so the SDK hands
   * it the raw argument bag directly instead of mapping closure parameters
   * by name against tool arguments.
   */
  private function bindCaller(\Closure $handler, TokenAuthUser $caller): \Closure {
    $bound = function (array $arguments) use ($handler, $caller): mixed {
      return $handler($arguments, $caller);
    };
    return \Closure::bind($bound, NULL, ReferenceHandler::class);
  }

}
