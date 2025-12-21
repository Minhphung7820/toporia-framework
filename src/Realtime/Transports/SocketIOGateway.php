<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Transports;

use Toporia\Framework\Realtime\Contracts\{TransportInterface, ConnectionInterface, MessageInterface, RealtimeManagerInterface};
use Toporia\Framework\Realtime\{Connection, Message, ChannelRoute};
use Toporia\Framework\Realtime\Subscriptions\BrokerSubscriptionFactory;
use Toporia\Framework\Realtime\Auth\BroadcastAuthenticator;
use App\Infrastructure\Realtime\Middleware\AuthMiddleware;

/**
 * Class SocketIOGateway
 *
 * Socket.IO compatible gateway for real-time bidirectional communication. Implements Engine.IO protocol v4 with Socket.IO namespace support.
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
final class SocketIOGateway implements TransportInterface
{
    private ?\Swoole\WebSocket\Server $server = null;
    private array $connections = [];
    private array $namespaces = [];
    private array $rooms = [];
    private bool $running = false;
    private int $workerNum = 1;
    private ?BroadcastAuthenticator $authenticator = null;

    // Socket.IO packet types
    private const PACKET_CONNECT = 0;
    private const PACKET_DISCONNECT = 1;
    private const PACKET_EVENT = 2;
    private const PACKET_ACK = 3;
    private const PACKET_ERROR = 4;
    private const PACKET_BINARY_EVENT = 5;
    private const PACKET_BINARY_ACK = 6;

    // Engine.IO packet types
    private const EIO_OPEN = '0';
    private const EIO_CLOSE = '1';
    private const EIO_PING = '2';
    private const EIO_PONG = '3';
    private const EIO_MESSAGE = '4';
    private const EIO_UPGRADE = '5';
    private const EIO_NOOP = '6';

    /**
     * @param array $config Configuration
     * @param RealtimeManagerInterface $manager Realtime manager
     */
    public function __construct(
        private readonly array $config,
        private readonly RealtimeManagerInterface $manager
    ) {
        // Initialize broadcast authenticator for signature verification
        try {
            $this->authenticator = new BroadcastAuthenticator();
        } catch (\Throwable $e) {
            // Authenticator not available (missing config)
            error_log("[SocketIO] Broadcast auth not configured: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(ConnectionInterface $connection, MessageInterface $message): void
    {
        if (!$this->server) {
            throw new \RuntimeException('Socket.IO gateway not started');
        }

        $fd = (int) $connection->getResource();

        if (!$this->server->isEstablished($fd)) {
            return;
        }

        // Convert to Socket.IO packet
        $namespace = $connection->get('namespace', '/');
        $packet = $this->createSocketIOPacket(
            type: self::PACKET_EVENT,
            namespace: $namespace,
            data: [$message->getEvent(), $message->getData()]
        );

        // Wrap in Engine.IO message packet
        $eioPacket = self::EIO_MESSAGE . $packet;

        $this->server->push($fd, $eioPacket, WEBSOCKET_OPCODE_TEXT);
        $connection->updateLastActivity();
    }

    /**
     * {@inheritdoc}
     */
    public function broadcast(MessageInterface $message): void
    {
        if (!$this->server) {
            throw new \RuntimeException('Socket.IO gateway not started');
        }

        // Broadcast to all connections in default namespace
        $this->broadcastToNamespace('/', $message);
    }

    /**
     * {@inheritdoc}
     */
    public function broadcastToChannel(string $channel, MessageInterface $message): void
    {
        // In Socket.IO, channels are called "rooms"
        $this->broadcastToRoom($channel, $message);
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $host, int $port): void
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException(
                'Swoole extension is required for Socket.IO gateway. ' .
                    'Install: pecl install swoole'
            );
        }

        $this->server = new \Swoole\WebSocket\Server($host, $port);

        // Calculate worker count
        $configWorkerNum = $this->config['worker_num'] ?? 0;
        $workerNum = $configWorkerNum > 0 ? $configWorkerNum : swoole_cpu_num();
        $this->workerNum = $workerNum;

        // Performance optimization
        $this->server->set([
            'worker_num' => $workerNum,
            'max_request' => 0,
            'max_conn' => $this->config['max_connections'] ?? 50000,
            'heartbeat_check_interval' => 25,  // Socket.IO ping interval
            'heartbeat_idle_time' => 60,        // 60s timeout
            'package_max_length' => 4 * 1024 * 1024, // 4MB
            'buffer_output_size' => 32 * 1024 * 1024,  // 32MB output buffer
            'open_tcp_nodelay' => true,
            'enable_coroutine' => true,
            'max_coroutine' => 100000,
            'socket_buffer_size' => 8 * 1024 * 1024,   // 8MB socket buffer
            'send_yield' => true,
            'dispatch_mode' => 2,  // Fixed mode - same connection always goes to same worker
        ]);

        echo "Workers: {$workerNum}, Max connections: " . ($this->config['max_connections'] ?? 50000) . "\n";

        // SSL support
        if ($this->config['ssl'] ?? false) {
            $this->server->set([
                'ssl_cert_file' => $this->config['cert'],
                'ssl_key_file' => $this->config['key'],
            ]);
        }

        $this->registerEventHandlers();

        echo "Socket.IO Gateway starting on {$host}:{$port}...\n";
        echo "Compatible with Socket.IO v4 clients\n";
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
            // Use disconnect() for WebSocket close with code and reason
            $this->server->disconnect($fd, $code, $reason);
        }

        unset($this->connections[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'socketio';
    }

    /**
     * Register Swoole event handlers.
     *
     * @return void
     */
    private function registerEventHandlers(): void
    {
        // WebSocket connection opened
        $this->server->on('open', function ($server, $request) {
            // Send Engine.IO handshake
            $handshake = [
                'sid' => uniqid('', true),
                'upgrades' => ['websocket'],
                'pingInterval' => 25000,
                'pingTimeout' => 60000,
                'maxPayload' => 1000000
            ];

            $openPacket = self::EIO_OPEN . json_encode($handshake);
            $server->push($request->fd, $openPacket);

            // Create connection
            $connection = new Connection($request->fd, [
                'ip' => $request->server['remote_addr'] ?? null,
                'user_agent' => $request->header['user-agent'] ?? null,
                'namespace' => '/', // Default namespace
                'sid' => $handshake['sid'],
            ]);

            $this->connections[$request->fd] = $connection;
            $this->manager->addConnection($connection);

            echo "[{$request->fd}] Socket.IO client connected\n";
        });

        // WebSocket message received
        $this->server->on('message', function ($server, $frame) {
            if (!isset($this->connections[$frame->fd])) {
                return;
            }

            $connection = $this->connections[$frame->fd];

            try {
                $this->handleEngineIOPacket($connection, $frame->data);
            } catch (\Throwable $e) {
                error_log("Socket.IO error: {$e->getMessage()}");
                $this->sendError($connection, $e->getMessage());
            }
        });

        // Connection closed
        $this->server->on('close', function ($server, $fd) {
            if (isset($this->connections[$fd])) {
                $connection = $this->connections[$fd];

                // Remove from all rooms
                $this->removeFromAllRooms($connection);

                $this->manager->removeConnection($connection);

                // Clear connection state to prevent memory leaks
                $connection->clear();

                unset($this->connections[$fd]);

                echo "[{$fd}] Socket.IO client disconnected\n";
            }
        });

        // Worker started
        $this->server->on('workerStart', function ($server, $workerId) {
            echo "Socket.IO Worker #{$workerId} started\n";

            // Start broker subscription in Worker #0 only to avoid duplicate messages
            if ($workerId === 0) {
                $this->startBrokerSubscription($server);
            }
        });

        // Inter-worker communication for multi-worker broadcast
        $this->server->on('pipeMessage', function ($server, $_srcWorkerId, $message) {
            // Received broadcast message from Worker #0 (broker subscription handler)
            // Broadcast to all connections in THIS worker using Socket.IO format
            foreach ($server->connections as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, $message, WEBSOCKET_OPCODE_TEXT);
                }
            }
        });
    }

    /**
     * Start broker subscription based on configuration.
     *
     * Uses Strategy/Factory pattern for extensibility.
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
            echo "Broker '{$brokerName}' not supported for Socket.IO gateway (available: {$available})\n";
            return;
        }

        // Message handler callback - broadcasts to all workers using Socket.IO format
        $messageHandler = function (string $channelName, string $event, array $data) use ($server): void {
            // Create Socket.IO formatted packet
            $packet = $this->createSocketIOPacket(
                type: self::PACKET_EVENT,
                namespace: '/',
                data: [$event, array_merge($data, ['channel' => $channelName])]
            );
            $eioPacket = self::EIO_MESSAGE . $packet;

            // Broadcast to connections in Worker #0 (current worker)
            foreach ($server->connections as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, $eioPacket, WEBSOCKET_OPCODE_TEXT);
                }
            }

            // Send message to all OTHER workers via pipe for them to broadcast
            for ($workerId = 1; $workerId < $this->workerNum; $workerId++) {
                $server->sendMessage($eioPacket, $workerId);
            }
        };

        // Is running callback
        $isRunning = fn(): bool => $this->running;

        // Start subscription using strategy
        $strategy->subscribe($server, $messageHandler, $isRunning);
    }

    /**
     * Handle Engine.IO packet.
     *
     * @param ConnectionInterface $connection
     * @param string $data Raw packet data
     * @return void
     */
    private function handleEngineIOPacket(ConnectionInterface $connection, string $data): void
    {
        if (empty($data)) {
            return;
        }

        $packetType = $data[0];
        $payload = substr($data, 1);

        match ($packetType) {
            self::EIO_MESSAGE => $this->handleSocketIOPacket($connection, $payload),
            self::EIO_PING => $this->sendPong($connection),
            self::EIO_CLOSE => $this->handleDisconnect($connection),
            default => null
        };
    }

    /**
     * Handle Socket.IO packet.
     *
     * @param ConnectionInterface $connection
     * @param string $payload Socket.IO packet
     * @return void
     */
    private function handleSocketIOPacket(ConnectionInterface $connection, string $payload): void
    {
        // Note: '0' is a valid CONNECT packet, so don't use empty() which returns true for '0'
        if ($payload === '') {
            return;
        }

        // Parse Socket.IO packet: type[namespace,][ackId,]data
        $packet = $this->parseSocketIOPacket($payload);

        match ($packet['type']) {
            self::PACKET_CONNECT => $this->handleConnect($connection, $packet),
            self::PACKET_DISCONNECT => $this->handleDisconnect($connection),
            self::PACKET_EVENT => $this->handleEvent($connection, $packet),
            self::PACKET_ACK => $this->handleAck($connection, $packet),
            default => null
        };
    }

    /**
     * Handle Socket.IO CONNECT packet.
     *
     * @param ConnectionInterface $connection
     * @param array $packet
     * @return void
     */
    private function handleConnect(ConnectionInterface $connection, array $packet): void
    {
        $namespace = $packet['namespace'] ?? '/';

        // Set connection namespace
        $connection->set('namespace', $namespace);

        // Add to namespace
        if (!isset($this->namespaces[$namespace])) {
            $this->namespaces[$namespace] = [];
        }
        $this->namespaces[$namespace][$connection->getId()] = $connection;

        // Send CONNECT acknowledgment
        $ackPacket = $this->createSocketIOPacket(
            type: self::PACKET_CONNECT,
            namespace: $namespace,
            data: ['sid' => $connection->get('sid')]
        );

        $this->sendRaw($connection, self::EIO_MESSAGE . $ackPacket);

        echo "[{$connection->getId()}] Connected to namespace: {$namespace}\n";
    }

    /**
     * Handle Socket.IO EVENT packet.
     *
     * @param ConnectionInterface $connection
     * @param array $packet
     * @return void
     */
    private function handleEvent(ConnectionInterface $connection, array $packet): void
    {
        $data = $packet['data'] ?? [];

        if (empty($data) || !is_array($data)) {
            return;
        }

        $eventName = array_shift($data); // First element is event name
        $eventData = $data[0] ?? null;   // Second element is event data
        $ackId = $packet['ackId'] ?? null;

        // Special Socket.IO events
        match ($eventName) {
            'auth' => $this->handleAuth($connection, $eventData, $ackId),
            'subscribe' => $this->handleSubscribe($connection, $eventData, $ackId),
            'unsubscribe' => $this->handleUnsubscribe($connection, $eventData, $ackId),
            'join' => $this->handleJoinRoom($connection, $eventData, $ackId),
            'leave' => $this->handleLeaveRoom($connection, $eventData, $ackId),
            default => $this->handleCustomEvent($connection, $eventName, $eventData, $ackId)
        };
    }

    /**
     * Handle 'auth' event - authenticate connection with JWT token or session.
     *
     * Client sends:
     *   // JWT auth
     *   socket.emit('auth', { token: 'jwt_token' })
     *   socket.emit('auth', { token: 'jwt_token', guard: 'api' })
     *   socket.emit('auth', { token: 'jwt_token', guard: 'admin' })
     *
     *   // Session auth (HttpOnly cookie-based)
     *   socket.emit('auth', { session_id: 'session_id_from_cookie' })
     *
     * @param ConnectionInterface $connection
     * @param mixed $data { token?: string, session_id?: string, guard?: string }
     * @param string|null $ackId
     * @return void
     */
    private function handleAuth(ConnectionInterface $connection, mixed $data, ?string $ackId): void
    {
        $token = is_array($data) ? ($data['token'] ?? null) : null;
        $sessionId = is_array($data) ? ($data['session_id'] ?? null) : null;
        $guardName = is_array($data) ? ($data['guard'] ?? 'web') : 'web';

        $userData = null;

        // Method 1: JWT token auth
        if (!empty($token)) {
            $guardName = is_array($data) ? ($data['guard'] ?? 'api') : 'api';
            $userData = $this->verifyTokenWithGuard($token, $guardName);
            if ($userData !== null) {
                $userData['auth_method'] = 'jwt';
            }
        }
        // Method 2: Session auth
        elseif (!empty($sessionId)) {
            $userData = $this->verifySessionAuth($sessionId, $guardName);
        } else {
            $this->emitToConnection($connection, 'auth_error', ['message' => 'Token or session_id required']);
            return;
        }

        if ($userData === null || $userData['user_id'] === null) {
            $this->emitToConnection($connection, 'auth_error', ['message' => 'Invalid credentials']);
            return;
        }

        // Set user data on connection
        $connection->setUserId($userData['user_id']);
        $connection->set('auth_guard', $guardName);
        $connection->set('auth_method', $userData['auth_method'] ?? 'jwt');

        // Store token-specific data
        if (isset($userData['issued_at'])) {
            $connection->set('token_issued_at', $userData['issued_at']);
        }
        if (isset($userData['expires_at'])) {
            $connection->set('token_expires_at', $userData['expires_at']);
        }

        // Store additional user info if available
        if (isset($userData['username'])) {
            $connection->set('username', $userData['username']);
        }
        if (isset($userData['email'])) {
            $connection->set('email', $userData['email']);
        }
        if (isset($userData['name'])) {
            $connection->set('username', $userData['name']);
        }
        if (isset($userData['roles'])) {
            $connection->set('roles', $userData['roles']);
        }

        $authMethod = $userData['auth_method'] ?? 'jwt';
        echo "[{$connection->getId()}] Authenticated as user {$userData['user_id']} (guard: {$guardName}, method: {$authMethod})\n";

        // Send success response
        $this->emitToConnection($connection, 'authenticated', [
            'user_id' => $userData['user_id'],
            'socket_id' => $connection->get('sid'),
            'guard' => $guardName,
            'method' => $authMethod,
        ]);

        // Send ACK if requested
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, [
                'authenticated' => true,
                'user_id' => $userData['user_id'],
                'guard' => $guardName,
                'method' => $authMethod,
            ]);
        }
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
     * PHP native format: "auth_web|i:1;other_key|s:5:\"value\";"
     * Toporia format: serialize(['data' => [...], 'expires_at' => timestamp])
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

        // Try Toporia's custom format first: serialize(['data' => [...], 'expires_at' => ...])
        $session = @unserialize($sessionData, ['allowed_classes' => false]);
        if (is_array($session) && isset($session['data']) && isset($session['expires_at'])) {
            echo "[Session Parse] Detected Toporia custom format\n";

            if ($session['expires_at'] < time()) {
                echo "[Session Parse] Session expired\n";
                return null;
            }

            return $this->extractAuthFromData($session['data'], $guardToCheck, $guardName);
        }

        // Try PHP native session format: "key|serialized_value;key2|serialized_value2;"
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
     * Format: "key|serialized_value;key2|serialized_value2;"
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

            // Handle boolean false: 'b:0;'
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
                    // Skip malformed entry
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
        // Try primary guard
        $authKey = "auth_{$guardToCheck}";
        if (isset($data[$authKey]) && !empty($data[$authKey])) {
            echo "[Session Parse] Found auth at {$authKey} = {$data[$authKey]}\n";
            return ['user_id' => $data[$authKey], 'guard' => $guardToCheck];
        }

        // Try original guard name
        if ($guardToCheck !== $originalGuard) {
            $authKey = "auth_{$originalGuard}";
            if (isset($data[$authKey]) && !empty($data[$authKey])) {
                echo "[Session Parse] Found auth at {$authKey} = {$data[$authKey]}\n";
                return ['user_id' => $data[$authKey], 'guard' => $originalGuard];
            }
        }

        // Fallback: Check all auth_* keys
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
     * Verify JWT token with guard-specific secret.
     *
     * @param string $token JWT token
     * @param string $guardName Guard name
     * @return array|null User data or null if invalid
     */
    private function verifyTokenWithGuard(string $token, string $guardName): ?array
    {
        // First try using AuthMiddleware (uses framework's auth system)
        $userData = AuthMiddleware::verifyToken($token);

        if ($userData !== null) {
            // Validate guard matches if specified in token
            $tokenGuard = $userData['guard'] ?? null;
            if ($tokenGuard !== null && $tokenGuard !== $guardName) {
                error_log("[SocketIO] Token guard '{$tokenGuard}' does not match requested guard '{$guardName}'");
                return null;
            }
            return $userData;
        }

        // Fallback: Manual JWT verification with guard-specific secret
        return $this->manualJwtVerify($token, $guardName);
    }

    /**
     * Manually verify JWT token (fallback when AuthMiddleware fails).
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
                'issued_at' => $payload['iat'] ?? null,
                'expires_at' => $payload['exp'] ?? null,
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
     * Handle 'subscribe' event - subscribe to a channel with optional auth signature.
     *
     * Two modes:
     * 1. Public channel: socket.emit('subscribe', { channel: 'news' })
     * 2. Private/Presence with auth: socket.emit('subscribe', { channel: 'private-orders.123', auth: 'app_key:signature' })
     *
     * @param ConnectionInterface $connection
     * @param mixed $data { channel: string, auth?: string, channel_data?: string }
     * @param string|null $ackId
     * @return void
     */
    private function handleSubscribe(ConnectionInterface $connection, mixed $data, ?string $ackId): void
    {
        $channelName = is_array($data) ? ($data['channel'] ?? null) : $data;

        if (empty($channelName)) {
            $this->emitToConnection($connection, 'subscription_error', ['message' => 'Channel name required']);
            return;
        }

        // Determine channel type
        $channelType = $this->getChannelType($channelName);

        // For private/presence channels, verify authentication
        if ($channelType !== 'public') {
            $authorized = $this->authorizeSubscription($connection, $channelName, $data);

            if (!$authorized) {
                $this->emitToConnection($connection, 'subscription_error', [
                    'channel' => $channelName,
                    'message' => 'Unauthorized',
                ]);
                return;
            }
        }

        // Subscribe to channel
        $this->subscribeToChannel($connection, $channelName);

        // Send success response
        $this->emitToConnection($connection, 'subscribed', ['channel' => $channelName]);

        // Send ACK if requested
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, ['channel' => $channelName, 'subscribed' => true]);
        }
    }

    /**
     * Authorize subscription to private/presence channel.
     *
     * Supports two methods:
     * 1. Auth signature from /broadcasting/auth endpoint
     * 2. Direct auth if connection is already authenticated
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @param mixed $data Subscribe request data
     * @return bool
     */
    private function authorizeSubscription(ConnectionInterface $connection, string $channelName, mixed $data): bool
    {
        $authSignature = is_array($data) ? ($data['auth'] ?? null) : null;
        $channelData = is_array($data) ? ($data['channel_data'] ?? null) : null;
        $socketId = $connection->get('sid');

        // Method 1: Verify auth signature (Toporia-style)
        if ($authSignature !== null && $this->authenticator !== null) {
            $isValid = $this->authenticator->verifySignature(
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
        if ($connection->isAuthenticated()) {
            // Normalize channel name for route matching
            $normalizedChannel = $this->normalizeChannelName($channelName);

            // Find channel definition
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
                'username' => $connection->getUsername(),
                'email' => $connection->getEmail(),
                'roles' => $connection->getRoles(),
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
                error_log("[SocketIO] Authorization callback error: {$e->getMessage()}");
                return false;
            }
        }

        // Not authenticated and no auth signature
        echo "[{$connection->getId()}] Not authenticated for private channel '{$channelName}'\n";
        return false;
    }

    /**
     * Subscribe connection to a channel.
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @return void
     */
    private function subscribeToChannel(ConnectionInterface $connection, string $channelName): void
    {
        // Add to room
        if (!isset($this->rooms[$channelName])) {
            $this->rooms[$channelName] = [];
        }
        $this->rooms[$channelName][$connection->getId()] = $connection;

        // Track in connection
        $rooms = $connection->get('rooms', []);
        $rooms[] = $channelName;
        $connection->set('rooms', array_unique($rooms));

        // Also mark as subscribed channel
        $connection->subscribe($channelName);

        // Subscribe via manager
        $channel = $this->manager->channel($channelName);
        $channel->subscribe($connection);

        echo "[{$connection->getId()}] Subscribed to channel: {$channelName}\n";

        // For presence channels, broadcast join event
        if (str_starts_with($channelName, 'presence-')) {
            $this->broadcastPresenceJoin($connection, $channelName);
        }
    }

    /**
     * Handle 'unsubscribe' event.
     *
     * @param ConnectionInterface $connection
     * @param mixed $data { channel: string }
     * @param string|null $ackId
     * @return void
     */
    private function handleUnsubscribe(ConnectionInterface $connection, mixed $data, ?string $ackId): void
    {
        $channelName = is_array($data) ? ($data['channel'] ?? null) : $data;

        if (empty($channelName)) {
            return;
        }

        // For presence channels, broadcast leave event first
        if (str_starts_with($channelName, 'presence-')) {
            $this->broadcastPresenceLeave($connection, $channelName);
        }

        // Remove from room
        if (isset($this->rooms[$channelName][$connection->getId()])) {
            unset($this->rooms[$channelName][$connection->getId()]);
        }

        // Update connection
        $rooms = $connection->get('rooms', []);
        $rooms = array_diff($rooms, [$channelName]);
        $connection->set('rooms', array_values($rooms));
        $connection->unsubscribe($channelName);

        // Unsubscribe via manager
        $channel = $this->manager->channel($channelName);
        $channel->unsubscribe($connection);

        echo "[{$connection->getId()}] Unsubscribed from channel: {$channelName}\n";

        // Send ACK
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, ['channel' => $channelName, 'unsubscribed' => true]);
        }
    }

    /**
     * Broadcast presence join event to channel members.
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @return void
     */
    private function broadcastPresenceJoin(ConnectionInterface $connection, string $channelName): void
    {
        $userInfo = [
            'user_id' => $connection->getUserId(),
            'user_info' => $connection->get('presence_user_info', [
                'name' => $connection->getUsername(),
            ]),
        ];

        $message = Message::event($channelName, 'presence:member_added', $userInfo);
        $this->broadcastToRoom($channelName, $message, $connection); // Exclude joiner
    }

    /**
     * Broadcast presence leave event to channel members.
     *
     * @param ConnectionInterface $connection
     * @param string $channelName
     * @return void
     */
    private function broadcastPresenceLeave(ConnectionInterface $connection, string $channelName): void
    {
        $userInfo = [
            'user_id' => $connection->getUserId(),
        ];

        $message = Message::event($channelName, 'presence:member_removed', $userInfo);
        $this->broadcastToRoom($channelName, $message, $connection); // Exclude leaver
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
     * Emit event to a specific connection.
     *
     * @param ConnectionInterface $connection
     * @param string $event
     * @param mixed $data
     * @return void
     */
    private function emitToConnection(ConnectionInterface $connection, string $event, mixed $data): void
    {
        $packet = $this->createSocketIOPacket(
            type: self::PACKET_EVENT,
            namespace: $connection->get('namespace', '/'),
            data: [$event, $data]
        );

        $this->sendRaw($connection, self::EIO_MESSAGE . $packet);
    }

    /**
     * Handle custom Socket.IO event.
     *
     * @param ConnectionInterface $connection
     * @param string $eventName
     * @param mixed $eventData
     * @param string|null $ackId
     * @return void
     */
    private function handleCustomEvent(ConnectionInterface $connection, string $eventName, mixed $eventData, ?string $ackId): void
    {
        // Convert to internal message format
        $message = Message::event(null, $eventName, $eventData);

        // Get current rooms/channels
        $rooms = $connection->get('rooms', []);

        // Broadcast to all rooms the user is in
        foreach ($rooms as $room) {
            $channel = $this->manager->channel($room);
            $channel->broadcast($message, $connection); // Exclude sender
        }

        // Send ACK if requested
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, ['status' => 'ok']);
        }
    }

    /**
     * Handle join room request.
     *
     * @param ConnectionInterface $connection
     * @param mixed $data Room name or array of room names
     * @param string|null $ackId
     * @return void
     */
    private function handleJoinRoom(ConnectionInterface $connection, mixed $data, ?string $ackId): void
    {
        $roomName = is_array($data) ? ($data['room'] ?? $data[0] ?? null) : $data;

        if (!$roomName) {
            return;
        }

        // Add to room
        if (!isset($this->rooms[$roomName])) {
            $this->rooms[$roomName] = [];
        }
        $this->rooms[$roomName][$connection->getId()] = $connection;

        // Track rooms in connection
        $rooms = $connection->get('rooms', []);
        $rooms[] = $roomName;
        $connection->set('rooms', array_unique($rooms));

        // Subscribe to channel
        $channel = $this->manager->channel($roomName);
        $channel->subscribe($connection);

        echo "[{$connection->getId()}] Joined room: {$roomName}\n";

        // Send ACK
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, ['room' => $roomName]);
        }
    }

    /**
     * Handle leave room request.
     *
     * @param ConnectionInterface $connection
     * @param mixed $data Room name
     * @param string|null $ackId
     * @return void
     */
    private function handleLeaveRoom(ConnectionInterface $connection, mixed $data, ?string $ackId): void
    {
        $roomName = is_array($data) ? ($data['room'] ?? $data[0] ?? null) : $data;

        if (!$roomName) {
            return;
        }

        // Remove from room
        if (isset($this->rooms[$roomName][$connection->getId()])) {
            unset($this->rooms[$roomName][$connection->getId()]);
        }

        // Update connection rooms
        $rooms = $connection->get('rooms', []);
        $rooms = array_diff($rooms, [$roomName]);
        $connection->set('rooms', array_values($rooms));

        // Unsubscribe from channel
        $channel = $this->manager->channel($roomName);
        $channel->unsubscribe($connection);

        echo "[{$connection->getId()}] Left room: {$roomName}\n";

        // Send ACK
        if ($ackId !== null) {
            $this->sendAck($connection, $ackId, ['room' => $roomName]);
        }
    }

    /**
     * Handle ACK packet.
     *
     * @param ConnectionInterface $connection
     * @param array $packet
     * @return void
     */
    private function handleAck(ConnectionInterface $connection, array $packet): void
    {
        // ACK handling for client-initiated events
        // Store callbacks and invoke them here
        // This is for advanced use cases
    }

    /**
     * Handle disconnect.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    private function handleDisconnect(ConnectionInterface $connection): void
    {
        $this->removeFromAllRooms($connection);
    }

    /**
     * Broadcast message to namespace.
     *
     * @param string $namespace
     * @param MessageInterface $message
     * @return void
     */
    private function broadcastToNamespace(string $namespace, MessageInterface $message): void
    {
        $connections = $this->namespaces[$namespace] ?? [];

        $packet = $this->createSocketIOPacket(
            type: self::PACKET_EVENT,
            namespace: $namespace,
            data: [$message->getEvent(), $message->getData()]
        );

        $eioPacket = self::EIO_MESSAGE . $packet;

        foreach ($connections as $connection) {
            $fd = (int) $connection->getResource();
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $eioPacket);
            }
        }
    }

    /**
     * Broadcast message to room.
     *
     * @param string $room
     * @param MessageInterface $message
     * @param ConnectionInterface|null $except
     * @return void
     */
    private function broadcastToRoom(string $room, MessageInterface $message, ?ConnectionInterface $except = null): void
    {
        $connections = $this->rooms[$room] ?? [];

        $packet = $this->createSocketIOPacket(
            type: self::PACKET_EVENT,
            namespace: '/',
            data: [$message->getEvent(), $message->getData()]
        );

        $eioPacket = self::EIO_MESSAGE . $packet;

        foreach ($connections as $connection) {
            if ($except && $connection->getId() === $except->getId()) {
                continue;
            }

            $fd = (int) $connection->getResource();
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $eioPacket);
            }
        }
    }

    /**
     * Send pong response.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    private function sendPong(ConnectionInterface $connection): void
    {
        $this->sendRaw($connection, self::EIO_PONG);
    }

    /**
     * Send ACK packet.
     *
     * @param ConnectionInterface $connection
     * @param string $ackId
     * @param mixed $data
     * @return void
     */
    private function sendAck(ConnectionInterface $connection, string $ackId, mixed $data): void
    {
        $packet = $this->createSocketIOPacket(
            type: self::PACKET_ACK,
            namespace: $connection->get('namespace', '/'),
            data: [$data],
            ackId: $ackId
        );

        $this->sendRaw($connection, self::EIO_MESSAGE . $packet);
    }

    /**
     * Send error packet.
     *
     * @param ConnectionInterface $connection
     * @param string $error
     * @return void
     */
    private function sendError(ConnectionInterface $connection, string $error): void
    {
        $packet = $this->createSocketIOPacket(
            type: self::PACKET_ERROR,
            namespace: $connection->get('namespace', '/'),
            data: $error
        );

        $this->sendRaw($connection, self::EIO_MESSAGE . $packet);
    }

    /**
     * Send raw packet.
     *
     * @param ConnectionInterface $connection
     * @param string $data
     * @return void
     */
    private function sendRaw(ConnectionInterface $connection, string $data): void
    {
        $fd = (int) $connection->getResource();
        if ($this->server && $this->server->isEstablished($fd)) {
            $this->server->push($fd, $data);
        }
    }

    /**
     * Remove connection from all rooms.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    private function removeFromAllRooms(ConnectionInterface $connection): void
    {
        $rooms = $connection->get('rooms', []);

        foreach ($rooms as $room) {
            if (isset($this->rooms[$room][$connection->getId()])) {
                unset($this->rooms[$room][$connection->getId()]);
            }
        }
    }

    /**
     * Create Socket.IO packet string.
     *
     * Format: type[namespace,][ackId,]data
     *
     * @param int $type Packet type
     * @param string $namespace Namespace
     * @param mixed $data Packet data
     * @param string|null $ackId Acknowledgment ID
     * @return string
     */
    private function createSocketIOPacket(int $type, string $namespace, mixed $data = null, ?string $ackId = null): string
    {
        $packet = (string) $type;

        // Add namespace if not default
        if ($namespace !== '/') {
            $packet .= $namespace . ',';
        }

        // Add ACK ID
        if ($ackId !== null) {
            $packet .= $ackId;
        }

        // Add data
        if ($data !== null) {
            $packet .= json_encode($data);
        }

        return $packet;
    }

    /**
     * Parse Socket.IO packet.
     *
     * @param string $packet
     * @return array
     */
    private function parseSocketIOPacket(string $packet): array
    {
        $result = [
            'type' => (int) ($packet[0] ?? self::PACKET_EVENT),
            'namespace' => '/',
            'ackId' => null,
            'data' => null,
        ];

        $remaining = substr($packet, 1);

        // Parse namespace - only if namespace comes before data
        // Namespace format: /custom-ns,data or just data
        if (str_starts_with($remaining, '/')) {
            $commaPos = strpos($remaining, ',');
            if ($commaPos !== false) {
                $result['namespace'] = substr($remaining, 0, $commaPos);
                $remaining = substr($remaining, $commaPos + 1);
            }
        }

        // Parse ack ID (if numeric and before data)
        // AckId is digits before [ or {
        if ($remaining !== '' && ctype_digit($remaining[0])) {
            $ackIdEnd = strcspn($remaining, '[{');
            if ($ackIdEnd > 0 && $ackIdEnd < strlen($remaining)) {
                $result['ackId'] = substr($remaining, 0, $ackIdEnd);
                $remaining = substr($remaining, $ackIdEnd);
            }
        }

        // Parse data (JSON)
        if ($remaining !== '') {
            try {
                $result['data'] = json_decode($remaining, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $result['data'] = $remaining;
            }
        }

        return $result;
    }
}
