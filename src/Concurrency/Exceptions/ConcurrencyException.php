<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Concurrency Exception
 *
 * Base exception for all concurrency-related errors.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class ConcurrencyException extends RuntimeException
{
    /**
     * The task key that caused the exception.
     */
    private string|int|null $taskKey;

    /**
     * The original exception from the task (if any).
     */
    private ?Throwable $taskException;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string|int|null $taskKey = null,
        ?Throwable $taskException = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->taskKey = $taskKey;
        $this->taskException = $taskException;
    }

    /**
     * Get the task key that caused the exception.
     */
    public function getTaskKey(): string|int|null
    {
        return $this->taskKey;
    }

    /**
     * Get the original task exception.
     */
    public function getTaskException(): ?Throwable
    {
        return $this->taskException;
    }

    /**
     * Create exception for a failed task.
     */
    public static function taskFailed(
        string|int $key,
        string $message,
        int $exitCode = 1,
        ?Throwable $previous = null
    ): self {
        return new self(
            sprintf('Concurrency task "%s" failed: %s', $key, $message),
            $exitCode,
            $previous,
            $key
        );
    }

    /**
     * Create exception for process failure.
     */
    public static function processFailed(
        string|int $key,
        string $errorOutput,
        int $exitCode
    ): self {
        return new self(
            sprintf(
                'Process for task "%s" failed with exit code %d: %s',
                $key,
                $exitCode,
                $errorOutput
            ),
            $exitCode,
            null,
            $key
        );
    }

    /**
     * Create exception for fork failure.
     */
    public static function forkFailed(string $reason): self
    {
        return new self(sprintf('Failed to fork process: %s', $reason));
    }

    /**
     * Create exception for unsupported environment.
     */
    public static function unsupportedEnvironment(string $driver, string $reason): self
    {
        return new self(sprintf(
            'Concurrency driver "%s" is not supported: %s',
            $driver,
            $reason
        ));
    }
}
