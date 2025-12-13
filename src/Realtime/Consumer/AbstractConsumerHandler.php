<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer;

use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerHandlerInterface;
use Toporia\Framework\Realtime\Contracts\MessageInterface;
use Toporia\Framework\Support\Accessors\Log;

/**
 * Class AbstractConsumerHandler
 *
 * Base class for broker consumer handlers.
 * Provides default implementations and utility methods.
 *
 * Example:
 * ```php
 * class SendOrderCreatedHandler extends AbstractConsumerHandler
 * {
 *     protected array $channels = ['orders.created'];
 *
 *     public function handle(MessageInterface $message, ConsumerContext $context): void
 *     {
 *         $data = $message->getData();
 *         // Process order...
 *         Mail::to($data['email'])->send(new OrderConfirmation($data));
 *     }
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Consumer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractConsumerHandler implements ConsumerHandlerInterface
{
    /**
     * Channels/topics this handler subscribes to.
     * Override in subclass or use setChannels().
     *
     * @var array<string>
     */
    protected array $channels = ['*'];

    /**
     * Preferred broker driver (null = use default).
     *
     * @var string|null
     */
    protected ?string $driver = null;

    /**
     * Consumer group ID for Kafka.
     *
     * @var string|null
     */
    protected ?string $consumerGroup = null;

    /**
     * Maximum retry attempts.
     *
     * @var int
     */
    protected int $maxRetries = 3;

    /**
     * Base retry delay in milliseconds.
     *
     * @var int
     */
    protected int $retryDelay = 1000;

    /**
     * Use exponential backoff for retries.
     *
     * @var bool
     */
    protected bool $exponentialBackoff = true;

    /**
     * {@inheritdoc}
     */
    abstract public function handle(MessageInterface $message, ConsumerContext $context): void;

    /**
     * {@inheritdoc}
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Set the channels to subscribe.
     *
     * @param array<string> $channels
     * @return static
     */
    public function setChannels(array $channels): static
    {
        $this->channels = $channels;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();

        // Remove 'Handler' suffix if present
        if (str_ends_with($className, 'Handler')) {
            $className = substr($className, 0, -7);
        }

        return $className;
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): ?string
    {
        return $this->driver;
    }

    /**
     * Set the preferred broker driver.
     *
     * @param string|null $driver
     * @return static
     */
    public function setDriver(?string $driver): static
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getConsumerGroup(): ?string
    {
        return $this->consumerGroup ?? 'consumer-' . strtolower($this->getName());
    }

    /**
     * Set the consumer group ID.
     *
     * @param string|null $consumerGroup
     * @return static
     */
    public function setConsumerGroup(?string $consumerGroup): static
    {
        $this->consumerGroup = $consumerGroup;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Set maximum retry attempts.
     *
     * @param int $maxRetries
     * @return static
     */
    public function setMaxRetries(int $maxRetries): static
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getRetryDelay(int $attempt): int
    {
        if (!$this->exponentialBackoff) {
            return $this->retryDelay;
        }

        // Exponential backoff: delay * 2^(attempt-1)
        // Attempt 1: 1000ms, Attempt 2: 2000ms, Attempt 3: 4000ms
        return (int) ($this->retryDelay * pow(2, $attempt - 1));
    }

    /**
     * Set base retry delay.
     *
     * @param int $delay Delay in milliseconds
     * @param bool $exponential Use exponential backoff
     * @return static
     */
    public function setRetryDelay(int $delay, bool $exponential = true): static
    {
        $this->retryDelay = $delay;
        $this->exponentialBackoff = $exponential;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldHandle(MessageInterface $message): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onFailed(MessageInterface $message, \Throwable $exception, ConsumerContext $context): void
    {
        Log::error("[Consumer:{$context->handlerName}] Message failed after {$context->attempt} attempts", [
            'handler' => $context->handlerName,
            'driver' => $context->driver,
            'channel' => $message->getChannel(),
            'event' => $message->getEvent(),
            'message_id' => $message->getId(),
            'error' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'attempts' => $context->attempt,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function onStart(ConsumerContext $context): void
    {
        Log::info("[Consumer:{$context->handlerName}] Started", [
            'handler' => $context->handlerName,
            'driver' => $context->driver,
            'channels' => $this->getChannels(),
            'process_id' => $context->processId,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function onStop(ConsumerContext $context): void
    {
        Log::info("[Consumer:{$context->handlerName}] Stopped", [
            'handler' => $context->handlerName,
            'driver' => $context->driver,
            'messages_processed' => $context->messageCount,
            'errors' => $context->errorCount,
            'duration_seconds' => round($context->getDuration(), 2),
            'throughput_msg_per_sec' => round($context->getThroughput(), 2),
        ]);
    }

    /**
     * Log a debug message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    protected function debug(string $message, array $context = []): void
    {
        Log::debug("[Consumer:{$this->getName()}] {$message}", $context);
    }

    /**
     * Log an info message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    protected function info(string $message, array $context = []): void
    {
        Log::info("[Consumer:{$this->getName()}] {$message}", $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    protected function warning(string $message, array $context = []): void
    {
        Log::warning("[Consumer:{$this->getName()}] {$message}", $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    protected function error(string $message, array $context = []): void
    {
        Log::error("[Consumer:{$this->getName()}] {$message}", $context);
    }

    /**
     * Get message data as array.
     *
     * @param MessageInterface $message
     * @return array<string, mixed>
     */
    protected function getData(MessageInterface $message): array
    {
        $data = $message->getData();
        return is_array($data) ? $data : [];
    }

    /**
     * Get a specific key from message data.
     *
     * @param MessageInterface $message
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get(MessageInterface $message, string $key, mixed $default = null): mixed
    {
        $data = $this->getData($message);
        return $data[$key] ?? $default;
    }
}
