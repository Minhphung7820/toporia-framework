<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers;

use Toporia\Framework\Realtime\Contracts\{BrokerInterface, HealthCheckableInterface, HealthCheckResult, MessageInterface};
use Toporia\Framework\Realtime\Exceptions\{BrokerException, BrokerTemporaryException};
use Toporia\Framework\Realtime\RealtimeManager;

/**
 * Class RedisBroker
 *
 * Redis Pub/Sub broker for multi-server realtime communication. Enables horizontal scaling by broadcasting messages across servers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RedisBroker implements BrokerInterface, HealthCheckableInterface
{
    private \Redis $redis;
    private \Redis $subscriber;
    private array $subscriptions = [];
    private array $patternSubscriptions = [];
    private bool $connected = false;
    private bool $consuming = false;

    /**
     * Track if subscriber is already in subscribe mode.
     * Redis subscribe() is a one-time blocking call that maintains connection.
     */
    private bool $subscriberActive = false;

    /**
     * Current read timeout setting to avoid redundant setOption() calls.
     */
    private float $currentReadTimeout = 0.0;

    public function __construct(
        array $config = [],
        private readonly ?RealtimeManager $manager = null
    ) {
        // Runtime check: Ensure Redis extension is loaded
        if (!extension_loaded('redis')) {
            throw BrokerException::invalidConfiguration(
                'redis',
                "Redis extension is not installed. Install it with:\n" .
                "  Ubuntu/Debian: sudo apt-get install php-redis\n" .
                "  macOS: pecl install redis"
            );
        }

        $this->redis = new \Redis();
        $this->subscriber = new \Redis();

        // Connect to Redis
        // Note: read_timeout is always 0 (infinite) for subscriber to prevent
        // "read error on connection" during blocking SUBSCRIBE
        $this->connect(
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 2.0),
            0.0 // Always 0 (infinite) for subscriber - DO NOT use config['read_timeout']
        );

        // Authenticate if password provided
        if (!empty($config['password'])) {
            try {
                $this->redis->auth($config['password']);
                $this->subscriber->auth($config['password']);
            } catch (\RedisException $e) {
                throw BrokerException::connectionFailed('redis', "Authentication failed: {$e->getMessage()}", $e);
            }
        }

        // Select database
        if (isset($config['database'])) {
            $this->redis->select((int) $config['database']);
            $this->subscriber->select((int) $config['database']);
        }

        $this->connected = true;
    }

    /**
     * Connect to Redis with retry logic.
     *
     * @param string $host
     * @param int $port
     * @param float $timeout Connection timeout
     * @param float $readTimeout Read timeout (0 = infinite, needed for subscriber blocking)
     * @return void
     */
    private function connect(string $host, int $port, float $timeout, float $readTimeout = 0.0): void
    {
        try {
            // Publisher connection - normal timeout
            $this->redis->connect($host, $port, $timeout);

            // Subscriber connection
            $this->subscriber->connect($host, $port, $timeout);

            // Set read timeout for subscriber AFTER connect
            // Use 5 seconds to allow periodic heartbeat checks
            // This causes RedisException on timeout, which we handle in consume()
            $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, 5.0);
        } catch (\RedisException $e) {
            throw BrokerException::connectionFailed('redis', "{$host}:{$port} - {$e->getMessage()}", $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function publish(string $channel, MessageInterface $message): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        // Publish to Redis channel
        // Format: realtime:{channel}
        $redisChannel = "realtime:{$channel}";
        $payload = $message->toJson();

        try {
            $this->redis->publish($redisChannel, $payload);
        } catch (\RedisException $e) {
            throw BrokerException::publishFailed('redis', $channel, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        $redisChannel = "realtime:{$channel}";

        // Store callback with channel mapping
        if (!isset($this->subscriptions[$redisChannel])) {
            $this->subscriptions[$redisChannel] = [];
        }
        $this->subscriptions[$redisChannel][$channel] = $callback;

        // Note: Actual subscription happens in consume() method
        // This method just registers the subscription
    }

    /**
     * Subscribe to channels using pattern matching (PSUBSCRIBE).
     *
     * Supports Redis pattern syntax:
     * - '*' matches any characters
     * - '?' matches single character
     * - '[abc]' matches a, b, or c
     *
     * Examples:
     * - 'realtime:*' - all realtime channels
     * - 'realtime:user.*' - all user channels
     * - 'realtime:presence-*' - all presence channels
     *
     * @param string $pattern Pattern to match (without 'realtime:' prefix)
     * @param callable $callback Callback receives (MessageInterface $message, string $channel)
     * @return void
     */
    public function psubscribe(string $pattern, callable $callback): void
    {
        if (!$this->connected) {
            throw BrokerException::notConnected('redis');
        }

        // Add 'realtime:' prefix to pattern
        $redisPattern = "realtime:{$pattern}";

        // Store pattern subscription
        $this->patternSubscriptions[$redisPattern] = $callback;
    }

    /**
     * Start consuming messages from subscribed channels.
     *
     * Optimized for production with minimal overhead:
     * - Caches read timeout to avoid redundant setOption() calls
     * - Uses native Redis Pub/Sub (push model, no polling)
     * - Returns control periodically via read timeout for heartbeat
     *
     * Note: Redis Pub/Sub is inherently push-based and very efficient.
     * The main optimization is reducing syscall overhead.
     *
     * @param int $timeoutMs Poll timeout in milliseconds (used as read timeout)
     * @param int $batchSize Maximum messages per batch (not used for Redis Pub/Sub)
     * @return void
     */
    public function consume(int $timeoutMs = 1000, int $batchSize = 100): void
    {
        // Check if we have pattern subscriptions (PSUBSCRIBE)
        if (!empty($this->patternSubscriptions)) {
            $this->consumePatterns($timeoutMs);
            return;
        }

        // Check if we have regular subscriptions
        if (empty($this->subscriptions)) {
            return;
        }

        $this->consuming = true;

        // Get all Redis channels to subscribe
        $redisChannels = array_keys($this->subscriptions);

        if (empty($redisChannels)) {
            return;
        }

        // Only set read timeout if it changed (avoid syscall overhead)
        $timeoutSeconds = max(1.0, $timeoutMs / 1000);
        if (abs($this->currentReadTimeout - $timeoutSeconds) > 0.001) {
            $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, $timeoutSeconds);
            $this->currentReadTimeout = $timeoutSeconds;
        }

        try {
            // Subscribe to all channels (blocking operation with timeout)
            // Will throw RedisException on timeout, which is normal behavior
            $this->subscriber->subscribe($redisChannels, function ($redis, $redisChannel, $payload) {
                // Check if we should stop (called by signal handler)
                if (!$this->consuming) {
                    return false;
                }

                $subscriptions = $this->subscriptions[$redisChannel] ?? null;

                if (!$subscriptions) {
                    return true;
                }

                try {
                    // Decode message (hot path - keep minimal)
                    $message = \Toporia\Framework\Realtime\Message::fromJson($payload);

                    // Extract channel name from Redis channel (remove "realtime:" prefix)
                    $channel = str_replace('realtime:', '', $redisChannel);

                    // Handle array of callbacks per channel
                    if (is_array($subscriptions)) {
                        $callback = $subscriptions[$channel] ?? null;
                        if ($callback) {
                            $callback($message);
                        } else {
                            // Fallback: try all callbacks
                            foreach ($subscriptions as $innerCallback) {
                                if (is_callable($innerCallback)) {
                                    $innerCallback($message);
                                }
                            }
                        }
                    } elseif (is_callable($subscriptions)) {
                        $subscriptions($message);
                    }
                } catch (\Throwable $e) {
                    error_log("Redis subscriber error on {$redisChannel}: {$e->getMessage()}");
                }

                return true;
            });
        } catch (\RedisException $e) {
            // Read timeout is normal - return control to caller for heartbeat
            $errorMessage = strtolower($e->getMessage());
            if (!str_contains($errorMessage, 'timeout') && !str_contains($errorMessage, 'read error')) {
                throw $e;
            }
        }
    }

    /**
     * Consume messages using pattern subscriptions (PSUBSCRIBE).
     *
     * Optimized for production with cached read timeout.
     *
     * @param int $timeoutMs Poll timeout in milliseconds
     * @return void
     */
    private function consumePatterns(int $timeoutMs = 1000): void
    {
        $this->consuming = true;

        $patterns = array_keys($this->patternSubscriptions);

        if (empty($patterns)) {
            return;
        }

        // Only set read timeout if it changed (avoid syscall overhead)
        $timeoutSeconds = max(1.0, $timeoutMs / 1000);
        if (abs($this->currentReadTimeout - $timeoutSeconds) > 0.001) {
            $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, $timeoutSeconds);
            $this->currentReadTimeout = $timeoutSeconds;
        }

        try {
            // Use PSUBSCRIBE for pattern matching (with timeout)
            $this->subscriber->psubscribe($patterns, function ($redis, $pattern, $redisChannel, $payload) {
                if (!$this->consuming) {
                    return false;
                }

                $callback = $this->patternSubscriptions[$pattern] ?? null;

                if (!$callback) {
                    return true;
                }

                try {
                    $message = \Toporia\Framework\Realtime\Message::fromJson($payload);
                    $channel = str_replace('realtime:', '', $redisChannel);
                    $callback($message, $channel);
                } catch (\Throwable $e) {
                    error_log("Redis psubscriber error on {$redisChannel} (pattern: {$pattern}): {$e->getMessage()}");
                }

                return true;
            });
        } catch (\RedisException $e) {
            $errorMessage = strtolower($e->getMessage());
            if (!str_contains($errorMessage, 'timeout') && !str_contains($errorMessage, 'read error')) {
                throw $e;
            }
        }
    }

    /**
     * Stop consuming messages.
     *
     * Unsubscribes from all channels to exit the blocking subscribe() call.
     *
     * Performance:
     * - O(N) where N = number of subscribed channels
     * - Fast operation (Redis command)
     *
     * @return void
     */
    public function stopConsuming(): void
    {
        $this->consuming = false;

        // Unsubscribe from pattern subscriptions
        if (!empty($this->patternSubscriptions)) {
            $patterns = array_keys($this->patternSubscriptions);
            try {
                $this->subscriber->punsubscribe($patterns);
            } catch (\Throwable $e) {
                error_log("Error punsubscribing from Redis: {$e->getMessage()}");
            }
        }

        // Unsubscribe from all channels to exit blocking subscribe()
        // This will cause subscribe() callback to return false and exit
        if (!empty($this->subscriptions)) {
            $redisChannels = array_keys($this->subscriptions);
            try {
                $this->subscriber->unsubscribe($redisChannels);
            } catch (\Throwable $e) {
                // Ignore errors during shutdown
                error_log("Error unsubscribing from Redis: {$e->getMessage()}");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        $redisChannel = "realtime:{$channel}";

        // Handle both old format (single callback) and new format (array of callbacks)
        if (isset($this->subscriptions[$redisChannel])) {
            if (is_array($this->subscriptions[$redisChannel])) {
                unset($this->subscriptions[$redisChannel][$channel]);
                // Remove Redis channel entry if no more channels
                if (empty($this->subscriptions[$redisChannel])) {
                    unset($this->subscriptions[$redisChannel]);
                    $this->subscriber->unsubscribe([$redisChannel]);
                }
            } else {
                // Old format: single callback
                unset($this->subscriptions[$redisChannel]);
                $this->subscriber->unsubscribe([$redisChannel]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(string $channel): int
    {
        $redisChannel = "realtime:{$channel}";

        // Use PUBSUB NUMSUB command
        $result = $this->redis->pubsub('NUMSUB', $redisChannel);

        return (int) ($result[$redisChannel] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        try {
            $this->redis->close();
            $this->subscriber->close();
        } catch (\Throwable $e) {
            error_log("Error disconnecting from Redis: {$e->getMessage()}");
        }

        $this->connected = false;
        $this->subscriptions = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'redis';
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): HealthCheckResult
    {
        if (!$this->connected) {
            return HealthCheckResult::unhealthy('Redis broker not connected');
        }

        $start = microtime(true);

        try {
            // Ping Redis to check connection
            $pong = $this->redis->ping();
            $latencyMs = (microtime(true) - $start) * 1000;

            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                // Get additional info
                $info = $this->redis->info('server');
                $version = $info['redis_version'] ?? 'unknown';

                return HealthCheckResult::healthy(
                    message: 'Redis connection healthy',
                    details: [
                        'version' => $version,
                        'connected_clients' => $info['connected_clients'] ?? 0,
                    ],
                    latencyMs: $latencyMs
                );
            }

            return HealthCheckResult::degraded(
                message: 'Redis ping returned unexpected response',
                details: ['response' => $pong],
                latencyMs: $latencyMs
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy(
                message: "Redis health check failed: {$e->getMessage()}",
                details: ['exception' => $e::class]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHealthCheckName(): string
    {
        return 'redis-broker';
    }

    /**
     * Destructor - ensure clean disconnect.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
