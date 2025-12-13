<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Support;

/**
 * Class CronExpressionCache
 *
 * Caches parsed cron expression results for performance optimization.
 * Provides O(1) cache lookup after first evaluation with reduced
 * repeated parsing overhead and memory-efficient LRU eviction.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CronExpressionCache
{
    /**
     * @var array<string, array{matches: bool, nextRun: ?\DateTime}> Expression cache
     */
    private static array $cache = [];

    /**
     * @var int Maximum cache size (LRU eviction)
     */
    private const MAX_CACHE_SIZE = 100;

    /**
     * Check if cron expression matches current time (cached).
     *
     * @param string $expression Cron expression
     * @param \DateTime $currentTime Current time
     * @return bool
     */
    public static function matches(string $expression, \DateTime $currentTime): bool
    {
        $key = self::getCacheKey($expression, $currentTime);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key]['matches'];
        }

        // Not cached - will be evaluated by caller
        return false;
    }

    /**
     * Cache cron expression match result.
     *
     * @param string $expression
     * @param \DateTime $currentTime
     * @param bool $matches
     * @return void
     */
    public static function cache(string $expression, \DateTime $currentTime, bool $matches): void
    {
        $key = self::getCacheKey($expression, $currentTime);

        // LRU eviction if cache too large
        if (count(self::$cache) >= self::MAX_CACHE_SIZE) {
            // Remove oldest entry (simple FIFO)
            array_shift(self::$cache);
        }

        self::$cache[$key] = [
            'matches' => $matches,
            'nextRun' => null, // Can be calculated later
        ];
    }

    /**
     * Get cache key for expression and time.
     *
     * @param string $expression
     * @param \DateTime $time
     * @return string
     */
    private static function getCacheKey(string $expression, \DateTime $time): string
    {
        // Cache key includes expression and time rounded to minute
        $minute = $time->format('Y-m-d H:i');
        return md5($expression . '|' . $minute);
    }

    /**
     * Clear cache.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$cache = [];
    }

    /**
     * Get cache size.
     *
     * @return int
     */
    public static function size(): int
    {
        return count(self::$cache);
    }
}
