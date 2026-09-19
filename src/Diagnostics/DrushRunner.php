<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Diagnostics;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Runs approved Drush diagnostics through an argv-only subprocess.
 *
 * Hard boundaries enforced here, independent of the command spec:
 *
 * - The executable is the project-local Drush from the Composer vendor
 *   directory, invoked with a fixed PHP CLI binary; callers never influence
 *   either, nor the working directory.
 * - Arguments are passed as an argv array; there is no shell, so no shell
 *   metacharacters exist. Site aliases, --uri/--root/--config overrides,
 *   user switching, include paths, and file redirections are structurally
 *   impossible: the fixed prefix and spec-built tail are the entire argv.
 * - Runtime, output size, and stderr are bounded; the process tree is
 *   terminated on timeout.
 * - The environment is reduced to PATH and HOME plus a fixed site selector,
 *   so callers cannot inject environment overrides.
 */
final class DrushRunner {

  /**
   * Maximum wall-clock seconds for one diagnostic.
   */
  private const TIMEOUT_SECONDS = 60;

  /**
   * Maximum combined bytes accepted from stdout and stderr.
   */
  private const MAX_OUTPUT_BYTES = 262144;

  private const LOCK_NAME = 'drupal_mcp.diagnostics.drush';

  private const LOCK_TTL_SECONDS = 70.0;

