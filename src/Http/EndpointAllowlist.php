<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Http;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The MCP endpoint's Host and Origin allowlists, parsed in one place.
 *
 * Both the request validator and the SDK transport middlewares consume the
 * same parsed lists, so a site's host/origin policy can never disagree
 * between the gate that rejects requests and the middleware that enforces
 * DNS rebinding and CORS protection. Hosts are compared case-insensitively
 * and normalized to lowercase; origins keep their configured form because
 * CORS comparisons are exact-string.
 */
final class EndpointAllowlist {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the normalized Host allowlist.
   *
   * @return list<string>
   *   Non-empty, lowercase host names.
   */
  public function hosts(): array {
    $configured = (array) ($this->configFactory->get('drupal_mcp.settings')->get('allowed_hosts') ?? []);
    return array_values(array_filter(array_map('strtolower', $configured)));
  }

  /**
   * Returns the Origin allowlist in its configured form.
   *
   * @return list<string>
   *   Non-empty origin URLs.
   */
  public function origins(): array {
    $configured = (array) ($this->configFactory->get('drupal_mcp.settings')->get('allowed_origins') ?? []);
    return array_values(array_filter($configured));
  }

}
