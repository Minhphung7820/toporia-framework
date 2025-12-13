<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Consumer\Contracts;

use Toporia\Framework\Realtime\Contracts\MessageInterface;

/**
 * Interface BatchConsumerHandlerInterface
 *
 * High-throughput batch consumer handler for Kafka.
 * Processes messages in batches for optimal performance.
 *
 * Performance comparison:
 * - Single message handler: ~5,000-10,000 msg/s
 * - Batch handler (100 msg): ~50,000-100,000 msg/s
 *
 * Use cases:
 * - Bulk database inserts
 * - Batch API calls
 * - Aggregation/analytics
 * - High-volume event processing
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface BatchConsumerHandlerInterface extends ConsumerHandlerInterface
{
    /**
     * Handle a batch of messages.
     *
     * Process messages in bulk for maximum throughput.
     * Return array of failed message indices for retry.
     *
     * @param array<MessageInterface> $messages Batch of messages
     * @param ConsumerContext $context Consumer context
     * @return array<int> Indices of failed messages (empty = all success)
     */
    public function handleBatch(array $messages, ConsumerContext $context): array;

    /**
     * Get the preferred batch size.
     *
     * Larger batches = higher throughput but more memory.
     * Recommended: 100-1000 depending on message size.
     *
     * @return int Batch size (default: 100)
     */
    public function getBatchSize(): int;

    /**
     * Get the batch timeout in milliseconds.
     *
     * Maximum time to wait for batch to fill before processing.
     * Lower = better latency, Higher = better throughput.
     *
     * @return int Timeout in ms (default: 100)
     */
    public function getBatchTimeout(): int;

    /**
     * Whether to commit offsets per batch or per message.
     *
     * Per-batch commit: Higher throughput, risk of reprocessing on failure
     * Per-message commit: Lower throughput, exactly-once semantics
     *
     * @return bool True for per-batch commit (recommended for high throughput)
     */
    public function shouldCommitPerBatch(): bool;
}
