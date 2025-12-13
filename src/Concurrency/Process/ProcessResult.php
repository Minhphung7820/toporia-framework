<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Process;

/**
 * Process Result
 *
 * Immutable value object representing the result of a process execution.
 * Contains stdout, stderr, exit code, and command information.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ProcessResult
{
    /**
     * @param string $stdout Standard output from the process
     * @param string $stderr Standard error output from the process
     * @param int $exitCode Process exit code (0 = success)
     * @param array<int, string> $command The command that was executed
     * @param float $duration Execution duration in seconds
     */
    public function __construct(
        private readonly string $stdout,
        private readonly string $stderr,
        private readonly int $exitCode,
        private readonly array $command,
        private readonly float $duration = 0.0
    ) {
    }

    /**
     * Check if the process completed successfully.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Check if the process failed.
     */
    public function failed(): bool
    {
        return $this->exitCode !== 0;
    }

    /**
     * Get the standard output.
     */
    public function output(): string
    {
        return $this->stdout;
    }

    /**
     * Get trimmed standard output.
     */
    public function outputTrimmed(): string
    {
        return trim($this->stdout);
    }

    /**
     * Get the standard error output.
     */
    public function errorOutput(): string
    {
        return $this->stderr;
    }

    /**
     * Get trimmed error output.
     */
    public function errorOutputTrimmed(): string
    {
        return trim($this->stderr);
    }

    /**
     * Get the process exit code.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Get the executed command.
     *
     * @return array<int, string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /**
     * Get the command as a string.
     */
    public function commandString(): string
    {
        return implode(' ', array_map('escapeshellarg', $this->command));
    }

    /**
     * Get the execution duration in seconds.
     */
    public function duration(): float
    {
        return $this->duration;
    }

    /**
     * Throw an exception if the process failed.
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\ConcurrencyException
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw \Toporia\Framework\Concurrency\Exceptions\ConcurrencyException::processFailed(
                $this->commandString(),
                $this->errorOutput() ?: $this->output(),
                $this->exitCode
            );
        }

        return $this;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful(),
            'exit_code' => $this->exitCode,
            'output' => $this->stdout,
            'error_output' => $this->stderr,
            'command' => $this->command,
            'duration' => $this->duration,
        ];
    }
}
