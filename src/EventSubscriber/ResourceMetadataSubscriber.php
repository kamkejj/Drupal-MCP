<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adapts the public protected-resource metadata for the MCP endpoint.
 *
 * The metadata module's defaults describe the site issuer with bearer
 * methods header/body/query. MCP needs this document to identify the
 * canonical /mcp resource, bearer tokens in headers only, and the MCP
 * scopes. Using the module's documented BUILD event avoids competing
 * definitions of the same well-known route.
 */
final class ResourceMetadataSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvents::BUILD => 'onBuild',
    ];
  }

  /**
   * Adjusts the RFC 9728 document for MCP.
   */
  public function onBuild(ResourceMetadataEvent $event): void {
    $metadata = &$event->metadata;

    $metadata['resource'] = (string) $this->configFactory->get('drupal_mcp.settings')->get('resource_uri');
    $metadata['bearer_methods_supported'] = ['header'];

    $settings = $this->configFactory->get('drupal_mcp.settings');
    $readScope = (string) ($settings->get('read_scope') ?: 'mcp:read');
    $writeScope = (string) ($settings->get('write_scope') ?: 'mcp:write');
    $metadata['scopes_supported'] = array_values(array_unique([$readScope, $writeScope]));
  }

}
