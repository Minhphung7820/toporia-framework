<?php

declare(strict_types=1);

namespace Toporia\Framework\RateLimit;

/**
 * Class Limit
 *
 * Represents a rate limit configuration with max attempts, decay time, and key resolver.
 * Similar to other frameworks's Limit class but with enhanced features.
 *
 * Usage:
 * ```php
 * Limit::perMinute(100)->by($request->user()?->id ?? $request->ip());
 * Limit::perHour(1000)->by($request->ip());
 * Limit::perDay(10000)->by($request->user()?->id);
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  RateLimit
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Limit
{
    /**
     * @var callable|null Key resolver callback
     */
    private $keyResolver = null;

    /**
     * @param int $maxAttempts Maximum number of attempts
     * @param int $decaySeconds Decay time in seconds
     * @param callable|null $keyResolver Callback to resolve rate limit key
     * @param string|null $prefix Optional prefix for rate limit key
     */
    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
        ?callable $keyResolver = null,
        private readonly ?string $prefix = null
    ) {
        $this->keyResolver = $keyResolver;
    }

    /**
     * Create a limit per minute.
     *
     * @param int $maxAttempts Maximum attempts per minute
     * @return self
     */
    public static function perMinute(int $maxAttempts): self
    {
        return new self($maxAttempts, 60);
    }

    /**
     * Create a limit per hour.
     *
     * @param int $maxAttempts Maximum attempts per hour
     * @return self
     */
    public static function perHour(int $maxAttempts): self
    {
        return new self($maxAttempts, 3600);
    }

    /**
     * Create a limit per day.
     *
     * @param int $maxAttempts Maximum attempts per day
     * @return self
     */
    public static function perDay(int $maxAttempts): self
    {
        return new self($maxAttempts, 86400);
    }

    /**
     * Create a limit with custom decay time.
     *
     * @param int $maxAttempts Maximum attempts
     * @param int $decaySeconds Decay time in seconds
     * @return self
     */
    public static function per(int $maxAttempts, int $decaySeconds): self
    {
        return new self($maxAttempts, $decaySeconds);
    }

    /**
     * Set the key resolver callback or direct key value.
     *
     * @param callable|string $keyResolver Callback that receives Request and returns string key, or direct key string
     * @return self
     */
    public function by(callable|string $keyResolver): self
    {
        // If string, wrap in closure
        if (is_string($keyResolver)) {
            $resolver = fn() => $keyResolver;
        } else {
            $resolver = $keyResolver;
        }

        return new self(
            $this->maxAttempts,
            $this->decaySeconds,
            $resolver,
            $this->prefix
        );
    }

    /**
     * Set a prefix for the rate limit key.
     *
     * @param string $prefix Prefix string
     * @return self
     */
    public function prefix(string $prefix): self
    {
        return new self(
            $this->maxAttempts,
            $this->decaySeconds,
            $this->keyResolver,
            $prefix
        );
    }

    /**
     * Get maximum attempts.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get decay time in seconds.
     *
     * @return int
     */
    public function getDecaySeconds(): int
    {
        return $this->decaySeconds;
    }

    /**
     * Get decay time in minutes.
     *
     * @return int
     */
    public function getDecayMinutes(): int
    {
        return (int) ceil($this->decaySeconds / 60);
    }

    /**
     * Get key resolver callback.
     *
     * @return callable|null
     */
    public function getKeyResolver(): ?callable
    {
        return $this->keyResolver;
    }

    /**
     * Get prefix.
     *
     * @return string|null
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }
}
