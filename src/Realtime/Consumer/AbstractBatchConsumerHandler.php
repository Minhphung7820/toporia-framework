<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer;

use Toporia\Framework\Realtime\Consumer\Contracts\BatchConsumerHandlerInterface;
use Toporia\Framework\Realtime\Consumer\Contracts\ConsumerContext;
use Toporia\Framework\Realtime\Contracts\MessageInterface;
use Toporia\Framework\Support\Accessors\Log;

/**
 * Class AbstractBatchConsumerHandler
 *
 * High-throughput batch consumer handler base class.
 * Extend this class for bulk processing operations.
 *
 * Example:
 * ```php
 * class BulkOrderProcessor extends AbstractBatchConsumerHandler
 * {
 *     protected array $channels = ['orders.*'];
 *     protected int $batchSize = 500;
 *
 *     public function handleBatch(array $messages, ConsumerContext $context): array
 *     {
 *         $orders = array_map(fn($m) => $m->getData(), $messages);
 *
 *         // Bulk insert - much faster than single inserts
 *         Order::insert($orders);
 *
 *         return []; // All success
 *     }
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
abstract class AbstractBatchConsumerHandler extends AbstractConsumerHandler implements BatchConsumerHandlerInterface
{
    /**
     * Batch size for processing.
     *
     * @var int
     */
    protected int $batchSize = 100;

    /**
     * Batch timeout in milliseconds.
     *
     * @var int
     */
    protected int $batchTimeout = 100;

    /**
     * Whether to commit per batch.
     *
     * @var bool
     */
    protected bool $commitPerBatch = true;

    /**
     * {@inheritdoc}
     */
    abstract public function handleBatch(array $messages, ConsumerContext $context): array;

    /**
     * {@inheritdoc}
     *
     * Default implementation: process single message via batch handler.
     * Override if you need different behavior for single messages.
     */
    public function handle(MessageInterface $message, ConsumerContext $context): void
    {
        $failed = $this->handleBatch([$message], $context);

        if (!empty($failed)) {
            throw new \RuntimeException('Message processing failed in batch handler');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Set batch size.
     *
     * @param int $size
     * @return static
     */
    public function setBatchSize(int $size): static
    {
        $this->batchSize = max(1, min($size, 10000)); // Clamp 1-10000
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getBatchTimeout(): int
    {
        return $this->batchTimeout;
    }

    /**
     * Set batch timeout.
     *
     * @param int $timeout Timeout in milliseconds
     * @return static
     */
    public function setBatchTimeout(int $timeout): static
    {
        $this->batchTimeout = max(10, min($timeout, 60000)); // Clamp 10ms-60s
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function shouldCommitPerBatch(): bool
    {
        return $this->commitPerBatch;
    }

    /**
     * Set commit strategy.
     *
     * @param bool $perBatch True for per-batch, false for per-message
     * @return static
     */
    public function setCommitPerBatch(bool $perBatch): static
    {
        $this->commitPerBatch = $perBatch;
        return $this;
    }

    /**
     * Called when batch processing fails.
     *
     * @param array<MessageInterface> $messages All messages in batch
     * @param array<int> $failedIndices Indices of failed messages
     * @param \Throwable $exception Exception that occurred
     * @param ConsumerContext $context Consumer context
     * @return void
     */
    public function onBatchFailed(
        array $messages,
        array $failedIndices,
        \Throwable $exception,
        ConsumerContext $context
    ): void {
        $failedCount = count($failedIndices);
        $totalCount = count($messages);

        Log::error("[BatchConsumer:{$context->handlerName}] Batch failed", [
            'handler' => $context->handlerName,
            'driver' => $context->driver,
            'total_messages' => $totalCount,
            'failed_count' => $failedCount,
            'failed_indices' => array_slice($failedIndices, 0, 10), // First 10 only
            'error' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * Called when batch processing succeeds.
     *
     * @param array<MessageInterface> $messages All messages in batch
     * @param ConsumerContext $context Consumer context
     * @param float $durationMs Processing time in milliseconds
     * @return void
     */
    public function onBatchSuccess(array $messages, ConsumerContext $context, float $durationMs): void
    {
        $count = count($messages);
        $throughput = $durationMs > 0 ? round($count / ($durationMs / 1000), 2) : 0;

        Log::debug("[BatchConsumer:{$context->handlerName}] Batch processed", [
            'handler' => $context->handlerName,
            'messages' => $count,
            'duration_ms' => round($durationMs, 2),
            'throughput_msg_s' => $throughput,
        ]);
    }

    /**
     * Pre-process batch before handling.
     *
     * Override to filter/transform messages before batch processing.
     *
     * @param array<MessageInterface> $messages
     * @return array<MessageInterface>
     */
    protected function preProcessBatch(array $messages): array
    {
        return array_filter($messages, fn($m) => $this->shouldHandle($m));
    }

    /**
     * Extract data from batch of messages.
     *
     * Utility method for common batch operations.
     *
     * @param array<MessageInterface> $messages
     * @return array<array<string, mixed>>
     */
    protected function extractData(array $messages): array
    {
        return array_map(fn($m) => $this->getData($m), $messages);
    }

    /**
     * Chunk a batch into smaller sub-batches.
     *
     * Useful for splitting large batches for parallel processing.
     *
     * @param array<MessageInterface> $messages
     * @param int $chunkSize
     * @return array<array<MessageInterface>>
     */
    protected function chunkBatch(array $messages, int $chunkSize): array
    {
        return array_chunk($messages, $chunkSize);
    }

    /**
     * Group messages by a key.
     *
     * Useful for batch processing by category/type.
     *
     * @param array<MessageInterface> $messages
     * @param string $key Data key to group by
     * @return array<string, array<MessageInterface>>
     */
    protected function groupByKey(array $messages, string $key): array
    {
        $groups = [];

        foreach ($messages as $message) {
            $data = $message->getData();
            $groupKey = is_array($data) ? ($data[$key] ?? 'unknown') : 'unknown';
            $groups[(string) $groupKey][] = $message;
        }

        return $groups;
    }
}
