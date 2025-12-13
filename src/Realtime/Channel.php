<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Contracts\{ChannelInterface, ConnectionInterface, MessageInterface, TransportInterface};
use Toporia\Framework\Realtime\Sync\AtomicLock;

/**
 * Class Channel
 *
 * Manages connections subscribed to a specific channel/topic.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Channel implements ChannelInterface
{
    /**
     * @var array<string, ConnectionInterface> Subscribers indexed by connection ID
     */
    private array $subscribers = [];

    /**
     * Atomic lock for thread-safe operations.
     */
    private AtomicLock $lock;

    /**
     * @param string $name Channel name
     * @param TransportInterface|null $transport Transport for broadcasting
     * @param callable|null $authorizer Authorization callback
     */
    public function __construct(
        private readonly string $name,
        private readonly ?TransportInterface $transport = null,
        private readonly mixed $authorizer = null
    ) {
        $this->lock = new AtomicLock();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function isPublic(): bool
    {
        return !$this->isPrivate() && !$this->isPresence();
    }

    /**
     * {@inheritdoc}
     */
    public function isPrivate(): bool
    {
        return str_starts_with($this->name, 'private-')
            || str_starts_with($this->name, 'private.')
            || str_starts_with($this->name, 'user.');
    }

    /**
     * {@inheritdoc}
     */
    public function isPresence(): bool
    {
        return str_starts_with($this->name, 'presence-')
            || str_starts_with($this->name, 'presence.');
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriberCount(): int
    {
        return count($this->subscribers);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribers(): array
    {
        return array_values($this->subscribers);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(ConnectionInterface $connection): void
    {
        $this->lock->synchronized(function () use ($connection): void {
            // Double-check to avoid duplicate subscription
            $connId = $connection->getId();
            if (!isset($this->subscribers[$connId])) {
                $this->subscribers[$connId] = $connection;
                $connection->subscribe($this->name);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(ConnectionInterface $connection): void
    {
        $this->lock->synchronized(function () use ($connection): void {
            $connId = $connection->getId();
            if (isset($this->subscribers[$connId])) {
                unset($this->subscribers[$connId]);
                $connection->unsubscribe($this->name);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function hasSubscriber(ConnectionInterface $connection): bool
    {
        return isset($this->subscribers[$connection->getId()]);
    }

    /**
     * {@inheritdoc}
     */
    public function broadcast(MessageInterface $message, ?ConnectionInterface $except = null): void
    {
        if (!$this->transport) {
            return; // No transport available
        }

        // Get subscribers as a snapshot to avoid modification during iteration
        $subscribers = $this->subscribers;
        $exceptId = $except ? $except->getId() : null;

        // Batch size for chunked broadcasting (prevents blocking)
        $batchSize = 100;
        $batch = [];
        $batchCount = 0;

        foreach ($subscribers as $connId => $connection) {
            // Skip excluded connection
            if ($exceptId !== null && $connId === $exceptId) {
                continue;
            }

            $batch[] = $connection;
            $batchCount++;

            // Process batch when full
            if ($batchCount >= $batchSize) {
                $this->sendBatch($batch, $message);
                $batch = [];
                $batchCount = 0;

                // Yield CPU time in coroutine context for better concurrency
                if (function_exists('\\Swoole\\Coroutine::yield')) {
                    \Swoole\Coroutine::yield();
                }
            }
        }

        // Process remaining connections
        if ($batchCount > 0) {
            $this->sendBatch($batch, $message);
        }
    }

    /**
     * Send message to a batch of connections.
     *
     * @param array<ConnectionInterface> $connections
     * @param MessageInterface $message
     * @return void
     */
    private function sendBatch(array $connections, MessageInterface $message): void
    {
        foreach ($connections as $connection) {
            try {
                $this->transport->send($connection, $message);
            } catch (\Throwable $e) {
                // Log error but continue broadcasting
                error_log("Failed to send to connection {$connection->getId()}: {$e->getMessage()}");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function authorize(ConnectionInterface $connection): bool
    {
        // If authorizer is set, always call it (regardless of public/private)
        if ($this->authorizer) {
            try {
                return (bool) call_user_func($this->authorizer, $connection, $this->name);
            } catch (\Throwable $e) {
                error_log("Authorization failed for {$this->name}: {$e->getMessage()}");
                return false;
            }
        }

        // No authorizer set:
        // - Public channels are allowed by default
        // - Private/Presence channels are denied by default
        return $this->isPublic();
    }

    /**
     * Get presence data for presence channels.
     *
     * Returns list of online users with their data.
     *
     * @return array
     */
    public function getPresenceData(): array
    {
        if (!$this->isPresence()) {
            return [];
        }

        $presence = [];

        foreach ($this->subscribers as $connection) {
            $userId = $connection->getUserId();
            if ($userId === null) {
                continue;
            }

            $presence[] = [
                'user_id' => $userId,
                'user_info' => $connection->get('user_info', []),
                'connected_at' => $connection->getConnectedAt()
            ];
        }

        return $presence;
    }
}
