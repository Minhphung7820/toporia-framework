<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Sse;

use Toporia\Framework\Http\Contracts\RequestInterface;
use Toporia\Framework\Http\Contracts\ResponseInterface;
use Toporia\Framework\Realtime\Contracts\RealtimeManagerInterface;
use Toporia\Framework\Realtime\{Connection, Message};

/**
 * SSE Controller
 *
 * Server-Sent Events (SSE) controller for one-way server-to-client push.
 * SSE is an HTTP-based protocol, so it belongs in the Http layer.
 *
 * SSE is NOT the same as WebSocket:
 * - SSE: One-way (server → client), runs over HTTP
 * - WebSocket: Two-way (bidirectional), separate protocol
 *
 * Usage in routes/web.php:
 * ```php
 * $router->get('/sse', [SseController::class, 'stream']);
 * ```
 *
 * Client-side:
 * ```javascript
 * const eventSource = new EventSource('/sse?channels=news,updates');
 * eventSource.onmessage = (e) => console.log(e.data);
 * eventSource.addEventListener('news', (e) => console.log('News:', e.data));
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Sse
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SseController
{
    private array $connections = [];

    public function __construct(
        private readonly ?RealtimeManagerInterface $manager = null
    ) {}

    /**
     * Handle SSE stream request.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function stream(RequestInterface $request, ResponseInterface $response): void
    {
        // SSE-specific HTTP headers
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no'); // Nginx compatibility

        // Disable output buffering for streaming
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Unlimited execution time for long-lived connection
        set_time_limit(0);

        // Create connection using php://output stream
        $resource = fopen('php://output', 'w');
        $connection = new Connection($resource, [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        $this->connections[$connection->getId()] = $connection;

        // Register with realtime manager if available
        if ($this->manager) {
            $this->manager->addConnection($connection);

            // Subscribe to channels from query string
            $this->subscribeToChannels($request, $connection);
        }

        // Send initial connection event
        $this->sendEvent($resource, 'connected', [
            'id' => $connection->getId(),
            'timestamp' => time(),
        ]);

        // Keep-alive loop
        $lastKeepAlive = time();

        while (!connection_aborted() && is_resource($resource) && !feof($resource)) {
            // Send keep-alive comment every 15 seconds
            if (time() - $lastKeepAlive >= 15) {
                fwrite($resource, ": keep-alive\n\n");
                fflush($resource);
                $lastKeepAlive = time();
            }

            // Sleep to avoid busy-wait (100ms)
            usleep(100000);
        }

        // Cleanup on disconnect
        $this->cleanup($connection, $resource);
    }

    /**
     * Subscribe connection to channels from query string.
     *
     * @param RequestInterface $request
     * @param Connection $connection
     * @return void
     */
    private function subscribeToChannels(RequestInterface $request, Connection $connection): void
    {
        $channels = $request->query('channels');

        if (!$channels) {
            return;
        }

        $channelNames = array_map('trim', explode(',', $channels));

        foreach ($channelNames as $channelName) {
            if (empty($channelName)) {
                continue;
            }

            $channel = $this->manager->channel($channelName);

            // Check authorization before subscribing
            if ($channel->authorize($connection)) {
                $channel->subscribe($connection);

                // Send subscription confirmation
                $resource = $connection->getResource();
                $this->sendEvent($resource, 'subscribed', ['channel' => $channelName]);
            }
        }
    }

    /**
     * Send SSE event to client.
     *
     * SSE Format:
     * ```
     * id: {id}
     * event: {event_name}
     * data: {json_data}
     *
     * ```
     *
     * @param resource $resource Output stream
     * @param string $event Event name
     * @param array $data Event data
     * @param string|null $id Message ID
     * @return void
     */
    private function sendEvent($resource, string $event, array $data, ?string $id = null): void
    {
        if (!is_resource($resource) || feof($resource)) {
            return;
        }

        $output = '';

        // Message ID (for client reconnection)
        if ($id) {
            $output .= "id: {$id}\n";
        }

        // Event type
        $output .= "event: {$event}\n";

        // Data as JSON
        $output .= 'data: ' . json_encode($data) . "\n";

        // Terminator (double newline)
        $output .= "\n";

        fwrite($resource, $output);
        fflush($resource);
    }

    /**
     * Cleanup connection on disconnect.
     *
     * @param Connection $connection
     * @param resource $resource
     * @return void
     */
    private function cleanup(Connection $connection, $resource): void
    {
        if ($this->manager) {
            $this->manager->removeConnection($connection);
        }

        unset($this->connections[$connection->getId()]);

        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * Get active connection count.
     *
     * @return int
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }
}
