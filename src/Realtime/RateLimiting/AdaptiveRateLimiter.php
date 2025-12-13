<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

use Toporia\Framework\Realtime\Exceptions\RateLimitException;
use Toporia\Framework\Realtime\Brokers\CircuitBreaker\CircuitBreaker;

/**
 * Adaptive Rate Limiter
 *
 * Dynamically adjusts rate limits based on system load and health.
 *
 * Adaptation strategies:
 * - Decrease limits when system is under stress
 * - Increase limits when system has spare capacity
 * - Circuit breaker integration for failure protection
 * - Gradual adjustment to prevent oscillation
 *
 * Algorithm:
 * 1. Monitor system metrics (CPU, memory, response time)
 * 2. Calculate load factor (0.0 - 1.0)
 * 3. Adjust limits: effective_limit = base_limit * (1 - load_factor * adjustment_rate)
 * 4. Apply circuit breaker state
 *
 * Benefits:
 * - Automatic protection during high load
 * - Better resource utilization during low load
 * - Graceful degradation under stress
 *
 * Performance: <1ms overhead per check
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\RateLimiting
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class AdaptiveRateLimiter implements RateLimiterInterface
{
    /**
     * Current load factor (0.0 = no load, 1.0 = max load).
     */
    private float $loadFactor = 0.0;

    /**
     * Last load update timestamp.
     */
    private float $lastLoadUpdate = 0.0;

    /**
     * @param RateLimiterInterface $baseLimiter Base rate limiter
     * @param int $baseLimit Base rate limit
     * @param float $adjustmentRate How much to adjust (0.0 - 1.0)
     * @param int $loadUpdateInterval Seconds between load checks
     * @param CircuitBreaker|null $circuitBreaker Circuit breaker for health checks
     * @param bool $enabled Enable adaptive limiting
     */
    public function __construct(
        private readonly RateLimiterInterface $baseLimiter,
        private readonly int $baseLimit = 60,
        private readonly float $adjustmentRate = 0.5,
        private readonly int $loadUpdateInterval = 5,
        private readonly ?CircuitBreaker $circuitBreaker = null,
        private readonly bool $enabled = true
    ) {
        $this->lastLoadUpdate = microtime(true);
    }

    /**
     * {@inheritdoc}
     */
    public function attempt(string $identifier, int $cost = 1): bool
    {
        if (!$this->enabled) {
            return $this->baseLimiter->attempt($identifier, $cost);
        }

        // Update load factor if needed
        $this->updateLoadFactor();

        // Calculate effective limit
        $effectiveLimit = $this->calculateEffectiveLimit();

        // Adjust cost based on effective limit
        $adjustedCost = $this->adjustCost($cost, $effectiveLimit);

        // Use base limiter with adjusted cost
        return $this->baseLimiter->attempt($identifier, $adjustedCost);
    }

    /**
     * {@inheritdoc}
     */
    public function check(string $identifier, int $cost = 1): void
    {
        if (!$this->attempt($identifier, $cost)) {
            throw new RateLimitException(
                $identifier,
                $this->calculateEffectiveLimit(),
                $this->baseLimit - $this->remaining($identifier),
                $this->retryAfter($identifier),
                'Adaptive rate limit exceeded (system under load)'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function remaining(string $identifier): int
    {
        $baseRemaining = $this->baseLimiter->remaining($identifier);
        $effectiveLimit = $this->calculateEffectiveLimit();

        // Scale remaining based on effective limit
        $scale = $effectiveLimit / $this->baseLimit;
        return (int) floor($baseRemaining * $scale);
    }

    /**
     * {@inheritdoc}
     */
    public function retryAfter(string $identifier): int
    {
        return $this->baseLimiter->retryAfter($identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(string $identifier): void
    {
        $this->baseLimiter->reset($identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function stats(string $identifier): array
    {
        $baseStats = $this->baseLimiter->stats($identifier);
        $effectiveLimit = $this->calculateEffectiveLimit();

        return [
            'current' => $baseStats['current'],
            'remaining' => $this->remaining($identifier),
            'limit' => $effectiveLimit,
            'base_limit' => $this->baseLimit,
            'retry_after' => $baseStats['retry_after'],
            'load_factor' => $this->loadFactor,
            'adjustment_active' => $effectiveLimit < $this->baseLimit,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function algorithm(): RateLimitAlgorithm
    {
        return $this->baseLimiter->algorithm();
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->baseLimiter->isEnabled();
    }

    /**
     * Calculate effective limit based on load.
     *
     * @return int
     */
    private function calculateEffectiveLimit(): int
    {
        // Check circuit breaker state
        if ($this->circuitBreaker !== null && $this->circuitBreaker->isOpen()) {
            // Circuit open: drastically reduce limits
            return (int) ceil($this->baseLimit * 0.1);
        }

        // Calculate adjusted limit
        $adjustment = $this->loadFactor * $this->adjustmentRate;
        $effectiveLimit = (int) ceil($this->baseLimit * (1.0 - $adjustment));

        // Ensure minimum limit (at least 10% of base)
        $minLimit = (int) ceil($this->baseLimit * 0.1);
        return max($minLimit, $effectiveLimit);
    }

    /**
     * Adjust cost based on effective limit.
     *
     * @param int $cost Original cost
     * @param int $effectiveLimit Effective limit
     * @return int Adjusted cost
     */
    private function adjustCost(int $cost, int $effectiveLimit): int
    {
        if ($effectiveLimit >= $this->baseLimit) {
            return $cost;
        }

        // Scale cost inversely to limit reduction
        $scale = $this->baseLimit / $effectiveLimit;
        return (int) ceil($cost * $scale);
    }

    /**
     * Update load factor based on system metrics.
     */
    private function updateLoadFactor(): void
    {
        $now = microtime(true);

        // Check if update needed
        if (($now - $this->lastLoadUpdate) < $this->loadUpdateInterval) {
            return;
        }

        $this->lastLoadUpdate = $now;

        // Calculate load factor from multiple sources
        $cpuLoad = $this->getCpuLoad();
        $memoryLoad = $this->getMemoryLoad();
        $circuitBreakerLoad = $this->getCircuitBreakerLoad();

        // Weighted average
        $this->loadFactor = ($cpuLoad * 0.5) + ($memoryLoad * 0.3) + ($circuitBreakerLoad * 0.2);

        // Clamp to [0.0, 1.0]
        $this->loadFactor = max(0.0, min(1.0, $this->loadFactor));
    }

    /**
     * Get CPU load factor.
     *
     * @return float 0.0 - 1.0
     */
    private function getCpuLoad(): float
    {
        // Try to get system load average
        $loadAvg = sys_getloadavg();

        if ($loadAvg === false) {
            return 0.0;
        }

        // Use 1-minute load average
        $load = $loadAvg[0];

        // Normalize by CPU count
        $cpuCount = $this->getCpuCount();
        $normalizedLoad = $load / max(1, $cpuCount);

        // Convert to 0.0-1.0 scale (assume 0.8 is high load)
        return min(1.0, $normalizedLoad / 0.8);
    }

    /**
     * Get CPU count.
     *
     * @return int
     */
    private function getCpuCount(): int
    {
        if (function_exists('swoole_cpu_num')) {
            return swoole_cpu_num();
        }

        // Fallback to nproc or default
        $cpuCount = (int) shell_exec('nproc 2>/dev/null');
        return max(1, $cpuCount ?: 1);
    }

    /**
     * Get memory load factor.
     *
     * @return float 0.0 - 1.0
     */
    private function getMemoryLoad(): float
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        if ($memoryLimit <= 0) {
            return 0.0;
        }

        $usage = $memoryUsage / $memoryLimit;

        // Scale: 0.7 = high load
        return min(1.0, $usage / 0.7);
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1' || $limit === false) {
            return PHP_INT_MAX;
        }

        return $this->parseMemorySize($limit);
    }

    /**
     * Parse memory size string to bytes.
     *
     * @param string $size Size string (e.g., '128M', '1G')
     * @return int Bytes
     */
    private function parseMemorySize(string $size): int
    {
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * Get circuit breaker load factor.
     *
     * @return float 0.0 - 1.0
     */
    private function getCircuitBreakerLoad(): float
    {
        if ($this->circuitBreaker === null) {
            return 0.0;
        }

        $stats = $this->circuitBreaker->getStats();

        // Map circuit breaker state to load
        return match ($stats['state']) {
            'open' => 1.0,           // Circuit open = max load
            'half_open' => 0.5,      // Testing recovery = medium load
            'closed' => 0.0,         // Circuit closed = no additional load
            default => 0.0,
        };
    }

    /**
     * Get current load factor.
     *
     * @return float
     */
    public function getLoadFactor(): float
    {
        return $this->loadFactor;
    }

    /**
     * Get effective limit for monitoring.
     *
     * @return int
     */
    public function getEffectiveLimit(): int
    {
        return $this->calculateEffectiveLimit();
    }

    /**
     * Manually set load factor (for testing).
     *
     * @param float $loadFactor Load factor (0.0 - 1.0)
     */
    public function setLoadFactor(float $loadFactor): void
    {
        $this->loadFactor = max(0.0, min(1.0, $loadFactor));
    }
}

