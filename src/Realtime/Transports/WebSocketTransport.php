<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Transports;

use Toporia\Framework\Realtime\Contracts\{TransportInterface, ConnectionInterface, MessageInterface, RealtimeManagerInterface};
use Toporia\Framework\Realtime\{Connection, Message, ChannelRoute};
use Toporia\Framework\Realtime\Auth\BroadcastAuthenticator;
use Toporia\Framework\Realtime\Subscriptions\BrokerSubscriptionFactory;

/**
 * Class WebSocketTransport
 *
 * Production-grade WebSocket server using Swoole extension.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Transports
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class WebSocketTransport implements TransportInterface
{
    private ?\Swoole\WebSocket\Server $server = null;
    private array $connections = [];
    private bool $running = false;
    private int $workerNum = 1;
    private ?BroadcastAuthenticator $broadcastAuthenticator = null;

    /**
     * @param array $config Configuration
     * @param RealtimeManagerInterface $manager Realtime manager
     */
    public function __construct(
        private readonly array $config,
        private readonly RealtimeManagerInterface $manager
    ) {
        // Initialize broadcast authenticator for channel authorization
        try {
            $this->broadcastAuthenticator = new BroadcastAuthenticator();
        } catch (\Throwable $e) {
            // Authenticator not available (missing config)
            error_log("[WebSocket] Broadcast auth not configured: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(ConnectionInterface $connection, MessageInterface $message): void
    {
        if (!$this->server) {
            throw new \RuntimeException('WebSocket server not started');
        }

        $fd = (int) $connection->getResource();

        if (!$this->server->isEstablished($fd)) {
            return; // Connection closed
        }

        // Zero-copy send (Swoole optimized)
        $this->server->push($fd, $message->toJson(), WEBSOCKET_OPCODE_TEXT);

        $connection->updateLastActivity();
    }

    /**
     * {@inheritdoc}
     */
    public function broadcast(MessageInterface $message): void
    {
        if (!$this->server) {
            throw new \RuntimeException('WebSocket server not started');
        }

        $json = $message->toJson(); // Serialize once

        // Broadcast to all connections (O(N) but optimized by Swoole)
        foreach ($this->server->connections as $fd) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $json, WEBSOCKET_OPCODE_TEXT);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function broadcastToChannel(string $channel, MessageInterface $message): void
    {
        $channelObj = $this->manager->channel($channel);
        $channelObj->broadcast($message);
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $host, int $port): void
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException(
                'Swoole extension is required for WebSocket transport. ' .
                    'Install: pecl install swoole'
            );
        }

        $this->server = new \Swoole\WebSocket\Server($host, $port);

        // Calculate worker count
        // For WebSocket: use CPU count (not *2) for optimal I/O performance
        // 0 means auto-detect, which Swoole will use swoole_cpu_num()
        $configWorkerNum = $this->config['worker_num'] ?? 0;
        $workerNum = $configWorkerNum > 0 ? $configWorkerNum : swoole_cpu_num();

        // Performance optimization settings (OPTIMIZED for production)
        $this->server->set([
            'worker_num' => $workerNum,
            'max_request' => 0,                        // No worker restart limit (long-lived connections)
            'max_conn' => $this->config['max_connections'] ?? 50000,
            'heartbeat_check_interval' => 30,          // Check every 30s
            'heartbeat_idle_time' => 120,              // Close idle after 2min
            'package_max_length' => 256 * 1024,        // 256KB max message size
            'buffer_output_size' => 32 * 1024 * 1024,  // 32MB output buffer
            'open_tcp_nodelay' => true,                // Disable Nagle for low latency
            'open_http2_protocol' => false,            // WebSocket only
            'enable_coroutine' => true,                // Enable coroutines for async I/O
            'max_coroutine' => 100000,                 // High concurrency support
            'socket_buffer_size' => 8 * 1024 * 1024,   // 8MB socket buffer
            'send_yield' => true,                      // Yield when send buffer is full (prevents blocking)
            'dispatch_mode' => 2,                      // Fixed mode - same connection always goes to same worker
            'websocket_compression' => true,           // Enable compression (requires Swoole compiled with zlib)
        ]);

        // Store worker count for use in handleRedisMessage
        $this->workerNum = $workerNum;

        echo "Workers: {$workerNum}, Max connections: " . ($this->config['max_connections'] ?? 50000) . "\n";

        // SSL/TLS support
        if ($this->config['ssl'] ?? false) {
            $this->server->set([
                'ssl_cert_file' => $this->config['cert'],
                'ssl_key_file' => $this->config['key'],
            ]);
        }

        $this->registerEventHandlers();

        echo "WebSocket server starting on {$host}:{$port}...\n";
        $this->running = true;
        $this->server->start();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        if ($this->server && $this->running) {
            $this->server->shutdown();
            $this->running = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * {@inheritdoc}
     */
    public function hasConnection(string $connectionId): bool
    {
        return isset($this->connections[$connectionId]);
    }

    /**
     * {@inheritdoc}
     */
    public function close(ConnectionInterface $connection, int $code = 1000, string $reason = ''): void
    {
        if (!$this->server) {
            return;
        }

        $fd = (int) $connection->getResource();

        if ($this->server->isEstablished($fd)) {
            $this->server->close($fd, $code, $reason);
        }

        unset($this->connections[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'websocket';
    }

    /**
     * Register Swoole event handlers.
     *
     * @return void
     */
    private function registerEventHandlers(): void
    {
        // Connection opened
        $this->server->on('open', function ($server, $request) {
            $ipAddress = $request->server['remote_addr'] ?? 'unknown';

            // 0. DDoS Protection Check (v2 - if enabled)
            $ddosProtection = $this->manager->getDDoSProtection();
            if ($ddosProtection !== null) {
                if (!$ddosProtection->isAllowed($ipAddress)) {
                    $server->close($request->fd, 4429, 'Too many connections - DDoS protection');
                    error_log("[{$request->fd}] Blocked by DDoS protection: IP {$ipAddress}");
                    return;
                }

                // Record this connection
                $ddosProtection->recordConnection($ipAddress);
            }

            // 1. Try to authenticate from handshake (query string token or header)
            $authData = $this->authenticateFromHandshake($request);

            // 2. Check if authentication is required on connect
            $requireAuth = $this->config['require_auth'] ?? false;

            if ($requireAuth && $authData === null) {
                // Reject unauthenticated connections if required
                $server->close($request->fd, 4401, 'Authentication required');
                error_log("[{$request->fd}] Rejected: Authentication required");
                return;
            }

            // 3. Create connection with authentication data
            $connection = new Connection($request->fd, [
                'ip_address' => $ipAddress,
                'remote_address' => $ipAddress, // Alias for compatibility
                'user_agent' => $request->header['user-agent'] ?? null,
                'user_id' => $authData['user_id'] ?? null,
                'username' => $authData['username'] ?? null,
                'email' => $authData['email'] ?? null,
                'roles' => $authData['roles'] ?? [],
                'auth_guard' => $authData['guard'] ?? null,
                'authenticated_at' => $authData['authenticated_at'] ?? null,
            ]);

            $this->connections[$request->fd] = $connection;
            $this->manager->addConnection($connection);

            $workerId = $server->worker_id;
            if ($authData) {
                echo "[Worker #{$workerId}][{$request->fd}] Connected: user_id={$authData['user_id']} IP={$ipAddress}\n";
            } else {
                echo "[Worker #{$workerId}][{$request->fd}] Connected: anonymous (IP: {$ipAddress})\n";
            }
        });

        // Message received
        $this->server->on('message', function ($server, $frame) {
            if (!isset($this->connections[$frame->fd])) {
                return;
            }

            $connection = $this->connections[$frame->fd];

            // Multi-layer rate limiting (v2 - if enabled)
            $multiLayerLimiter = $this->manager->getMultiLayerLimiter();
            if ($multiLayerLimiter !== null) {
                try {
                    $multiLayerLimiter->check($connection, null, 1);
                } catch (\Toporia\Framework\Realtime\Exceptions\RateLimitException $e) {
                    // Send rate limit error to client
                    $errorMsg = Message::event(null, 'error', [
                        'code' => 'rate_limit_exceeded',
                        'message' => 'Rate limit exceeded',
                        'retry_after' => $e->getRetryAfter(),
                    ]);
                    $server->push($frame->fd, $errorMsg->toJson());
                    return;
                }
            }

            try {
                $message = Message::fromJson($frame->data);
                $this->handleMessage($connection, $message);
            } catch (\Throwable $e) {
                $error = Message::error("Invalid message: {$e->getMessage()}", 400);
                $this->send($connection, $error);
            }
        });

        // Connection closed
        $this->server->on('close', function ($server, $fd) {
            if (isset($this->connections[$fd])) {
                $connection = $this->connections[$fd];
                $this->manager->removeConnection($connection);

                // Clear connection state to prevent memory leaks
                $connection->clear();

                unset($this->connections[$fd]);

                echo "[{$fd}] Disconnected\n";
            }
        });

        // Worker started (coroutine context)
        $this->server->on('workerStart', function ($server, $workerId) {
            echo "Worker #{$workerId} started\n";

            // Start broker subscription in coroutine (only in worker 0 to avoid duplicate messages)
            if ($workerId === 0) {
                $this->startBrokerSubscription($server);
            }
        });

        // Inter-worker communication for multi-worker broadcast
        $this->server->on('pipeMessage', function ($server, $srcWorkerId, $message) {
            $workerId = $server->worker_id;
            echo "[Worker #{$workerId}] Received pipeMessage from Worker #{$srcWorkerId}\n";

            // Received message from Worker #0 (broker subscription handler)
            $decoded = json_decode($message, true);

            if ($decoded && ($decoded['type'] ?? '') === 'channel_broadcast') {
                $channelName = $decoded['channel'] ?? '';
                $event = $decoded['event'] ?? 'message';
                $data = $decoded['data'] ?? [];

                echo "[Worker #{$workerId}] Broadcasting to channel: {$channelName}, has " . count($this->connections) . " connections\n";

                if ($channelName) {
                    // Broadcast directly to subscribed connections in THIS worker
                    $msg = Message::event($channelName, $event, $data);
                    $json = $msg->toJson();

                    $sentCount = 0;
                    foreach ($this->connections as $connection) {
                        $subscribed = $connection->isSubscribed($channelName);
                        echo "[Worker #{$workerId}] Connection {$connection->getId()} subscribed to {$channelName}: " . ($subscribed ? 'yes' : 'no') . "\n";
                        if ($subscribed) {
                            $fd = (int) $connection->getResource();
                            if ($server->isEstablished($fd)) {
                                $server->push($fd, $json, WEBSOCKET_OPCODE_TEXT);
                                $sentCount++;
                            }
                        }
                    }
                    echo "[Worker #{$workerId}] Sent to {$sentCount} connections\n";
                }
            }
        });
    }

    /**
     * Start broker subscription based on configuration.
     *
     * Uses Strategy/Factory pattern for extensibility.
     * Automatically detects which broker is configured and starts the appropriate subscription.
     *
     * @param \Swoole\WebSocket\Server $server
     * @return void
     */
    private function startBrokerSubscription(\Swoole\WebSocket\Server $server): void
    {
        $brokerName = config('realtime.default_broker') ?: env('REALTIME_BROKER');

        if (!$brokerName) {
            echo "Broker: none (single server mode)\n";
            return;
        }

        echo "Broker: {$brokerName}\n";

        // Create factory with broker configs
        $factory = BrokerSubscriptionFactory::createWithDefaults([
            'redis' => config('realtime.brokers.redis', []),
            'rabbitmq' => config('realtime.brokers.rabbitmq', []),
            'kafka' => config('realtime.brokers.kafka', []),
        ]);

        // Get strategy for configured broker
        $strategy = $factory->create($brokerName);

        if ($strategy === null) {
            $available = implode(', ', $factory->getAvailableStrategies());
            echo "Broker '{$brokerName}' not supported for WebSocket transport (available: {$available})\n";
            return;
        }

        // Message handler callback - broadcasts to channel subscribers in all workers
        $messageHandler = function (string $channelName, string $event, array $data) use ($server): void {
            $message = Message::event($channelName, $event, $data);
            $json = $message->toJson();

            echo "[Broker] Received message for channel: {$channelName}, event: {$event}\n";
            echo "[Broker] Worker #0 has " . count($this->connections) . " connections\n";

            // Broadcast to subscribers in Worker #0 (current worker)
            $sentCount = 0;
            foreach ($this->connections as $connection) {
                $subscribed = $connection->isSubscribed($channelName);
                echo "[Broker] Connection {$connection->getId()} subscribed to {$channelName}: " . ($subscribed ? 'yes' : 'no') . "\n";
                if ($subscribed) {
                    $fd = (int) $connection->getResource();
                    if ($server->isEstablished($fd)) {
                        $server->push($fd, $json, WEBSOCKET_OPCODE_TEXT);
                        $sentCount++;
                    }
                }
            }
            echo "[Broker] Sent to {$sentCount} connections in Worker #0\n";

            // For multi-worker setup, send to other workers via pipe
            if ($this->workerNum > 1) {
                echo "[Broker] Sending to " . ($this->workerNum - 1) . " other workers via pipe\n";
                $pipeMessage = json_encode([
                    'type' => 'channel_broadcast',
                    'channel' => $channelName,
                    'event' => $event,
                    'data' => $data,
                ]);

                for ($workerId = 1; $workerId < $this->workerNum; $workerId++) {
                    $server->sendMessage($pipeMessage, $workerId);
                }
            }
        };

        // Is running callback
        $isRunning = fn(): bool => $this->running;

        // Start subscription using strategy
        $strategy->subscribe($server, $messageHandler, $isRunning);
    }

    /**
     * Handle incoming message from client.
     *
     * @param ConnectionInterface $connection
     * @param MessageInterface $message
     * @return void
     */
    private function handleMessage(ConnectionInterface $connection, MessageInterface $message): void
    {
        $type = $message->getType();

        // Check if authentication is required for operations (except 'auth' and 'ping')
        if (!in_array($type, ['auth', 'ping'], true)) {
            // Check config - prefer explicit false from env
            $envValue = env('REALTIME_REQUIRE_AUTH_SUBSCRIBE');
            $requireAuthForSubscribe = $envValue === false || $envValue === 'false'
                ? false
                : ($this->config['require_auth_for_subscribe'] ?? true);

            if ($requireAuthForSubscribe && $connection->getUserId() === null) {
                $this->send($connection, Message::error('Authentication required. Please authenticate first.', 401));
                return;
            }
        }

        match ($type) {
            'auth' => $this->handleAuth($connection, $message),
            'subscribe' => $this->handleSubscribe($connection, $message),
            'unsubscribe' => $this->handleUnsubscribe($connection, $message),
            'event' => $this->handleEvent($connection, $message),
            'ping' => $this->handlePing($connection),
            default => $this->send($connection, Message::error("Unknown message type: {$message->getType()}", 400))
        };
    }

    /**
     * Handle subscribe request.
     *
     * Supports two modes:
     * 1. Public channel: { "type": "subscribe", "channel": "news" }
     * 2. Private/Presence with auth: { "type": "subscribe", "channel": "private-orders.123", "data": { "auth": "app_key:signature" } }
     *
     * @param ConnectionInterface $connection
     * @param MessageInterface $message
     * @return void
     */
    private function handleSubscribe(ConnectionInterface $connection, MessageInterface $message): void
    {
        $channelName = $message->getChannel();

        if (!$channelName) {
            $this->send($connection, Message::error('Channel name required', 400));
            return;
        }

        // Determine channel type
        $channelType = $this->getChannelType($channelName);

        // For private/presence channels, verify authentication
        if ($channelType !== 'public') {
            $messageData = $message->getData();
            $authorized = $this->authorizeSubscription($connection, $channelName, $messageData);

            if (!$authorized) {
                $this->send($connection, Message::error("Unauthorized for channel: {$channelName}", 403));
                return;
            }
        }

        $channel = $this->manager->channel($channelName);
        $channel->subscribe($connection);

        // Log subscription for debugging
        if ($this->server) {
            $workerId = $this->server->worker_id;
            echo "[Worker #{$workerId}] Connection {$connection->getId()} subscribed to channel: {$channelName}\n";
            echo "[Worker #{$workerId}] Connection channels: " . implode(', ', $connection->getChannels()) . "\n";
        }

        // Send success response
        $this->send($connection, Message::event($channelName, 'subscribed', [
            'channel' => $channelName,
            'subscribers' => $channel->getSubscriberCount()
        ]));

        // Broadcast presence join for presence channels
        if ($channel->isPresence() || str_starts_with($channelName, 'presence-')) {
            $channel->broadcast(Message::event($channelName, 'presence.join', [
                'user_id' => $connection->getUserId(),
                'user_info' => $connection->get('presence_user_info', $connection->get('user_info', []))
            ]), $connection);
        }
    }

    /**
     * Authorize subscription to private/presence channel.
     *
     * Supports two methods:
     * 1. Auth signature from /broadcasting/auth endpoint
     * 2. Direct auth if connection is already authenticated (uses routes/channels.php)
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @param array|null $data Subscribe request data
     * @return bool
     */
    private function authorizeSubscription(ConnectionInterface $connection, string $channelName, ?array $data): bool
    {
        $authSignature = $data['auth'] ?? null;
        $channelData = $data['channel_data'] ?? null;

        // Method 1: Verify auth signature (Pusher-style)
        if ($authSignature !== null && $this->broadcastAuthenticator !== null) {
            // Generate a socket_id for this connection if not set
            $socketId = $connection->get('socket_id') ?? $connection->getId();

            $isValid = $this->broadcastAuthenticator->verifySignature(
                $socketId,
                $channelName,
                $authSignature,
                $channelData
            );

            if ($isValid) {
                // For presence channels, extract user data from channel_data
                if ($channelData !== null && str_starts_with($channelName, 'presence-')) {
                    $parsedData = json_decode($channelData, true);
                    if (is_array($parsedData)) {
                        $connection->setUserId($parsedData['user_id'] ?? null);
                        $connection->set('presence_user_info', $parsedData['user_info'] ?? []);
                    }
                }
                return true;
            }

            echo "[{$connection->getId()}] Invalid auth signature for channel '{$channelName}'\n";
            return false;
        }

        // Method 2: Direct authorization (connection already authenticated)
        if ($connection->getUserId() !== null) {
            // Normalize channel name for route matching
            $normalizedChannel = $this->normalizeChannelName($channelName);

            // Find channel definition in routes/channels.php
            $channelDef = ChannelRoute::match($normalizedChannel);

            if ($channelDef === null) {
                // No channel definition - deny private channels by default
                echo "[{$connection->getId()}] No channel definition for '{$normalizedChannel}'\n";
                return false;
            }

            // Check if connection's guard is allowed for this channel
            $connectionGuard = $connection->get('auth_guard');
            if (!ChannelRoute::isGuardAllowed($channelDef, $connectionGuard)) {
                echo "[{$connection->getId()}] Guard '{$connectionGuard}' not allowed for channel '{$channelName}'\n";
                return false;
            }

            // Build user array for callback
            $user = [
                'id' => $connection->getUserId(),
                'user_id' => $connection->getUserId(),
                'username' => $connection->get('username'),
                'email' => $connection->get('email'),
                'roles' => $connection->get('roles', []),
                'guard' => $connectionGuard,
            ];

            // Execute authorization callback
            try {
                $params = array_values($channelDef['params'] ?? []);
                $callback = $channelDef['callback'];

                // Check if callback accepts guard parameter (reflection)
                $reflection = new \ReflectionFunction($callback);
                $paramCount = $reflection->getNumberOfParameters();

                // If callback has more parameters than user + channel params, pass guard
                if ($paramCount > count($params) + 1 && $connectionGuard !== null) {
                    // Append guard to params array before unpacking
                    $paramsWithGuard = array_merge($params, [$connectionGuard]);
                    $result = $callback($user, ...$paramsWithGuard);
                } else {
                    $result = $callback($user, ...$params);
                }

                if ($result === false || $result === null) {
                    echo "[{$connection->getId()}] Authorization denied for channel '{$channelName}'\n";
                    return false;
                }

                // For presence channels, store user info from callback result
                if (is_array($result) && str_starts_with($channelName, 'presence-')) {
                    $connection->set('presence_user_info', $result);
                }

                return true;
            } catch (\Throwable $e) {
                error_log("[WebSocket] Authorization callback error: {$e->getMessage()}");
                return false;
            }
        }

        // Not authenticated and no auth signature
        echo "[{$connection->getId()}] Not authenticated for private channel '{$channelName}'\n";
        return false;
    }

    /**
     * Get channel type from channel name.
     *
     * @param string $channelName
     * @return string 'public', 'private', or 'presence'
     */
    private function getChannelType(string $channelName): string
    {
        if (str_starts_with($channelName, 'private-')) {
            return 'private';
        }
        if (str_starts_with($channelName, 'presence-')) {
            return 'presence';
        }
        return 'public';
    }

    /**
     * Normalize channel name by removing prefix.
     *
     * @param string $channelName
     * @return string
     */
    private function normalizeChannelName(string $channelName): string
    {
        if (str_starts_with($channelName, 'private-')) {
            return substr($channelName, 8);
        }
        if (str_starts_with($channelName, 'presence-')) {
            return substr($channelName, 9);
        }
        return $channelName;
    }

    /**
     * Handle unsubscribe request.
     *
     * @param ConnectionInterface $connection
     * @param MessageInterface $message
     * @return void
     */
    private function handleUnsubscribe(ConnectionInterface $connection, MessageInterface $message): void
    {
        $channelName = $message->getChannel();

        if (!$channelName) {
            return;
        }

        $channel = $this->manager->channel($channelName);

        // Broadcast presence leave for presence channels
        if ($channel->isPresence() && $channel->hasSubscriber($connection)) {
            $channel->broadcast(Message::event($channelName, 'presence.leave', [
                'user_id' => $connection->getUserId()
            ]), $connection);
        }

        $channel->unsubscribe($connection);

        $this->send($connection, Message::event($channelName, 'unsubscribed', [
            'channel' => $channelName
        ]));
    }

    /**
     * Handle client event (client-to-server message).
     *
     * @param ConnectionInterface $connection
     * @param MessageInterface $message
     * @return void
     */
    private function handleEvent(ConnectionInterface $connection, MessageInterface $message): void
    {
        $channelName = $message->getChannel();

        if (!$channelName) {
            $this->send($connection, Message::error('Channel required for events', 400));
            return;
        }

        // Verify connection is subscribed to channel
        if (!$connection->isSubscribed($channelName)) {
            $this->send($connection, Message::error("Not subscribed to channel: {$channelName}", 403));
            return;
        }

        // Broadcast to channel (excluding sender)
        $channel = $this->manager->channel($channelName);
        $channel->broadcast($message, $connection);
    }

    /**
     * Handle authentication request (two-step auth).
     *
     * Allows clients to authenticate after connection.
     * Supports guard-based authentication like SocketIOGateway.
     *
     * Client sends:
     *   // JWT auth
     *   { "type": "auth", "data": { "token": "jwt_token" } }
     *   { "type": "auth", "data": { "token": "jwt_token", "guard": "api" } }
     *   { "type": "auth", "data": { "token": "jwt_token", "guard": "admin" } }
     *
     *   // Session auth
     *   { "type": "auth", "data": { "session_id": "session_id" } }
     *   { "type": "auth", "data": { "session_id": "session_id", "guard": "web" } }
     *
     * @param ConnectionInterface $connection
     * @param MessageInterface $message
     * @return void
     */
    private function handleAuth(ConnectionInterface $connection, MessageInterface $message): void
    {
        // Check if already authenticated
        if ($connection->getUserId() !== null) {
            $this->send($connection, Message::error('Already authenticated', 400));
            return;
        }

        // Get token/session and guard from message data
        $data = $message->getData();
        $token = $data['token'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $guardName = $data['guard'] ?? 'web';

        $authData = null;

        // Method 1: JWT token auth
        if (!empty($token)) {
            $guardName = $data['guard'] ?? 'api';
            $authData = $this->verifyTokenWithGuard($token, $guardName);
            if ($authData !== null) {
                $authData['auth_method'] = 'jwt';
            }
        }
        // Method 2: Session auth
        elseif (!empty($sessionId)) {
            $authData = $this->verifySessionAuth($sessionId, $guardName);
        }
        else {
            $this->send($connection, Message::error('Token or session_id required', 400));
            return;
        }

        if ($authData === null || ($authData['user_id'] ?? null) === null) {
            $this->send($connection, Message::error('Invalid credentials', 401));
            return;
        }

        $authMethod = $authData['auth_method'] ?? 'jwt';

        // Update connection with authentication data
        $connection->set('user_id', $authData['user_id']);
        $connection->set('username', $authData['username'] ?? $authData['name'] ?? null);
        $connection->set('email', $authData['email'] ?? null);
        $connection->set('roles', $authData['roles'] ?? []);
        $connection->set('auth_guard', $guardName);
        $connection->set('auth_method', $authMethod);
        $connection->set('authenticated_at', $authData['authenticated_at'] ?? time());

        // Send success response
        $this->send($connection, Message::create('auth_success', null, 'Authenticated successfully', [
            'user_id' => $authData['user_id'],
            'username' => $authData['username'] ?? $authData['name'] ?? null,
            'roles' => $authData['roles'] ?? [],
            'guard' => $guardName,
            'method' => $authMethod,
        ]));

        echo "[{$connection->getId()}] Authenticated: user_id={$authData['user_id']} (guard: {$guardName}, method: {$authMethod})\n";
    }

    /**
     * Verify session-based authentication.
     *
     * Reads session directly from storage/sessions (Toporia's session driver).
     *
     * @param string $sessionId Session ID
     * @param string $guardName Guard name (web, admin, default, etc.)
     * @return array|null User data or null if invalid
     */
    private function verifySessionAuth(string $sessionId, string $guardName): ?array
    {
        // Validate session ID format (strict alphanumeric only - prevent injection)
        if (!preg_match('/^[a-zA-Z0-9]{22,256}$/', $sessionId)) {
            echo "[Session Auth] Invalid session ID format\n";
            return null;
        }

        try {
            // Find session file
            $sessionFile = $this->findSessionFile($sessionId);

            if ($sessionFile === null) {
                return null;
            }

            // Read session data with size limit (prevent memory exhaustion)
            $maxSize = 1024 * 1024; // 1MB max session file
            $fileSize = @filesize($sessionFile);
            if ($fileSize === false || $fileSize > $maxSize) {
                echo "[Session Auth] Session file too large or unreadable\n";
                return null;
            }

            $sessionData = @file_get_contents($sessionFile, false, null, 0, $maxSize);
            if ($sessionData === false || empty($sessionData)) {
                echo "[Session Auth] Failed to read session file\n";
                return null;
            }

            // Parse session data (Toporia format)
            $userData = $this->parseSessionData($sessionData, $guardName);

            if ($userData !== null) {
                $userData['auth_method'] = 'session';
            }

            return $userData;
        } catch (\Throwable $e) {
            error_log("[Session Auth] Error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get possible session storage paths.
     *
     * Priority: Toporia storage/sessions first (framework's session driver)
     *
     * @return array<string>
     */
    private function getSessionPaths(): array
    {
        $paths = [];

        // 1. Toporia custom path from config (highest priority)
        $configPath = config('session.stores.file.path', null);
        if ($configPath !== null && is_dir($configPath)) {
            $paths[] = $configPath;
        }

        // 2. Default Toporia session path: storage/sessions
        $basePath = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 5);
        $defaultPath = $basePath . '/storage/sessions';
        if (is_dir($defaultPath) && !in_array($defaultPath, $paths)) {
            $paths[] = $defaultPath;
        }

        // 3. PHP native session path (fallback)
        $phpSessionPath = session_save_path();
        if (!empty($phpSessionPath) && is_dir($phpSessionPath) && !in_array($phpSessionPath, $paths)) {
            $paths[] = $phpSessionPath;
        }

        // 4. Common PHP session paths (last resort)
        $commonPaths = ['/var/lib/php/sessions', '/tmp'];
        foreach ($commonPaths as $path) {
            if (is_dir($path) && !in_array($path, $paths)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Find session file across multiple paths.
     *
     * @param string $sessionId Session ID
     * @return string|null Full path to session file or null
     */
    private function findSessionFile(string $sessionId): ?string
    {
        $paths = $this->getSessionPaths();

        foreach ($paths as $path) {
            $file = $path . '/sess_' . $sessionId;
            if (file_exists($file) && is_readable($file)) {
                echo "[Session Auth] Found session file: {$file}\n";
                return $file;
            }
        }

        echo "[Session Auth] Session file not found. Searched paths: " . implode(', ', $paths) . "\n";
        return null;
    }

    /**
     * Parse session data - supports both PHP native and Toporia custom format.
     *
     * @param string $sessionData Raw session file content
     * @param string $guardName Guard name
     * @return array|null User data or null
     */
    private function parseSessionData(string $sessionData, string $guardName): ?array
    {
        // Use guard name directly - SessionGuard uses auth_{guardName} as session key
        // e.g., guard 'web' stores in auth_web, guard 'admin' stores in auth_admin
        $guardToCheck = $guardName;

        // Try Toporia's custom format first
        $session = @unserialize($sessionData, ['allowed_classes' => false]);
        if (is_array($session) && isset($session['data']) && isset($session['expires_at'])) {
            echo "[Session Parse] Detected Toporia custom format\n";

            if ($session['expires_at'] < time()) {
                echo "[Session Parse] Session expired\n";
                return null;
            }

            return $this->extractAuthFromData($session['data'], $guardToCheck, $guardName);
        }

        // Try PHP native session format
        echo "[Session Parse] Trying PHP native format\n";
        $data = $this->parsePhpNativeSession($sessionData);

        if (!empty($data)) {
            echo "[Session Parse] Parsed keys: " . implode(', ', array_keys($data)) . "\n";
            return $this->extractAuthFromData($data, $guardToCheck, $guardName);
        }

        echo "[Session Parse] Failed to parse session data\n";
        return null;
    }

    /**
     * Parse PHP native session format.
     *
     * @param string $sessionData Raw session data
     * @return array Parsed data
     */
    private function parsePhpNativeSession(string $sessionData): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($sessionData);

        while ($offset < $length) {
            $keyEnd = strpos($sessionData, '|', $offset);
            if ($keyEnd === false) {
                break;
            }

            $key = substr($sessionData, $offset, $keyEnd - $offset);
            $offset = $keyEnd + 1;

            $remaining = substr($sessionData, $offset);

            if (str_starts_with($remaining, 'b:0;')) {
                $result[$key] = false;
                $offset += 4;
                continue;
            }

            // Safe unserialize with error handling
            try {
                set_error_handler(function () {
                    throw new \ErrorException('Unserialize failed');
                });

                $value = unserialize($remaining, ['allowed_classes' => false]);

                restore_error_handler();

                if ($value !== false) {
                    $result[$key] = $value;
                    $serialized = serialize($value);
                    $offset += strlen($serialized);
                } else {
                    if (preg_match('/;([a-zA-Z_][a-zA-Z0-9_]*)\|/', $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                        $offset += $matches[0][1] + 1;
                    } else {
                        break;
                    }
                }
            } catch (\Throwable) {
                restore_error_handler();
                // Try to find next key
                if (preg_match('/;([a-zA-Z_][a-zA-Z0-9_]*)\|/', $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                    $offset += $matches[0][1] + 1;
                } else {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Extract auth data from parsed session data.
     *
     * @param array $data Parsed session data
     * @param string $guardToCheck Primary guard to check
     * @param string $originalGuard Original guard name
     * @return array|null User data or null
     */
    private function extractAuthFromData(array $data, string $guardToCheck, string $originalGuard): ?array
    {
        $authKey = "auth_{$guardToCheck}";
        if (isset($data[$authKey]) && !empty($data[$authKey])) {
            echo "[Session Parse] Found auth at {$authKey} = {$data[$authKey]}\n";
            return ['user_id' => $data[$authKey], 'guard' => $guardToCheck];
        }

        if ($guardToCheck !== $originalGuard) {
            $authKey = "auth_{$originalGuard}";
            if (isset($data[$authKey]) && !empty($data[$authKey])) {
                echo "[Session Parse] Found auth at {$authKey} = {$data[$authKey]}\n";
                return ['user_id' => $data[$authKey], 'guard' => $originalGuard];
            }
        }

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'auth_') && !empty($value)) {
                $foundGuard = substr($key, 5);
                echo "[Session Parse] Found auth (fallback) at {$key} = {$value}\n";
                return ['user_id' => $value, 'guard' => $foundGuard];
            }
        }

        echo "[Session Parse] No auth data found\n";
        return null;
    }

    /**
     * Authenticate from WebSocket handshake request.
     *
     * Extracts JWT from query string (?token=xxx&guard=api) or Authorization header.
     *
     * @param \Swoole\Http\Request $request Swoole HTTP request
     * @return array|null User data or null
     */
    private function authenticateFromHandshake(\Swoole\Http\Request $request): ?array
    {
        $token = null;
        $guardName = 'api';

        // Method 1: JWT from query string (?token=xxx&guard=api)
        if (isset($request->get['token'])) {
            $token = $request->get['token'];
            $guardName = $request->get['guard'] ?? 'api';
        }
        // Method 2: JWT from Authorization header
        elseif (isset($request->header['authorization'])) {
            $auth = $request->header['authorization'];
            $token = str_replace('Bearer ', '', $auth);
            // Guard from query string or default
            $guardName = $request->get['guard'] ?? 'api';
        }

        if ($token === null) {
            return null;
        }

        return $this->verifyTokenWithGuard($token, $guardName);
    }

    /**
     * Verify JWT token with guard-specific secret.
     *
     * @param string $token JWT token
     * @param string $guardName Guard name
     * @return array|null User data or null if invalid
     */
    private function verifyTokenWithGuard(string $token, string $guardName): ?array
    {
        return $this->manualJwtVerify($token, $guardName);
    }

    /**
     * Verify JWT token with signature validation.
     *
     * @param string $token JWT token
     * @param string $guardName Guard name for secret lookup
     * @return array|null User data or null
     */
    private function manualJwtVerify(string $token, string $guardName): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

            // Get guard-specific secret
            $secret = $this->getJwtSecretForGuard($guardName);
            if ($secret === null || strlen($secret) < 32) {
                return null;
            }

            // Verify signature
            $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secret, true);
            $expectedSignatureEncoded = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');

            if (!hash_equals($expectedSignatureEncoded, $signatureEncoded)) {
                return null;
            }

            // Decode payload
            $payload = json_decode(base64_decode(strtr($payloadEncoded, '-_', '+/')), true);
            if (!is_array($payload)) {
                return null;
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            return [
                'user_id' => $payload['sub'] ?? null,
                'username' => $payload['username'] ?? $payload['name'] ?? null,
                'email' => $payload['email'] ?? null,
                'roles' => $payload['roles'] ?? [],
                'authenticated_at' => time(),
                'guard' => $guardName,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get JWT secret for a specific guard.
     *
     * @param string $guardName Guard name
     * @return string|null
     */
    private function getJwtSecretForGuard(string $guardName): ?string
    {
        $guardUpper = strtoupper($guardName);

        // 1. Guard-specific env
        $secret = $_ENV["JWT_SECRET_{$guardUpper}"] ?? getenv("JWT_SECRET_{$guardUpper}");
        if (!empty($secret) && is_string($secret)) {
            return $secret;
        }

        // 2. Default secret
        return $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
    }

    /**
     * Handle ping request.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    private function handlePing(ConnectionInterface $connection): void
    {
        $this->send($connection, Message::pong());
        $connection->updateLastActivity();
    }
}
