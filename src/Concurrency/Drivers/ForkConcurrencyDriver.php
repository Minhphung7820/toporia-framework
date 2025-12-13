<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Drivers;

use InvalidArgumentException;
use RuntimeException;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Exceptions\ConcurrencyException;
use Throwable;

/**
 * Fork Concurrency Driver
 *
 * High-performance concurrency driver using pcntl_fork().
 * Executes closures in child processes with shared-nothing architecture.
 *
 * How it works:
 * 1. Create socket pairs for IPC (Inter-Process Communication)
 * 2. Fork child processes using pcntl_fork()
 * 3. Each child executes its closure directly (no serialization needed)
 * 4. Children write serialized results to sockets
 * 5. Parent collects results via non-blocking I/O
 *
 * Advantages:
 * - Fastest option (no PHP bootstrap overhead)
 * - Closures execute directly (no serialization of closure itself)
 * - Memory efficient (copy-on-write semantics)
 *
 * Limitations:
 * - CLI only (cannot fork in web context)
 * - Unix/Linux/macOS only (requires PCNTL extension)
 * - Results must be serializable
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ForkConcurrencyDriver implements ConcurrencyDriverInterface
{
    /**
     * Running tasks information.
     *
     * @var array<int, array{pid: int, socket: resource, key: int|string, startTime: float}>
     */
    private array $runningTasks = [];

    /**
     * Collected results.
     *
     * @var array<int|string, mixed>
     */
    private array $results = [];

    /**
     * Whether signal handlers are registered.
     */
    private bool $signalHandlersRegistered = false;

    /**
     * Deferred tasks to execute on shutdown.
     *
     * @var array<callable>
     */
    private static array $deferredTasks = [];

    /**
     * Whether shutdown handler is registered.
     */
    private static bool $shutdownRegistered = false;

    /**
     * @param int $maxConcurrent Maximum concurrent processes
     * @param float $timeout Timeout in seconds (0 = no timeout)
     */
    public function __construct(
        private readonly int $maxConcurrent = 4,
        private readonly float $timeout = 60.0
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function run(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }

        if (!$this->isSupported()) {
            throw ConcurrencyException::unsupportedEnvironment(
                'fork',
                'PCNTL extension is required'
            );
        }

        // Guard against HTTP context
        $this->guardAgainstHttpContext();

        // Register signal handlers
        $this->registerSignalHandlers();

        // Normalize tasks
        $normalizedTasks = $this->normalizeTasks($tasks);
        $keys = array_keys($normalizedTasks);

        // Reset state
        $this->runningTasks = [];
        $this->results = [];

        // Build task queue
        $taskQueue = [];
        foreach ($normalizedTasks as $key => $callable) {
            $taskQueue[] = ['key' => $key, 'callable' => $callable];
        }

        $startTime = microtime(true);
        $effectiveTimeout = $this->timeout > 0 ? $this->timeout : PHP_INT_MAX;

        // Process all tasks
        while (!empty($taskQueue) || !empty($this->runningTasks)) {
            // Check global timeout
            if ($this->timeout > 0 && (microtime(true) - $startTime) > $effectiveTimeout) {
                $this->killAllRunning();
                break;
            }

            // Dispatch signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Start new tasks up to concurrency limit
            while (count($this->runningTasks) < $this->maxConcurrent && !empty($taskQueue)) {
                $task = array_shift($taskQueue);
                $this->forkTask($task['key'], $task['callable']);
            }

            // Collect finished tasks
            $this->collectFinishedTasks();

            // Small delay to prevent CPU spinning
            if (!empty($this->runningTasks)) {
                usleep(5000); // 5ms - balance between responsiveness and CPU usage
            }
        }

        // Ensure results are in original order
        $orderedResults = [];
        foreach ($keys as $key) {
            $orderedResults[$key] = $this->results[$key] ?? null;
        }

        return $orderedResults;
    }

    /**
     * {@inheritdoc}
     */
    public function defer(array $tasks): void
    {
        if (empty($tasks)) {
            return;
        }

        $normalizedTasks = $this->normalizeTasks($tasks);

        foreach ($normalizedTasks as $task) {
            self::$deferredTasks[] = $task;
        }

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'executeDeferredTasks']);
        }
    }

    /**
     * Execute deferred tasks on shutdown.
     *
     * @internal
     */
    public static function executeDeferredTasks(): void
    {
        if (empty(self::$deferredTasks)) {
            return;
        }

        // Flush output first
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        // Execute deferred tasks sequentially for simplicity in shutdown
        foreach (self::$deferredTasks as $task) {
            try {
                $task();
            } catch (Throwable $e) {
                error_log('Deferred task failed: ' . $e->getMessage());
            }
        }

        self::$deferredTasks = [];
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'fork';
    }

    /**
     * Fork a single task.
     *
     * @param int|string $key Task key
     * @param callable $callable Task to execute
     */
    private function forkTask(int|string $key, callable $callable): void
    {
        // Create socket pair for IPC
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw ConcurrencyException::forkFailed('Failed to create socket pair');
        }

        [$parentSocket, $childSocket] = $sockets;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            throw ConcurrencyException::forkFailed('pcntl_fork() failed');
        }

        if ($pid === 0) {
            // CHILD PROCESS
            fclose($parentSocket);
            $this->executeInChild($callable, $childSocket);
            // Never returns
        }

        // PARENT PROCESS
        fclose($childSocket);

        $this->runningTasks[$pid] = [
            'pid' => $pid,
            'socket' => $parentSocket,
            'key' => $key,
            'startTime' => microtime(true),
        ];
    }

    /**
     * Execute task in child process.
     *
     * @param callable $callable Task to execute
     * @param resource $socket Socket for result transmission
     */
    private function executeInChild(callable $callable, $socket): never
    {
        try {
            $result = $callable();

            $payload = serialize([
                'success' => true,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            $payload = serialize([
                'success' => false,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Write result to socket
        fwrite($socket, $payload);
        fflush($socket);
        fclose($socket);

        // Exit child process
        exit(0);
    }

    /**
     * Collect results from finished tasks.
     */
    private function collectFinishedTasks(): void
    {
        foreach ($this->runningTasks as $pid => $taskInfo) {
            // Non-blocking wait
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                // Process finished
                $this->collectTaskResult($pid, $taskInfo);
                unset($this->runningTasks[$pid]);
            } elseif ($result === -1) {
                // Error or already reaped
                unset($this->runningTasks[$pid]);
            }
            // result === 0 means still running
        }
    }

    /**
     * Collect result from a finished task.
     *
     * @param int $pid Process ID
     * @param array{pid: int, socket: resource, key: int|string, startTime: float} $taskInfo
     */
    private function collectTaskResult(int $pid, array $taskInfo): void
    {
        $socket = $taskInfo['socket'];
        $key = $taskInfo['key'];

        // Read result from socket
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 0, 100000); // 100ms

        $data = stream_get_contents($socket);
        fclose($socket);

        if ($data === '' || $data === false) {
            $this->results[$key] = null;
            return;
        }

        try {
            $result = unserialize($data);

            if (is_array($result) && isset($result['success'])) {
                if ($result['success']) {
                    $this->results[$key] = $result['result'];
                } else {
                    // Task threw an exception
                    $this->results[$key] = [
                        'error' => $result['error'] ?? 'Unknown error',
                        'exception' => $result['class'] ?? 'Exception',
                    ];
                }
            } else {
                $this->results[$key] = $result;
            }
        } catch (Throwable $e) {
            $this->results[$key] = [
                'error' => 'Failed to deserialize: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Kill all running child processes.
     */
    private function killAllRunning(): void
    {
        foreach ($this->runningTasks as $pid => $taskInfo) {
            posix_kill($pid, SIGTERM);
        }

        // Grace period
        usleep(100000); // 100ms

        // Force kill remaining
        foreach ($this->runningTasks as $pid => $taskInfo) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status, WNOHANG);

            if (isset($taskInfo['socket']) && is_resource($taskInfo['socket'])) {
                fclose($taskInfo['socket']);
            }
        }

        $this->runningTasks = [];
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if ($this->signalHandlersRegistered) {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->killAllRunning();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGQUIT, $handler);

        pcntl_async_signals(true);
        $this->signalHandlersRegistered = true;
    }

    /**
     * Guard against HTTP context.
     *
     * @throws ConcurrencyException
     */
    private function guardAgainstHttpContext(): void
    {
        if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['HTTP_HOST'])) {
            throw ConcurrencyException::unsupportedEnvironment(
                'fork',
                'Fork driver cannot be used in HTTP context. Use Queue jobs instead.'
            );
        }

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            throw ConcurrencyException::unsupportedEnvironment(
                'fork',
                'Fork driver requires CLI SAPI. Current: ' . PHP_SAPI
            );
        }
    }

    /**
     * Normalize and validate tasks.
     *
     * @param array<int|string, callable> $tasks
     * @return array<int|string, callable>
     * @throws InvalidArgumentException
     */
    private function normalizeTasks(array $tasks): array
    {
        $normalized = [];

        foreach ($tasks as $key => $task) {
            if (!is_callable($task)) {
                throw new InvalidArgumentException(
                    sprintf('Task "%s" must be callable, %s given', $key, gettype($task))
                );
            }
            $normalized[$key] = $task;
        }

        return $normalized;
    }
}
