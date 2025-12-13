<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;

/**
 * Class HealthCheckResult
 *
 * Health check result object.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNHEALTHY = 'unhealthy';

    /**
     * @param string $status Health status
     * @param string $message Human-readable message
     * @param array<string, mixed> $details Additional details
     * @param float $latencyMs Response latency in milliseconds
     * @param int $timestamp Check timestamp
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $details = [],
        public readonly float $latencyMs = 0.0,
        public readonly int $timestamp = 0
    ) {
    }

    /**
     * Create a healthy result.
     *
     * @param string $message Message
     * @param array<string, mixed> $details Details
     * @param float $latencyMs Latency
     * @return static
     */
    public static function healthy(string $message = 'OK', array $details = [], float $latencyMs = 0.0): static
    {
        return new static(
            status: self::STATUS_HEALTHY,
            message: $message,
            details: $details,
            latencyMs: $latencyMs,
            timestamp: time()
        );
    }

    /**
     * Create a degraded result.
     *
     * @param string $message Message
     * @param array<string, mixed> $details Details
     * @param float $latencyMs Latency
     * @return static
     */
    public static function degraded(string $message, array $details = [], float $latencyMs = 0.0): static
    {
        return new static(
            status: self::STATUS_DEGRADED,
            message: $message,
            details: $details,
            latencyMs: $latencyMs,
            timestamp: time()
        );
    }

    /**
     * Create an unhealthy result.
     *
     * @param string $message Error message
     * @param array<string, mixed> $details Details
     * @return static
     */
    public static function unhealthy(string $message, array $details = []): static
    {
        return new static(
            status: self::STATUS_UNHEALTHY,
            message: $message,
            details: $details,
            latencyMs: 0.0,
            timestamp: time()
        );
    }

    /**
     * Check if healthy.
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    /**
     * Check if degraded.
     *
     * @return bool
     */
    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    /**
     * Check if unhealthy.
     *
     * @return bool
     */
    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'latency_ms' => $this->latencyMs,
            'timestamp' => $this->timestamp,
        ];
    }
}
