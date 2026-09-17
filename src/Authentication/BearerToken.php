<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Authentication;

use Symfony\Component\HttpFoundation\Request;

/**
 * Value object for the raw bearer token of the current request.
 *
 * The token's signature and lifetime are validated by Simple OAuth before
 * this object is ever constructed; the helpers here only expose already-
 * trusted request data and unverified claim inspection for audit purposes.
 */
final class BearerToken {

  private function __construct(
    public readonly string $value,
  ) {}

  /**
   * Extracts the bearer token from a request, or NULL when absent.
   */
  public static function fromRequest(Request $request): ?self {
    $header = trim((string) $request->headers->get('Authorization', ''));
    if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
      return NULL;
    }
    return new self($matches[1]);
  }

  /**
   * Returns the JWT payload claims without verifying the signature.
   *
   * The signature has already been validated by Simple OAuth's resource
   * server; this is claim *inspection*, not authentication. Returns NULL for
   * opaque or malformed tokens.
   *
   * @return array<string, mixed>|null
   *   The operation result.
   */
  public function unsafeClaims(): ?array {
    $parts = explode('.', $this->value);
    if (\count($parts) !== 3) {
      return NULL;
    }
    $payload = strtr($parts[1], ['-' => '+', '_' => '/']);
    $decoded = base64_decode($payload, TRUE);
    if ($decoded === FALSE) {
      return NULL;
    }
    $claims = json_decode($decoded, TRUE);
    return \is_array($claims) ? $claims : NULL;
  }

}