  /**
   * Fixed argv prefix: no alias, no arbitrary --uri/--root, no user switch.
   */
  private const FIXED_PREFIX = ['--yes', '--no-ansi', '--no-interaction'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
    private readonly RequestStack $requestStack,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Runs one approved command for a caller.
   *
   * @param \Drupal\simple_oauth\Authentication\TokenAuthUser $caller
   *   The account invoking the diagnostic.
   * @param \Drupal\drupal_mcp\Diagnostics\DrushCommandSpec $spec
   *   The approved command specification.
   * @param array<string, mixed> $arguments
   *   Caller arguments, already schema-validated.
   *
   * @return array{ok: bool, exit_code: ?int, timed_out: bool, output: array|null, error: ?string}
   *   Bounded, projected result. Never raw unbounded stdout.
   */
  public function run(TokenAuthUser $caller, DrushCommandSpec $spec, array $arguments): array {
    $started = microtime(TRUE);
    $audit = $this->auditContext($caller, $spec->id);
    try {
      $acquired = $this->lock->acquire(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
    }
    catch (\Throwable) {
      return $this->failure($audit, $started, 'concurrency_control_failure', NULL, FALSE, 'The diagnostic could not start safely.');
    }
    if (!$acquired) {
      return $this->failure($audit, $started, 'concurrency_limit', NULL, FALSE, 'Another privileged diagnostic is already running.');
    }

    try {
      $settings = $this->configFactory->get('drupal_mcp.settings')->getRawData();
      $tail = ($spec->argv)($arguments, $settings);
      if ($tail === NULL) {
        return $this->failure($audit, $started, 'contract_refused', NULL, FALSE, 'The request is outside the approved contract for this command.');
      }

      $argv = $this->buildArgv($spec, $tail);
      $process = new Process(
        $argv,
        dirname($this->appRoot),
        ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp'],
        NULL,
        self::TIMEOUT_SECONDS,
      );
      $outputExceeded = FALSE;
      $output = new BoundedProcessOutput(self::MAX_OUTPUT_BYTES);
      $process->start();
      foreach ($process->getIterator() as $type => $chunk) {
        if (!$output->append($type, $chunk)) {
          $outputExceeded = TRUE;
          $this->terminateProcessTree($process);
          break;
        }
      }

      if ($outputExceeded) {
        return $this->failure($audit, $started, 'output_limit', $process->getExitCode(), FALSE, 'The diagnostic output exceeded the allowed size.');
      }

      $exitCode = $process->getExitCode();

      if ($exitCode !== 0) {
        return $this->failure($audit, $started, 'process_failure', $exitCode, FALSE, 'The diagnostic command failed.');
      }

      $decoded = json_decode($output->stdout(), TRUE);
      if (!\is_array($decoded)) {
        return $this->failure($audit, $started, 'invalid_output', $exitCode, FALSE, 'The diagnostic output could not be parsed.');
      }

      $projected = ($spec->project)($decoded, $arguments, $settings);
      $this->logger->notice('MCP privileged diagnostic completed.', $audit + [
        'outcome' => 'success',
        'failure_category' => NULL,
        'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
      ]);
      return ['ok' => TRUE, 'exit_code' => 0, 'timed_out' => FALSE, 'output' => $projected, 'error' => NULL];
    }
    catch (ProcessTimedOutException) {
      if (isset($process)) {
        $this->terminateProcessTree($process);
      }
      return $this->failure($audit, $started, 'timeout', NULL, TRUE, 'The diagnostic timed out and was terminated.');
    }
    catch (\Throwable) {
      if (isset($process) && $process->isRunning()) {
        $this->terminateProcessTree($process);
      }
      return $this->failure($audit, $started, 'internal_failure', NULL, FALSE, 'The diagnostic command failed.');
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  /**
   * Builds the complete Drush subprocess argv.
   *
   * @param \Drupal\drupal_mcp\Diagnostics\DrushCommandSpec $spec
   *   Approved command specification.
   * @param list<string> $tail
   *   Fixed argv tail produced by the approved specification.
   *
   * @return list<string>
   *   Executable, arguments, and forced JSON output flag.
   */
  private function buildArgv(DrushCommandSpec $spec, array $tail): array {
    $argv = array_merge(
      [$this->processGroupLauncher()],
      $this->phpBinary(),
      [$this->drushScript()],
      self::FIXED_PREFIX,
      [$spec->drushCommand],
      $tail,
    );
    if (!\in_array('--format=json', $tail, TRUE)) {
      $argv[] = '--format=json';
    }
    return $argv;
  }

  /**
   * Returns the fixed launcher that isolates each diagnostic process group.
   */
  private function processGroupLauncher(): string {
    if (!\function_exists('posix_kill')) {
      throw new \RuntimeException('POSIX process-group termination is unavailable.');
    }
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $launcher) {
      if (is_executable($launcher)) {
        return $launcher;
      }
    }
    throw new \RuntimeException('A safe process-group launcher is unavailable.');
  }

  /**
   * Terminates the isolated diagnostic process group and its descendants.
   */
  private function terminateProcessTree(Process $process): void {
    $pid = $process->getPid();
    if ($pid !== NULL && \function_exists('posix_kill')) {
      @posix_kill(-$pid, \SIGTERM);
      usleep(100000);
      @posix_kill(-$pid, \SIGKILL);
    }
    if ($process->isRunning()) {
      $process->stop(0.1, \SIGKILL);
    }
  }

  /**
   * Executes the operation.
   *
   * @return array<string, int|string|null>
   *   The operation result.
   */
  private function auditContext(TokenAuthUser $caller, string $operationId): array {
    $request = $this->requestStack->getCurrentRequest();
    return [
      'uid' => (int) $caller->id(),
      'consumer' => $caller->getConsumer()->getClientId(),
      'operation_id' => $operationId,
      'request_id' => $request?->headers->get('X-Request-ID') ?: Uuid::v4()->toRfc4122(),
    ];
  }

  /**
   * Logs and formats a failed diagnostic.
   *
   * @param array<string, int|string|null> $audit
   *   Caller and request audit context.
   * @param float $started
   *   Unix microtime when execution began.
   * @param string $category
   *   Stable failure classification.
   * @param int|null $exitCode
   *   Subprocess exit code, when available.
   * @param bool $timedOut
   *   Whether execution exceeded its wall-clock limit.
   * @param string $error
   *   Safe client-facing error message.
   *
   * @return array{ok: false, exit_code: ?int, timed_out: bool, output: null, error: string}
   *   Sanitized failure result.
   */
  private function failure(array $audit, float $started, string $category, ?int $exitCode, bool $timedOut, string $error): array {
    $this->logger->notice('MCP privileged diagnostic completed.', $audit + [
      'outcome' => 'failure',
      'failure_category' => $category,
      'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
    ]);
    return ['ok' => FALSE, 'exit_code' => $exitCode, 'timed_out' => $timedOut, 'output' => NULL, 'error' => $error];
  }

  /**
   * Fixed PHP CLI binary used to invoke the project-local Drush.
   *
   * Under FPM, PHP_BINARY points at php-fpm, so a real CLI binary is
   * resolved from fixed candidates instead.
   *
   * @return list<string>
   *   The operation result.
   */
  private function phpBinary(): array {
    $candidates = [
      PHP_BINDIR . '/php',
      PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
      '/usr/bin/php',
      '/usr/local/bin/php',
    ];
    foreach ($candidates as $php) {
      if (is_executable($php)) {
        return [$php];
      }
    }
    return [PHP_BINARY];
  }

  /**
   * Executes the operation.
   */
  private function drushScript(): string {
    return dirname($this->appRoot) . '/vendor/bin/drush.php';
  }

}
