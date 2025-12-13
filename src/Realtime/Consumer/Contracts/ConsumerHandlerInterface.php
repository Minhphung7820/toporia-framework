<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer\Contracts;

use Toporia\Framework\Realtime\Contracts\MessageInterface;

/**
 * Interface ConsumerHandlerInterface
 *
 * Contract for broker message consumer handlers.
 * Handlers process incoming messages from brokers (Redis, RabbitMQ, Kafka).
 *
 * Usage:
 *   php console broker:consume --handler=SendOrderCreated --driver=rabbitmq
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Consumer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ConsumerHandlerInterface
{
    /**
     * Handle the incoming message.
     *
     * This is the main entry point for processing broker messages.
     * Implement your business logic here (save to DB, send email, dispatch jobs, etc.)
     *
     * @param MessageInterface $message The incoming message from broker
     * @param ConsumerContext $context Additional context (driver, channel, metadata)
     * @return void
     * @throws \Throwable On unrecoverable errors (will be caught and logged)
     */
    public function handle(MessageInterface $message, ConsumerContext $context): void;

    /**
     * Get the channels/topics this handler subscribes to.
     *
     * Examples:
     *   - ['orders.created'] - Single channel
     *   - ['orders.*'] - Pattern (Redis/RabbitMQ)
     *   - ['orders.created', 'orders.updated'] - Multiple channels
     *   - ['#'] or ['*'] - All messages (wildcard)
     *
     * @return array<string> List of channel patterns
     */
    public function getChannels(): array;

    /**
     * Get the handler name (used for registration and logging).
     *
     * Convention: Use PascalCase without 'Handler' suffix.
     * Example: 'SendOrderCreated', 'ProcessPayment', 'SyncInventory'
     *
     * @return string Handler name
     */
    public function getName(): string;

    /**
     * Get the preferred broker driver for this handler.
     *
     * Returns null to use the default broker from config.
     * Can be overridden via --driver option in CLI.
     *
     * @return string|null Broker driver (redis, rabbitmq, kafka) or null
     */
    public function getDriver(): ?string;

    /**
     * Get the consumer group ID (for Kafka).
     *
     * Used for Kafka consumer group coordination.
     * Multiple instances with the same group ID will share partitions.
     *
     * @return string|null Consumer group ID or null for default
     */
    public function getConsumerGroup(): ?string;

    /**
     * Get maximum retry attempts for failed messages.
     *
     * After max attempts, message is sent to Dead Letter Queue (if configured).
     *
     * @return int Maximum retry attempts (0 = no retry)
     */
    public function getMaxRetries(): int;

    /**
     * Get retry delay in milliseconds.
     *
     * Delay between retry attempts. Can implement exponential backoff.
     *
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in milliseconds
     */
    public function getRetryDelay(int $attempt): int;

    /**
     * Determine if the handler should process this message.
     *
     * Optional filter before handle() is called.
     * Useful for conditional processing based on message content.
     *
     * @param MessageInterface $message The incoming message
     * @return bool True to process, false to skip
     */
    public function shouldHandle(MessageInterface $message): bool;

    /**
     * Called when message processing fails after all retries.
     *
     * Use this for error reporting, alerting, or Dead Letter Queue routing.
     *
     * @param MessageInterface $message The failed message
     * @param \Throwable $exception The last exception
     * @param ConsumerContext $context Consumer context
     * @return void
     */
    public function onFailed(MessageInterface $message, \Throwable $exception, ConsumerContext $context): void;

    /**
     * Called when the consumer starts.
     *
     * Use for initialization, logging, or metric setup.
     *
     * @param ConsumerContext $context Consumer context
     * @return void
     */
    public function onStart(ConsumerContext $context): void;

    /**
     * Called when the consumer stops (graceful shutdown).
     *
     * Use for cleanup, metric flushing, or connection closing.
     *
     * @param ConsumerContext $context Consumer context
     * @return void
     */
    public function onStop(ConsumerContext $context): void;
}
