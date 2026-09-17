<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Symfony\Component\Process\Process;

/**
 * Collects bounded subprocess output while retaining stdout only.
 */
final class BoundedProcessOutput {

  /**
   * Combined bytes observed across stdout and stderr.
   */
  private int $bytes = 0;

  /**
   * Bounded stdout retained for JSON parsing.
   */
  private string $stdout = '';

  public function __construct(
    private readonly int $maxBytes,
  ) {}

  /**
   * Records one stdout or stderr chunk, returning FALSE on overflow.
   */
  public function append(string $type, string $chunk): bool {
    $length = strlen($chunk);
    if ($length > $this->maxBytes - $this->bytes) {
      return FALSE;
    }

    $this->bytes += $length;
    if ($type === Process::OUT) {
      $this->stdout .= $chunk;
    }
    return TRUE;
  }

  /**
   * Returns retained stdout for JSON parsing.
   */
  public function stdout(): string {
    return $this->stdout;
  }

}
