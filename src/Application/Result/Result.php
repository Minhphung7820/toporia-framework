<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Result;

/**
 * Result Object
 *
 * Wrapper for handler return values.
 * Provides a consistent way to return success/failure results.
 *
 * Usage:
 * ```php
 * // Success
 * return Result::success($data);
 *
 * // Failure
 * return Result::failure('Error message');
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Result
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Result
{
    /**
     * @param bool $success Whether the operation was successful
     * @param mixed $data Result data
     * @param string|null $message Optional message
     * @param array<string, mixed> $meta Optional metadata
     */
    private function __construct(
        private readonly bool $success,
        private readonly mixed $data = null,
        private readonly ?string $message = null,
        private readonly array $meta = []
    ) {}

    /**
     * Create a successful result.
     *
     * @param mixed $data Result data
     * @param string|null $message Optional success message
     * @param array<string, mixed> $meta Optional metadata
     * @return self
     */
    public static function success(mixed $data = null, ?string $message = null, array $meta = []): self
    {
        return new self(true, $data, $message, $meta);
    }

    /**
     * Create a failed result.
     *
     * @param string|null $message Error message
     * @param mixed $data Optional error data
     * @param array<string, mixed> $meta Optional metadata
     * @return self
     */
    public static function failure(?string $message = null, mixed $data = null, array $meta = []): self
    {
        return new self(false, $data, $message, $meta);
    }

    /**
     * Check if result is successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if result is failure.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Get result data.
     *
     * @return mixed
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get result message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get metadata.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * Get a specific metadata value.
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed
     */
    public function getMetaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Throw exception if result is failure.
     *
     * @param string|null $exceptionClass Custom exception class
     * @return self
     * @throws \Exception If result is failure
     */
    public function throwIfFailure(?string $exceptionClass = null): self
    {
        if ($this->isFailure()) {
            $exceptionClass = $exceptionClass ?? \RuntimeException::class;
            throw new $exceptionClass($this->message ?? 'Operation failed');
        }

        return $this;
    }

    /**
     * Transform result data.
     *
     * @param callable $transformer Callable to transform data
     * @return self
     */
    public function map(callable $transformer): self
    {
        if ($this->isSuccess()) {
            $transformedData = $transformer($this->data);
            return self::success($transformedData, $this->message, $this->meta);
        }

        return $this;
    }
}
