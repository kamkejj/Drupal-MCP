<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Site\Settings;
use Mcp\Exception\ToolCallException;

/**
 * Encodes and verifies the module's signed pagination cursors.
 *
 * A cursor carries one integer offset bound to one query context. The payload
 * is HMAC-signed with the site hash salt so clients cannot forge offsets, and
 * the offset is capped so even a legitimately paginating client cannot drive
 * arbitrarily deep OFFSET scans. Every paginating tool shares this one
 * implementation; the signature scheme must never be forked per tool.
 */
final class SignedCursor {

  /**
   * Maximum offset a cursor may carry, bounding deep OFFSET scans.
   */
  private const MAX_OFFSET = 100000;

  /**
   * Decodes a server-signed continuation cursor to its offset.
   *
   * @param string|null $cursor
   *   Opaque cursor previously returned by encode(), or NULL for the start.
   * @param string $context
   *   The cursor's bound query context; cursors from other contexts are
   *   rejected.
   *
   * @return int
   *   The signed continuation offset.
   *
   * @throws \Mcp\Exception\ToolCallException
   *   When the cursor is malformed, out of range, or signed for another
   *   context.
   */
  public function decode(?string $cursor, string $context): int {
    if ($cursor === NULL || $cursor === '') {
      return 0;
    }
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), TRUE);
    $data = $decoded === FALSE ? NULL : json_decode($decoded, TRUE);
    if (!\is_array($data)
      || !isset($data['offset'], $data['context'], $data['signature'])
      || !\is_int($data['offset'])
      || $data['offset'] < 0
      || $data['offset'] > self::MAX_OFFSET
      || !hash_equals($this->signature($data['offset'], (string) $data['context']), (string) $data['signature'])
      || !hash_equals(hash('sha256', $context), (string) $data['context'])) {
      throw new ToolCallException('The pagination cursor is invalid for this request.');
    }
    return $data['offset'];
  }

  /**
   * Encodes an offset as a server-signed continuation cursor.
   *
   * @param int $offset
   *   The continuation offset to carry.
   * @param string $context
   *   The query context the cursor is bound to.
   *
   * @return string
   *   The opaque continuation cursor.
   */
  public function encode(int $offset, string $context): string {
    $contextHash = hash('sha256', $context);
    $encoded = json_encode([
      'offset' => $offset,
      'context' => $contextHash,
      'signature' => $this->signature($offset, $contextHash),
    ], JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
  }

  /**
   * HMAC binding one offset to one query context.
   */
  private function signature(int $offset, string $contextHash): string {
    return hash_hmac('sha256', $offset . ':' . $contextHash, Settings::getHashSalt());
  }

}
