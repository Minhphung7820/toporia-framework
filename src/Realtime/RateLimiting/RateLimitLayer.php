<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\RateLimiting;

/**
 * Rate Limit Layer Enum
 *
 * Defines different layers of rate limiting for defense in depth.
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
enum RateLimitLayer: string
{
    /**
     * Global Layer
     *
     * Rate limit for entire system.
     * Protects against total system overload.
     *
     * Example: 100,000 requests/second globally
     */
    case GLOBAL = 'global';

    /**
     * IP Address Layer
     *
     * Rate limit per IP address.
     * Protects against DDoS from single source.
     *
     * Example: 100 connections/minute per IP
     */
    case IP_ADDRESS = 'ip';

    /**
     * Connection Layer
     *
     * Rate limit per WebSocket connection.
     * Protects against single connection abuse.
     *
     * Example: 60 messages/minute per connection
     */
    case CONNECTION = 'connection';

    /**
     * User Layer
     *
     * Rate limit per authenticated user.
     * Protects against user account abuse.
     *
     * Example: 1000 messages/hour per user
     */
    case USER = 'user';

    /**
     * Channel Layer
     *
     * Rate limit per channel.
     * Protects against channel flooding.
     *
     * Example: 10,000 messages/minute per channel
     */
    case CHANNEL = 'channel';

    /**
     * API Key Layer
     *
     * Rate limit per API key.
     * Protects against API key abuse.
     *
     * Example: 10,000 requests/day per API key
     */
    case API_KEY = 'api_key';

    /**
     * Get layer priority (lower = checked first).
     *
     * @return int
     */
    public function priority(): int
    {
        return match ($this) {
            self::GLOBAL => 1,      // Check global first
            self::IP_ADDRESS => 2,  // Then IP
            self::CONNECTION => 3,  // Then connection
            self::USER => 4,        // Then user
            self::API_KEY => 5,     // Then API key
            self::CHANNEL => 6,     // Channel last
        };
    }

    /**
     * Get default limit for this layer.
     *
     * @return int Messages per window
     */
    public function defaultLimit(): int
    {
        return match ($this) {
            self::GLOBAL => 100000,      // 100k/min globally
            self::IP_ADDRESS => 100,     // 100/min per IP
            self::CONNECTION => 60,      // 60/min per connection
            self::USER => 1000,          // 1000/hour per user
            self::API_KEY => 10000,      // 10k/day per API key
            self::CHANNEL => 10000,      // 10k/min per channel
        };
    }

    /**
     * Get default window in seconds.
     *
     * @return int
     */
    public function defaultWindow(): int
    {
        return match ($this) {
            self::GLOBAL => 60,          // 1 minute
            self::IP_ADDRESS => 60,      // 1 minute
            self::CONNECTION => 60,      // 1 minute
            self::USER => 3600,          // 1 hour
            self::API_KEY => 86400,      // 1 day
            self::CHANNEL => 60,         // 1 minute
        };
    }

    /**
     * Check if layer requires authentication.
     *
     * @return bool
     */
    public function requiresAuth(): bool
    {
        return match ($this) {
            self::USER => true,
            self::API_KEY => true,
            default => false,
        };
    }

    /**
     * Get Redis key prefix for this layer.
     *
     * @return string
     */
    public function redisPrefix(): string
    {
        return "realtime:ratelimit:{$this->value}";
    }
}
