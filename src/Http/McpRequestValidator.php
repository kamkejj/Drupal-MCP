<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates MCP request Host and Origin policy consistently.
 */
final class McpRequestValidator {

  public function __construct(
    private readonly EndpointAllowlist $allowlist,
  ) {}

  /**
   * Returns a denial message, or NULL when the request is allowed.
   */
  public function validate(Request $request): ?string {
    $allowedHosts = $this->allowlist->hosts();
    if (!\in_array(strtolower($request->getHost()), $allowedHosts, TRUE)) {
      return 'Forbidden: Invalid Host header.';
    }

    $origin = $request->headers->get('Origin');
    if ($origin === NULL) {
      return NULL;
    }

    $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
    if (!\in_array($origin, $this->allowlist->origins(), TRUE) || !\in_array($originHost, $allowedHosts, TRUE)) {
      return 'Forbidden: Invalid Origin header.';
    }

    return NULL;
  }

}
