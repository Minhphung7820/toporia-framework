<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Subscriptions;

use Toporia\Framework\Realtime\Contracts\BrokerSubscriptionStrategyInterface;

/**
 * Class RedisBrokerSubscriptionStrategy
 *
 * Redis Pub/Sub subscription strategy for WebSocket transport.
 * Uses Swoole Coroutine Client with raw RESP protocol for non-blocking I/O.
 *
 * Features:
 * - Auto-reconnect with exponential backoff (1s -> 2s -> 4s -> ... -> 30s max)
 * - Non-blocking subscription using Swoole coroutines
 * - Production-ready reliability
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Subscriptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RedisBrokerSubscriptionStrategy implements BrokerSubscriptionStrategyInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(
        \Swoole\WebSocket\Server $server,
        callable $messageHandler,
        callable $isRunning
    ): void {
        \Swoole\Coroutine::create(function () use ($server, $messageHandler, $isRunning) {
            $host = $this->config['host'] ?? env('REDIS_HOST', '127.0.0.1');
            $port = (int) ($this->config['port'] ?? env('REDIS_PORT', 6379));
            $password = $this->config['password'] ?? env('REDIS_PASSWORD');
            $pattern = 'realtime:*';

            // Exponential backoff settings
            $baseDelay = 1.0;
            $maxDelay = 30.0;
            $currentDelay = $baseDelay;
            $consecutiveFailures = 0;

            // Auto-reconnect loop
            while ($isRunning()) {
                echo "[Redis Broker] Connecting to {$host}:{$port}...\n";

                $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                $client->set([
                    'open_eof_check' => false,
                    'package_max_length' => 1024 * 1024,
                ]);

                // Try to connect
                if (!$client->connect($host, $port, 5.0)) {
                    $consecutiveFailures++;
                    echo "[Redis Broker] Failed to connect: {$client->errMsg} (attempt #{$consecutiveFailures})\n";

                    $currentDelay = min($baseDelay * pow(2, $consecutiveFailures - 1), $maxDelay);
                    echo "[Redis Broker] Retrying in {$currentDelay}s...\n";
                    \Swoole\Coroutine::sleep($currentDelay);
                    continue;
                }

                // Authenticate if password is set
                if ($password && $password !== 'null') {
                    $authCmd = "*2\r\n\$4\r\nAUTH\r\n\$" . strlen($password) . "\r\n{$password}\r\n";
                    $client->send($authCmd);
                    $authResponse = $client->recv(5.0);
                    if (!$authResponse || !str_starts_with($authResponse, '+OK')) {
                        echo "[Redis Broker] Authentication failed: {$authResponse}\n";
                        $client->close();

                        $consecutiveFailures++;
                        $currentDelay = min($baseDelay * pow(2, $consecutiveFailures - 1), $maxDelay);
                        \Swoole\Coroutine::sleep($currentDelay);
                        continue;
                    }
                }

                // Send PSUBSCRIBE command
                $psubscribeCmd = "*2\r\n\$10\r\nPSUBSCRIBE\r\n\$" . strlen($pattern) . "\r\n{$pattern}\r\n";
                $client->send($psubscribeCmd);

                // Read subscription confirmation
                $confirmation = $client->recv(5.0);
                if (!$confirmation) {
                    echo "[Redis Broker] Failed to subscribe\n";
                    $client->close();

                    $consecutiveFailures++;
                    $currentDelay = min($baseDelay * pow(2, $consecutiveFailures - 1), $maxDelay);
                    \Swoole\Coroutine::sleep($currentDelay);
                    continue;
                }

                // Reset backoff on successful connection
                $consecutiveFailures = 0;
                $currentDelay = $baseDelay;
                echo "[Redis Broker] Connected and subscribed to pattern: {$pattern}\n";

                // Main loop - receive messages
                while ($isRunning()) {
                    $response = $client->recv(86400.0);

                    if ($response === false || $response === '') {
                        echo "[Redis Broker] Connection lost, reconnecting...\n";
                        break;
                    }

                    $this->handleResponse($response, $messageHandler);
                }

                $client->close();

                if ($isRunning()) {
                    \Swoole\Coroutine::sleep(1.0);
                }
            }

            echo "[Redis Broker] Subscription stopped\n";
        });
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
    public function supports(string $brokerName): bool
    {
        return $brokerName === 'redis';
    }

    /**
     * Parse Redis RESP protocol response and extract message data.
     *
     * @param string $response Raw RESP response
     * @param callable $messageHandler
     * @return void
     */
    private function handleResponse(string $response, callable $messageHandler): void
    {
        $lines = explode("\r\n", $response);
        $pmessageIdx = array_search('pmessage', $lines);

        if ($pmessageIdx === false) {
            return;
        }

        $channel = $lines[$pmessageIdx + 4] ?? '';
        $messageJson = $lines[$pmessageIdx + 6] ?? '';

        if (!$channel || !$messageJson) {
            return;
        }

        try {
            $messageData = json_decode($messageJson, true);
            if (!$messageData) {
                return;
            }

            // Extract channel name (remove 'realtime:' prefix)
            $channelName = str_replace('realtime:', '', $channel);
            $event = $messageData['event'] ?? 'message';
            $data = $messageData['data'] ?? [];

            $messageHandler($channelName, $event, $data);
        } catch (\Throwable $e) {
            error_log("[Redis] Error: {$e->getMessage()}");
        }
    }
}
