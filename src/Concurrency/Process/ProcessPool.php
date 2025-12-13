<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Process;

use RuntimeException;

/**
 * Process Pool
 *
 * Manages concurrent execution of multiple processes using proc_open.
 * Supports configurable timeouts, working directories, and environment variables.
 *
 * Features:
 * - True parallel process execution
 * - Non-blocking I/O for efficient resource usage
 * - Configurable timeout per process
 * - Named processes for result identification
 * - Real-time output streaming (optional)
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ProcessPool
{
    /**
     * Pending tasks to execute.
     *
     * @var array<int, array{
     *     name: string|int,
     *     command: array<int, string>,
     *     env: array<string, string>,
     *     cwd: string|null,
     *     timeout: float
     * }>
     */
    private array $tasks = [];

    /**
     * Default working directory.
     */
    private ?string $workingDirectory = null;

    /**
     * Default timeout in seconds.
     */
    private float $timeout = 60.0;

    /**
     * Maximum concurrent processes.
     */
    private int $maxConcurrent = 10;

    /**
     * Output callback for real-time streaming.
     *
     * @var callable(string $type, string $output, string|int $name): void|null
     */
    private $outputCallback = null;

    /**
     * Set the default working directory for all processes.
     */
    public function path(string $path): self
    {
        $this->workingDirectory = $path;
        return $this;
    }

    /**
     * Set the default timeout for all processes.
     *
     * @param float $seconds Timeout in seconds
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = max(0.0, $seconds);
        return $this;
    }

    /**
     * Set maximum concurrent processes.
     */
    public function concurrent(int $max): self
    {
        $this->maxConcurrent = max(1, $max);
        return $this;
    }

    /**
     * Add a command to the pool.
     *
     * @param array<int, string> $command Command and arguments
     * @param array<string, string> $env Environment variables
     */
    public function command(array $command, array $env = []): self
    {
        $this->tasks[] = [
            'name' => count($this->tasks),
            'command' => $command,
            'env' => $env,
            'cwd' => $this->workingDirectory,
            'timeout' => $this->timeout,
        ];

        return $this;
    }

    /**
     * Add a named command to the pool.
     *
     * @param string|int $name Process name for identification
     * @param array<int, string> $command Command and arguments
     * @param array<string, string> $env Environment variables
     */
    public function as(string|int $name, array $command, array $env = []): self
    {
        $this->tasks[] = [
            'name' => $name,
            'command' => $command,
            'env' => $env,
            'cwd' => $this->workingDirectory,
            'timeout' => $this->timeout,
        ];

        return $this;
    }

    /**
     * Set callback for real-time output streaming.
     *
     * @param callable(string $type, string $output, string|int $name): void $callback
     */
    public function onOutput(callable $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

    /**
     * Start all processes and wait for completion.
     *
     * @return array<string|int, ProcessResult> Results keyed by process name
     */
    public function start(): array
    {
        if (empty($this->tasks)) {
            return [];
        }

        $results = [];
        $pending = $this->tasks;
        $running = [];

        while (!empty($pending) || !empty($running)) {
            // Start new processes up to concurrency limit
            while (count($running) < $this->maxConcurrent && !empty($pending)) {
                $task = array_shift($pending);
                $processInfo = $this->startProcess($task);

                if ($processInfo !== null) {
                    $running[$task['name']] = $processInfo;
                }
            }

            // Poll running processes
            $this->pollProcesses($running, $results);

            // Small delay to prevent CPU spinning
            if (!empty($running)) {
                usleep(5000); // 5ms - balance between responsiveness and CPU usage
            }
        }

        // Ensure results are ordered by original task order
        return $this->orderResults($results);
    }

    /**
     * Start a single process.
     *
     * @param array{name: string|int, command: array, env: array, cwd: string|null, timeout: float} $task
     * @return array{process: resource, pipes: array, task: array, startTime: float}|null
     */
    private function startProcess(array $task): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Merge environment with current environment
        $env = array_merge($_ENV, $_SERVER, $task['env']);

        // Filter out non-string values
        $env = array_filter($env, fn($v) => is_string($v));

        $process = @proc_open(
            $task['command'],
            $descriptors,
            $pipes,
            $task['cwd'] ?? getcwd(),
            $env
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Failed to start process: ' . implode(' ', $task['command'])
            );
        }

        // Set pipes to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Close stdin - we don't need to write to the process
        fclose($pipes[0]);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'task' => $task,
            'startTime' => microtime(true),
            'stdout' => '',
            'stderr' => '',
        ];
    }

    /**
     * Poll running processes for completion.
     *
     * @param array<string|int, array> &$running
     * @param array<string|int, ProcessResult> &$results
     */
    private function pollProcesses(array &$running, array &$results): void
    {
        foreach ($running as $name => $info) {
            // Read available output
            $stdout = stream_get_contents($info['pipes'][1]);
            $stderr = stream_get_contents($info['pipes'][2]);

            if ($stdout !== false && $stdout !== '') {
                $running[$name]['stdout'] .= $stdout;
                $this->notifyOutput('out', $stdout, $name);
            }

            if ($stderr !== false && $stderr !== '') {
                $running[$name]['stderr'] .= $stderr;
                $this->notifyOutput('err', $stderr, $name);
            }

            // Check process status
            $status = proc_get_status($info['process']);
            $elapsed = microtime(true) - $info['startTime'];

            // Check timeout
            if ($info['task']['timeout'] > 0 && $elapsed > $info['task']['timeout']) {
                $this->terminateProcess($info);
                $results[$name] = new ProcessResult(
                    $running[$name]['stdout'],
                    $running[$name]['stderr'] . "\nProcess timed out after {$info['task']['timeout']}s",
                    -1,
                    $info['task']['command'],
                    $elapsed
                );
                unset($running[$name]);
                continue;
            }

            // Check if process has finished
            if (!$status['running']) {
                // Read any remaining output
                $finalStdout = stream_get_contents($info['pipes'][1]);
                $finalStderr = stream_get_contents($info['pipes'][2]);

                if ($finalStdout !== false) {
                    $running[$name]['stdout'] .= $finalStdout;
                }
                if ($finalStderr !== false) {
                    $running[$name]['stderr'] .= $finalStderr;
                }

                // Close pipes
                fclose($info['pipes'][1]);
                fclose($info['pipes'][2]);

                // Get exit code
                $exitCode = $status['exitcode'];
                if ($exitCode === -1) {
                    // Process may have been signaled
                    $exitCode = $status['termsig'] > 0 ? 128 + $status['termsig'] : 0;
                }

                proc_close($info['process']);

                $results[$name] = new ProcessResult(
                    $running[$name]['stdout'],
                    $running[$name]['stderr'],
                    $exitCode,
                    $info['task']['command'],
                    microtime(true) - $info['startTime']
                );

                unset($running[$name]);
            }
        }
    }

    /**
     * Terminate a process.
     *
     * @param array{process: resource, pipes: array} $info
     */
    private function terminateProcess(array $info): void
    {
        // Send SIGTERM first
        @proc_terminate($info['process'], 15);
        usleep(10000); // 10ms grace period

        // Force kill if still running
        $status = proc_get_status($info['process']);
        if ($status['running']) {
            @proc_terminate($info['process'], 9);
        }

        // Close pipes
        @fclose($info['pipes'][1]);
        @fclose($info['pipes'][2]);

        @proc_close($info['process']);
    }

    /**
     * Notify output callback.
     */
    private function notifyOutput(string $type, string $output, string|int $name): void
    {
        if ($this->outputCallback !== null && $output !== '') {
            ($this->outputCallback)($type, $output, $name);
        }
    }

    /**
     * Order results by original task order.
     *
     * @param array<string|int, ProcessResult> $results
     * @return array<string|int, ProcessResult>
     */
    private function orderResults(array $results): array
    {
        $ordered = [];

        foreach ($this->tasks as $task) {
            $name = $task['name'];
            if (isset($results[$name])) {
                $ordered[$name] = $results[$name];
            }
        }

        return $ordered;
    }

    /**
     * Get the number of pending tasks.
     */
    public function count(): int
    {
        return count($this->tasks);
    }

    /**
     * Clear all pending tasks.
     */
    public function clear(): self
    {
        $this->tasks = [];
        return $this;
    }
}
