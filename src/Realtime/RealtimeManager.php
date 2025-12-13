<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Contracts\{BrokerInterface, ChannelInterface, ConnectionInterface, RealtimeManagerInterface, TransportInterface};
use Toporia\Framework\Realtime\Exceptions\{BrokerException, ChannelException, RateLimitException};
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Realtime\ChannelRoute;
use Toporia\Framework\Realtime\Middleware;
use Toporia\Framework\Realtime\Auth;
use Toporia\Framework\Realtime\RateLimiting\MultiLayerRateLimiter;
use Toporia\Framework\Realtime\RateLimiting\RateLimiterFactory;
use Toporia\Framework\Realtime\RateLimiting\RateLimiterInterface;
use Toporia\Framework\Realtime\Security\DDoSProtection;
use Toporia\Framework\Realtime\Metrics\MiddlewareMetrics;

/**
 * Class RealtimeManager
 *
 * Central coordinator for realtime communication system. Manages transports, brokers, channels, and connections.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RealtimeManager implements RealtimeManagerInterface
{
    /**
     * @var array<string, ChannelInterface> Channel instances
     */
    private array $channels = [];

    /**
     * @var array<string, ConnectionInterface> Active connections
     */
    private array $connections = [];

    /**
     * @var array<string, TransportInterface> Transport instances
     */
    private array $transports = [];

    /**
     * @var array<string, BrokerInterface> Broker instances
     */
    private array $brokers = [];

    private string $defaultTransport;
    private ?string $defaultBroker;

    // Rate limiting system (v2)
    private ?MultiLayerRateLimiter $multiLayerLimiter = null;
    private ?RateLimiterInterface $channelLimiter = null;

    // Security components (v2)
    private ?DDoSProtection $ddosProtection = null;

    // Metrics (v2)
    private ?MiddlewareMetrics $metrics = null;

    private bool $validateInput = true;
    private bool $useEnhancedPipeline = false;

    /**
     * @param array $config Realtime configuration
     * @param ContainerInterface|null $container DI container
     */
    public function __construct(
        private array $config = [],
        private readonly ?ContainerInterface $container = null
    ) {
        $this->defaultTransport = $config['default_transport'] ?? 'memory';
        $this->defaultBroker = $config['default_broker'] ?? null;
        $this->validateInput = (bool) ($config['validate_input'] ?? true);
        $this->useEnhancedPipeline = (bool) ($config['use_enhanced_pipeline'] ?? true);

        // Initialize v2 components
        $this->initializeRateLimiting();
        $this->initializeSecurity();
        $this->initializeMetrics();
    }

    /**
     * Initialize v2 rate limiting system.
     */
    private function initializeRateLimiting(): void
    {
        // Load rate limiting config
        $rateLimitConfig = $this->loadRateLimitConfig();

        if (!($rateLimitConfig['enabled'] ?? true)) {
            return;
        }

        // Create Redis connection if available
        $redis = null;
        if ($rateLimitConfig['redis']['enabled'] ?? true) {
            $redis = RateLimiterFactory::createRedisConnection($rateLimitConfig['redis']);
        }

        // Create multi-layer rate limiter
        $this->multiLayerLimiter = RateLimiterFactory::createMultiLayer(
            $rateLimitConfig['layers'] ?? [],
            $redis
        );

        // Create channel-specific rate limiter
        $this->channelLimiter = RateLimiterFactory::create('channel', [
            'algorithm' => $rateLimitConfig['default_algorithm'] ?? 'token_bucket',
            'capacity' => $rateLimitConfig['rate_limiting']['connection_limit'] ?? 60,
            'redis' => $redis,
        ]);

        // Legacy v1 rate limiter removed - use MultiLayerRateLimiter (v2) instead
    }

    /**
     * Initialize security components.
     */
    private function initializeSecurity(): void
    {
        $rateLimitConfig = $this->loadRateLimitConfig();
        $ddosConfig = $rateLimitConfig['ddos_protection'] ?? [];

        if (!($ddosConfig['enabled'] ?? true)) {
            return;
        }

        // Create Redis connection for distributed DDoS protection
        $redis = null;
        if ($rateLimitConfig['redis']['enabled'] ?? true) {
            $redis = RateLimiterFactory::createRedisConnection($rateLimitConfig['redis']);
        }

        $this->ddosProtection = new DDoSProtection(
            redis: $redis,
            connectionThreshold: (int) ($ddosConfig['connection_threshold'] ?? 10),
            connectionWindow: (int) ($ddosConfig['connection_window'] ?? 60),
            blockDuration: (int) ($ddosConfig['block_duration'] ?? 3600),
            enabled: true
        );
    }

    /**
     * Initialize metrics collection.
     */
    private function initializeMetrics(): void
    {
        $rateLimitConfig = $this->loadRateLimitConfig();

        if (!($rateLimitConfig['metrics']['enabled'] ?? true)) {
            return;
        }

        $this->metrics = new MiddlewareMetrics();
    }

    /**
     * Load rate limiting configuration.
     */
    private function loadRateLimitConfig(): array
    {
        // Try to load from container config first
        if ($this->container !== null && $this->container->has('config')) {
            try {
                $config = $this->container->get('config');
                return $config->get('realtime-ratelimit', []);
            } catch (\Throwable $e) {
                // Fall through to config array
            }
        }

        // Try to load from config file directly
        $configFile = __DIR__ . '/../../../config/realtime-ratelimit.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }

        // Fallback to basic config
        return $this->config['rate_limiting'] ?? [];
    }

    /**
     * {@inheritdoc}
     *
     * Broadcast Architecture:
     * - Transport: Server <-> Client (WebSocket, SSE, Long-polling)
     * - Broker: Server <-> Server (Redis, Kafka, RabbitMQ, NATS)
     *
     * When broker is available (multi-server):
     * 1. Publish to broker (for other servers to receive)
     * 2. Broadcast locally (for clients on this server to receive)
     *
     * When no broker (single server):
     * - Only broadcast locally
     *
     * Usage:
     * - Can be called from ANYWHERE: HTTP requests, CLI commands, background jobs, events, etc.
     * - Producer (publish to broker) is available everywhere
     * - Consumer (consume from broker) is ONLY in CLI commands
     *
     * Examples:
     * - HTTP Controller: $realtime->broadcast('user.1', 'message', $data)
     * - CLI Command: $realtime->broadcast('user.1', 'message', $data)
     * - Background Job: $realtime->broadcast('user.1', 'message', $data)
     * - Event Listener: $realtime->broadcast('user.1', 'message', $data)
     *
     * Performance:
     * - O(1) broker publish
     * - O(N) local broadcast where N = local subscribers
     */
    public function broadcast(string $channel, string $event, mixed $data): void
    {
        // Validate input if enabled
        if ($this->validateInput) {
            ChannelValidator::validateChannel($channel);
            ChannelValidator::validateEvent($event);
        }

        // Check rate limit (v2 multi-layer or v1 legacy)
        if ($this->channelLimiter !== null) {
            try {
                $this->channelLimiter->check("channel:{$channel}");
            } catch (RateLimitException $e) {
                error_log("Rate limit exceeded for channel {$channel}: {$e->getMessage()}");
                throw $e;
            }
        }

        $message = Message::event($channel, $event, $data);

        // Always broadcast locally first (for clients on this server)
        $channelInstance = $this->channel($channel);
        $channelInstance->broadcast($message);

        // If broker is available, also publish to broker (for other servers)
        // Producer can be called from anywhere (HTTP, CLI, jobs, events, etc.)
        // Consumer is ONLY in CLI commands (long-lived processes)
        if ($broker = $this->broker()) {
            try {
                $broker->publish($channel, $message);
            } catch (BrokerException $e) {
                // Log but don't fail the broadcast - local clients still receive it
                error_log("Broker publish failed for channel {$channel}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Broadcast locally only (without publishing to broker).
     *
     * Used when receiving messages from broker in CLI commands.
     * Prevents infinite loop: broker message → broadcast → broker publish → ...
     *
     * Architecture:
     * - Called by CLI consumer commands when receiving messages from broker
     * - Only broadcasts to local clients via transport
     * - Does NOT publish to broker (to prevent message loop)
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param mixed $data Event data
     * @return void
     */
    public function broadcastLocal(string $channel, string $event, mixed $data): void
    {
        // Validate input if enabled
        if ($this->validateInput) {
            ChannelValidator::validateChannel($channel);
            ChannelValidator::validateEvent($event);
        }

        $message = Message::event($channel, $event, $data);

        // Broadcast locally only (no broker publish)
        $channelInstance = $this->channel($channel);
        $channelInstance->broadcast($message);
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $connectionId, string $event, mixed $data): void
    {
        $connection = $this->connections[$connectionId] ?? null;

        if (!$connection) {
            throw new \RuntimeException("Connection {$connectionId} not found");
        }

        $message = Message::event(null, $event, $data);
        $transport = $this->transport();
        $transport->send($connection, $message);
    }

    /**
     * {@inheritdoc}
     */
    public function sendToUser(string|int $userId, string $event, mixed $data): void
    {
        $userConnections = $this->getUserConnections($userId);

        if (empty($userConnections)) {
            return; // User not connected
        }

        $message = Message::event(null, $event, $data);
        $transport = $this->transport();

        foreach ($userConnections as $connection) {
            try {
                $transport->send($connection, $message);
            } catch (\Throwable $e) {
                error_log("Failed to send to user {$userId} connection {$connection->getId()}: {$e->getMessage()}");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function channel(string $name): ChannelInterface
    {
        // Validate channel name
        if ($this->validateInput) {
            ChannelValidator::validateChannel($name);
        }

        // Return cached channel if exists
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        // Create new channel
        $transport = $this->transport();
        $authorizer = $this->getChannelAuthorizer($name);

        $this->channels[$name] = new Channel($name, $transport, $authorizer);

        return $this->channels[$name];
    }

    /**
     * Get rate limiter instance.
     *
     * @return RateLimiterInterface|null
     */
    public function getRateLimiter(): ?RateLimiterInterface
    {
        return $this->channelLimiter;
    }

    /**
     * Set rate limiter instance.
     *
     * @param RateLimiterInterface|null $rateLimiter
     */
    public function setRateLimiter(?RateLimiterInterface $rateLimiter): void
    {
        $this->channelLimiter = $rateLimiter;
    }

    /**
     * Enable or disable input validation.
     *
     * @param bool $validate
     */
    public function setValidateInput(bool $validate): void
    {
        $this->validateInput = $validate;
    }

    /**
     * {@inheritdoc}
     */
    public function transport(?string $name = null): TransportInterface
    {
        $name = $name ?? $this->defaultTransport;

        // Return cached instance
        if (isset($this->transports[$name])) {
            return $this->transports[$name];
        }

        // Create new transport
        $this->transports[$name] = $this->createTransport($name);

        return $this->transports[$name];
    }

    /**
     * {@inheritdoc}
     *
     * Get broker instance for server-to-server communication.
     *
     * Architecture:
     * - Brokers are used ONLY for server-to-server communication
     * - Broker PRODUCER (publish): Can be called from ANYWHERE
     *   - HTTP requests, CLI commands, background jobs, events, etc.
     *   - Via broadcast() method (automatically publishes to broker)
     * - Broker CONSUMER (consume): ONLY in CLI commands
     *   - Long-lived processes (e.g., realtime:kafka:consume)
     *   - NEVER consume in HTTP requests (blocks request)
     *
     * Usage:
     * - Publishing: $manager->broadcast() → automatically publishes to broker
     *   - Can be called from HTTP, CLI, jobs, events, anywhere!
     * - Consuming: Run CLI command (e.g., php console realtime:kafka:consume)
     *   - Only in CLI commands (long-lived processes)
     *
     * @param string|null $name Broker name (null = default)
     * @return BrokerInterface|null
     */
    public function broker(?string $name = null): ?BrokerInterface
    {
        if (!$this->defaultBroker && !$name) {
            return null; // No broker configured
        }

        $name = $name ?? $this->defaultBroker;

        // Return cached instance
        if (isset($this->brokers[$name])) {
            return $this->brokers[$name];
        }

        // Create new broker
        $this->brokers[$name] = $this->createBroker($name);

        return $this->brokers[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getUserConnections(string|int $userId): array
    {
        return array_filter(
            $this->connections,
            fn($conn) => $conn->getUserId() === $userId
        );
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(string $connectionId): void
    {
        $connection = $this->connections[$connectionId] ?? null;

        if (!$connection) {
            return;
        }

        // Unsubscribe from all channels
        foreach ($connection->getChannels() as $channelName) {
            $channel = $this->channels[$channelName] ?? null;
            if ($channel) {
                $channel->unsubscribe($connection);
            }
        }

        // Close connection
        $transport = $this->transport();
        $transport->close($connection);

        // Clear connection state to prevent memory leaks
        $connection->clear();

        // Remove from registry
        unset($this->connections[$connectionId]);
    }

    /**
     * Register a connection.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function registerConnection(ConnectionInterface $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    /**
     * Create transport instance.
     *
     * @param string $name Transport name
     * @return TransportInterface
     */
    private function createTransport(string $name): TransportInterface
    {
        $config = $this->config['transports'][$name] ?? [];
        $driver = $config['driver'] ?? $name;

        return match ($driver) {
            'memory' => new Transports\MemoryTransport($this),
            'websocket' => new Transports\WebSocketTransport($config, $this),
            'socketio' => new Transports\SocketIOGateway($config, $this),
            default => throw new \InvalidArgumentException(
                "Unsupported transport driver: {$driver}. " .
                    "Supported drivers: memory, websocket, socketio. " .
                    "Note: SSE is not a transport - use Toporia\\Framework\\Http\\Sse\\SseController instead."
            )
        };
    }

    /**
     * Create broker instance.
     *
     * Supports both:
     * - Direct broker name: broker('kafka') → uses brokers['kafka'] config
     * - Driver alias: broker('kafka-improved') → finds broker with driver='kafka-improved'
     *
     * @param string $name Broker name or driver alias
     * @return BrokerInterface
     */
    private function createBroker(string $name): BrokerInterface
    {
        $config = $this->config['brokers'][$name] ?? [];
        $driver = $config['driver'] ?? $name;

        // If config is empty and name looks like a driver alias,
        // try to find the broker that uses this driver
        if (empty($config) && str_contains($name, '-')) {
            $config = $this->findConfigByDriver($name);
            $driver = $name; // Use the requested driver
        }

        // Normalize driver name (backward compatibility)
        $normalizedDriver = str_replace(['-improved', '-hp'], '', $driver);

        return match ($normalizedDriver) {
            'redis' => new Brokers\RedisBroker($config, $this),
            'kafka' => new Brokers\KafkaBroker($config, $this),
            'rabbitmq' => new Brokers\RabbitMqBroker($config, $this),

            default => throw new \InvalidArgumentException(
                "Unsupported broker driver: {$driver}. Supported drivers: redis, kafka, rabbitmq"
            )
        };
    }

    /**
     * Find broker config by driver name.
     *
     * Searches through all broker configs to find one that uses the specified driver.
     *
     * @param string $driverName Driver name to search for
     * @return array<string, mixed> Config array or empty if not found
     */
    private function findConfigByDriver(string $driverName): array
    {
        // Normalize driver name for comparison
        $normalizedName = str_replace(['-improved', '-hp'], '', $driverName);

        foreach ($this->config['brokers'] ?? [] as $brokerConfig) {
            if (isset($brokerConfig['driver'])) {
                $configDriver = str_replace(['-improved', '-hp'], '', $brokerConfig['driver']);
                if ($configDriver === $normalizedName) {
                    return $brokerConfig;
                }
            }
        }

        return [];
    }

    /**
     * Get channel authorizer callback.
     *
     * Returns a callback that executes middleware + channel authorization.
     *
     * @param string $channelName
     * @return callable|null
     */
    private function getChannelAuthorizer(string $channelName): ?callable
    {
        // Try to find channel route definition from routes/channels.php
        $channelDefinition = ChannelRoute::match($channelName);

        if ($channelDefinition === null) {
            // No route defined - check legacy config-based authorizers (backward compatibility)
            return $this->getLegacyAuthorizer($channelName);
        }

        // Return authorizer that executes middleware + callback
        return function (ConnectionInterface $connection) use ($channelDefinition, $channelName) {
            $middleware = $channelDefinition['middleware'] ?? [];
            $callback = $channelDefinition['callback'];
            $params = $channelDefinition['params'] ?? [];

            // Execute middleware pipeline (v2 enhanced or v1 basic)
            if ($this->useEnhancedPipeline) {
                $middlewarePipeline = new Middleware\EnhancedChannelMiddlewarePipeline(
                    $this->container,
                    $this->metrics,
                    true // Enable caching
                );
            } else {
                $middlewarePipeline = new Middleware\ChannelMiddlewarePipeline($this->container);
            }

            return $middlewarePipeline->execute(
                $middleware,
                $connection,
                $channelName,
                function ($conn, $channel) use ($callback, $params) {
                    // Execute final authorization callback with extracted params
                    return (bool) $callback($conn, ...array_values($params));
                }
            );
        };
    }

    /**
     * Get legacy authorizer from config (backward compatibility).
     *
     * @param string $channelName
     * @return callable|null
     */
    private function getLegacyAuthorizer(string $channelName): ?callable
    {
        // Check for pattern-based authorizers in config
        $authorizers = $this->config['authorizers'] ?? [];

        foreach ($authorizers as $pattern => $callback) {
            if ($this->matchesPattern($channelName, $pattern)) {
                return $callback;
            }
        }

        return null;
    }

    /**
     * Check if channel name matches pattern (legacy support).
     *
     * @param string $channelName
     * @param string $pattern
     * @return bool
     */
    private function matchesPattern(string $channelName, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = str_replace(['*', '.'], ['.*', '\\.'], $pattern);
        return (bool) preg_match("/^{$regex}$/", $channelName);
    }

    /**
     * Get all active channels.
     *
     * @return array<ChannelInterface>
     */
    public function getChannels(): array
    {
        return array_values($this->channels);
    }

    /**
     * Add connection to manager.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function addConnection(ConnectionInterface $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    /**
     * Remove connection from manager.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function removeConnection(ConnectionInterface $connection): void
    {
        $connId = $connection->getId();

        // Unsubscribe from all channels
        foreach ($connection->getChannels() as $channelName) {
            if (isset($this->channels[$channelName])) {
                $this->channels[$channelName]->unsubscribe($connection);
            }
        }

        unset($this->connections[$connId]);
    }

    /**
     * Get all active connections.
     *
     * @return array<ConnectionInterface>
     */
    public function getAllConnections(): array
    {
        return array_values($this->connections);
    }

    /**
     * Get connection by ID.
     *
     * @param string $connectionId
     * @return ConnectionInterface|null
     */
    public function getConnection(string $connectionId): ?ConnectionInterface
    {
        return $this->connections[$connectionId] ?? null;
    }

    /**
     * Get total number of active connections.
     *
     * @return int
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get total number of active channels.
     *
     * @return int
     */
    public function getChannelCount(): int
    {
        return count($this->channels);
    }

    /**
     * Get all active connections.
     *
     * @return array<ConnectionInterface>
     */
    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    /**
     * Get multi-layer rate limiter (v2).
     *
     * @return MultiLayerRateLimiter|null
     */
    public function getMultiLayerLimiter(): ?MultiLayerRateLimiter
    {
        return $this->multiLayerLimiter;
    }

    /**
     * Get DDoS protection instance (v2).
     *
     * @return DDoSProtection|null
     */
    public function getDDoSProtection(): ?DDoSProtection
    {
        return $this->ddosProtection;
    }

    /**
     * Get middleware metrics (v2).
     *
     * @return MiddlewareMetrics|null
     */
    public function getMetrics(): ?MiddlewareMetrics
    {
        return $this->metrics;
    }

    /**
     * Check if using enhanced pipeline (v2).
     *
     * @return bool
     */
    public function isUsingEnhancedPipeline(): bool
    {
        return $this->useEnhancedPipeline;
    }
}
