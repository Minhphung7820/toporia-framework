<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface ChannelInterface
 *
 * Contract defining the interface for ChannelInterface implementations in
 * the Real-time broadcasting layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ChannelInterface
{
    /**
     * Get channel name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if channel is public.
     *
     * @return bool
     */
    public function isPublic(): bool;

    /**
     * Check if channel is private.
     *
     * @return bool
     */
    public function isPrivate(): bool;

    /**
     * Check if channel is presence-enabled.
     *
     * @return bool
     */
    public function isPresence(): bool;

    /**
     * Get subscriber count.
     *
     * @return int
     */
    public function getSubscriberCount(): int;

    /**
     * Get all subscribers.
     *
     * @return array<ConnectionInterface>
     */
    public function getSubscribers(): array;

    /**
     * Add subscriber to channel.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function subscribe(ConnectionInterface $connection): void;

    /**
     * Remove subscriber from channel.
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function unsubscribe(ConnectionInterface $connection): void;

    /**
     * Check if connection is subscribed.
     *
     * @param ConnectionInterface $connection
     * @return bool
     */
    public function hasSubscriber(ConnectionInterface $connection): bool;

    /**
     * Broadcast message to all subscribers.
     *
     * @param MessageInterface $message
     * @param ConnectionInterface|null $except Exclude this connection
     * @return void
     */
    public function broadcast(MessageInterface $message, ?ConnectionInterface $except = null): void;

    /**
     * Authorize connection to join channel.
     *
     * For private/presence channels, validates user permissions.
     *
     * @param ConnectionInterface $connection
     * @return bool
     */
    public function authorize(ConnectionInterface $connection): bool;
}
