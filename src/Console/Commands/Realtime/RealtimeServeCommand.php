<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Realtime\Contracts\RealtimeManagerInterface;

/**
 * Realtime Server Command
 *
 * Start the realtime server with configured transport.
 *
 * Usage:
 * - php console realtime:serve                    # Start with default config
 * - php console realtime:serve --transport=websocket
 * - php console realtime:serve --host=0.0.0.0 --port=6001
 * - php console realtime:serve --transport=sse
 *
 * @package Toporia\Framework\Console\Commands
 */
/**
 * Class RealtimeServeCommand
 *
 * Start the realtime server for broadcasting.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RealtimeServeCommand extends Command
{
    protected string $signature = 'realtime:serve';
    protected string $description = 'Start the realtime server';

    public function __construct(
        private readonly RealtimeManagerInterface $realtime
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function handle(): int
    {
        $transport = $this->option('transport', config('realtime.default_transport', 'memory'));
        $host = $this->option('host', '0.0.0.0');
        $port = (int) $this->option('port', 6001);

        $this->newLine();
        $this->info("╔════════════════════════════════════════════════════════════╗");
        $this->info("║                   Realtime Server                           ║");
        $this->info("╠════════════════════════════════════════════════════════════╣");
        $this->writeln("║ Transport: <fg=cyan>" . str_pad($transport, 48) . "</> ║");
        $this->writeln("║ Host:      <fg=cyan>" . str_pad($host, 48) . "</> ║");
        $this->writeln("║ Port:      <fg=cyan>" . str_pad((string) $port, 48) . "</> ║");
        $this->info("╚════════════════════════════════════════════════════════════╝");
        $this->newLine();

        // Check transport requirements
        if ($transport === 'websocket') {
            if (!extension_loaded('swoole')) {
                $this->error('Swoole extension is required for WebSocket transport');
                $this->warn('Install: pecl install swoole');
                return 1;
            }

            $this->success('Swoole extension detected');
        }

        try {
            // Get transport instance
            $transportInstance = $this->realtime->transport($transport);

            // Register signal handlers for graceful shutdown
            $this->registerSignalHandlers($transportInstance);

            // Start broker subscription if broker is configured
            $this->startBrokerSubscription($transportInstance);

            // Start server
            $this->info("Server starting on {$host}:{$port}...\n");

            $transportInstance->start($host, $port);

            // This line is reached when server stops
            $this->info("\nServer stopped");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed to start server: {$e->getMessage()}");

            if ($this->hasOption('verbose')) {
                $this->line($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Start broker subscription to receive messages from other servers.
     *
     * NOTE: Broker subscription is now handled internally by each transport
     * using Swoole coroutines (WebSocketTransport, SocketIOGateway).
     * This method only displays info about the configured broker.
     *
     * @param mixed $transport Transport instance
     * @return void
     */
    private function startBrokerSubscription($transport): void
    {
        $brokerName = config('realtime.default_broker') ?: env('REALTIME_BROKER');

        if (!$brokerName) {
            $this->writeln('Broker: <fg=gray>none (single server mode)</>');
            return;
        }

        // Broker subscription is handled by the transport itself in workerStart event
        // Just display info here
        $this->writeln("Broker: <fg=green>{$brokerName}</> (subscription handled by transport)");
    }

    /**
     * Register signal handlers for graceful shutdown.
     *
     * Note: For Swoole WebSocket server, signals are handled internally by Swoole.
     * This method sets up pcntl signals for non-Swoole transports or as fallback.
     * Swoole handles SIGTERM/SIGINT automatically and will trigger shutdown.
     *
     * @param mixed $transport Transport instance
     * @return void
     */
    private function registerSignalHandlers($transport): void
    {
        // For Swoole: signals are handled in workerStart event
        // Swoole automatically handles SIGTERM/SIGINT for graceful shutdown
        // No need to register pcntl_signal as it conflicts with Swoole's event loop

        // Display shutdown instructions
        $this->newLine();
        $this->writeln('<fg=yellow>Press Ctrl+C to stop the server</>');
        $this->writeln('Or run: <fg=cyan>php console realtime:stop</>');
        $this->newLine();
    }
}
