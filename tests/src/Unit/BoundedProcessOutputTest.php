<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Diagnostics\BoundedProcessOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests combined stdout and stderr accounting.
 */
final class BoundedProcessOutputTest extends TestCase {

  /**
   * Tests both streams share one output allowance.
   */
  public function testStdoutAndStderrShareLimit(): void {
    $output = new BoundedProcessOutput(10);

    $this->assertTrue($output->append(Process::OUT, '123456'));
    $this->assertTrue($output->append(Process::ERR, '7890'));
    $this->assertFalse($output->append(Process::ERR, 'x'));
    $this->assertSame('123456', $output->stdout());
  }

  /**
   * Tests stderr consumes the allowance without being returned.
   */
  public function testStderrIsBoundedButNotRetained(): void {
    $output = new BoundedProcessOutput(4);

    $this->assertTrue($output->append(Process::ERR, '1234'));
    $this->assertFalse($output->append(Process::OUT, 'x'));
    $this->assertSame('', $output->stdout());
  }

}
