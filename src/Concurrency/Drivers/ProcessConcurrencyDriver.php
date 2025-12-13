<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Drivers;

use InvalidArgumentException;
use Toporia\Framework\Concurrency\Contracts\ClosureSerializerInterface;
use Toporia\Framework\Concurrency\Contracts\ConcurrencyDriverInterface;
use Toporia\Framework\Concurrency\Contracts\ProcessFactoryInterface;
use Toporia\Framework\Concurrency\Exceptions\ConcurrencyException;
use Toporia\Framework\Concurrency\Process\ProcessPool;

/**
 * Process Concurrency Driver
 *
 * Executes closures concurrently by spawning separate PHP CLI processes.
 * Each closure is serialized, passed via environment variable, and executed
 * by the `concurrency:invoke` console command.
 *
 * How it works:
 * 1. Serialize each closure using ClosureSerializerInterface
 * 2. Spawn PHP CLI processes with serialized closure in env var
 * 3. Each process runs `console concurrency:invoke` command
 * 4. Command deserializes closure, executes it, serializes result to stdout
 * 5. Parent collects stdout, deserializes results
 *
 * Advantages:
 * - Works on all platforms (Unix, Windows)
 * - Full application bootstrap in each process (access to services, DB, etc.)
 * - Memory isolation between tasks
 *
 * Disadvantages:
 * - Higher overhead than fork (full PHP bootstrap per task)
 * - Requires closure serialization support
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ProcessConcurrencyDriver implements ConcurrencyDriverInterface
{
    /**
     * Environment variable name for passing serialized closure.
     */
    public const ENV_CLOSURE = 'TOPORIA_INVOKABLE_CLOSURE';

    /**
     * Environment variable name for passing task key.
     */
    public const ENV_TASK_KEY = 'TOPORIA_TASK_KEY';

    /**
     * @param ProcessFactoryInterface $processFactory Factory for creating process pools
     * @param ClosureSerializerInterface $serializer Closure serialization handler
     * @param string $phpBinary Path to PHP binary
     * @param string $consoleBinary Console entry point (e.g., 'console')
     * @param string|null $workingDirectory Working directory for processes
     * @param float $timeout Default timeout in seconds
     */
    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
        private readonly ClosureSerializerInterface $serializer,
        private readonly string $phpBinary = 'php',
        private readonly string $consoleBinary = 'console',
        private readonly ?string $workingDirectory = null,
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

        // Validate and normalize tasks
        $normalizedTasks = $this->normalizeTasks($tasks);
        $keys = array_keys($normalizedTasks);

        // Build and execute process pool
        $pool = $this->buildProcessPool($normalizedTasks);
        $processResults = $pool->start();

        // Map process results back to task results
        return $this->collectResults($keys, $processResults);
    }

    /**
     * {@inheritdoc}
     */
    public function defer(array $tasks): void
    {
        if (empty($tasks)) {
            return;
        }

        // For defer, we run tasks but don't wait for results in the traditional sense
        // We use register_shutdown_function to ensure cleanup
        // For now, implement as immediate async execution
        $normalizedTasks = $this->normalizeTasks($tasks);
        $pool = $this->buildProcessPool($normalizedTasks);

        // Start processes but don't block on results
        // In a more sophisticated implementation, we'd use pcntl_signal
        // or a proper async pattern. For now, we just run them.
        register_shutdown_function(function () use ($pool): void {
            // Flush output first
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $pool->start();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'process';
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

    /**
     * Build process pool for tasks.
     *
     * @param array<int|string, callable> $tasks
     */
    private function buildProcessPool(array $tasks): ProcessPool
    {
        return $this->processFactory->pool(function (ProcessPool $pool) use ($tasks): void {
            if ($this->workingDirectory !== null) {
                $pool->path($this->workingDirectory);
            }

            $pool->timeout($this->timeout);

            foreach ($tasks as $key => $closure) {
                // Serialize the closure
                $serializedClosure = $this->serializer->serializeClosure($closure);
                $encodedClosure = $this->serializer->encode($serializedClosure);

                // Build command
                $command = [$this->phpBinary, $this->consoleBinary, 'concurrency:invoke'];

                // Build environment
                $env = [
                    self::ENV_CLOSURE => $encodedClosure,
                    self::ENV_TASK_KEY => (string) $key,
                ];

                // Add to pool with task key as name
                $pool->as($key, $command, $env);
            }
        });
    }

    /**
     * Collect and deserialize results from process outputs.
     *
     * @param array<int, int|string> $keys Original task keys
     * @param array<int|string, \Toporia\Framework\Concurrency\Process\ProcessResult> $processResults
     * @return array<int|string, mixed>
     * @throws ConcurrencyException
     */
    private function collectResults(array $keys, array $processResults): array
    {
        $results = [];

        foreach ($keys as $key) {
            if (!isset($processResults[$key])) {
                throw ConcurrencyException::taskFailed(
                    $key,
                    'No result received from process'
                );
            }

            $processResult = $processResults[$key];

            if ($processResult->failed()) {
                throw ConcurrencyException::processFailed(
                    $key,
                    $processResult->errorOutput() ?: 'Unknown error',
                    $processResult->exitCode()
                );
            }

            // Deserialize the result from stdout
            $output = $processResult->outputTrimmed();

            if ($output === '') {
                $results[$key] = null;
                continue;
            }

            try {
                $results[$key] = $this->serializer->unserializeResult($output);
            } catch (\Throwable $e) {
                throw ConcurrencyException::taskFailed(
                    $key,
                    'Failed to deserialize result: ' . $e->getMessage(),
                    1,
                    $e
                );
            }
        }

        return $results;
    }
}
