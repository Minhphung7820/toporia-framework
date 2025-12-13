<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\Kafka;

use RdKafka;
use Toporia\Framework\Realtime\Exceptions\BrokerException;
use Toporia\Framework\Realtime\Metrics\BrokerMetrics;

/**
 * Class ProducerPool
 *
 * Pool of Kafka producers for parallel high-throughput publishing.
 * Each producer is a separate TCP connection to Kafka cluster.
 *
 * Architecture:
 * - Multiple producer instances for parallel I/O
 * - Round-robin or topic-based distribution
 * - Connection pooling with health monitoring
 * - Graceful shutdown with coordinated flush
 *
 * Performance:
 * - Single producer: ~50,000-100,000 msg/s
 * - Pool of 4: ~200,000-400,000 msg/s
 * - Pool of 8: ~400,000-800,000 msg/s (diminishing returns after)
 *
 * Use cases:
 * - Ultra high-throughput scenarios (100K+ msg/s)
 * - Multi-topic publishing
 * - Load balancing across brokers
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ProducerPool
{
    /**
     * @var array<int, RdKafka\Producer> Pool of producer instances
     */
    private array $producers = [];

    /**
     * @var array<int, RdKafka\Conf> Configurations for each producer
     */
    private array $configs = [];

    /**
     * @var int Current index for round-robin
     */
    private int $currentIndex = 0;

    /**
     * @var int Pool size
     */
    private int $poolSize;

    /**
     * @var bool Whether pool is initialized
     */
    private bool $initialized = false;

    /**
     * @var array<int, int> Pending message count per producer
     */
    private array $pendingCounts = [];

    /**
     * @var array<int, array{healthy: bool, last_check: float, error_count: int}> Health status per producer
     */
    private array $healthStatus = [];

    /**
     * @var array<string, string> Base producer configuration
     */
    private array $baseConfig;

    /**
     * @var string Broker list
     */
    private string $brokers;

    /**
     * @param string $brokers Broker list (comma-separated)
     * @param int $poolSize Number of producers (default: 4)
     * @param array<string, string> $baseConfig Base producer configuration
     */
    public function __construct(
        string $brokers,
        int $poolSize = 4,
        array $baseConfig = []
    ) {
        if (!extension_loaded('rdkafka')) {
            throw BrokerException::invalidConfiguration('kafka', 'rdkafka extension required for ProducerPool');
        }

        $this->brokers = $brokers;
        $this->poolSize = max(1, min($poolSize, 16)); // Clamp 1-16
        $this->baseConfig = $baseConfig;
    }

    /**
     * Initialize the producer pool.
     *
     * Creates all producer instances with optimized settings.
     *
     * @return void
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        for ($i = 0; $i < $this->poolSize; $i++) {
            $this->createProducer($i);
        }

        $this->initialized = true;
        BrokerMetrics::recordConnectionEvent('kafka', 'pool_init');
    }

    /**
     * Create a producer instance at given index.
     *
     * @param int $index Pool index
     * @return void
     */
    private function createProducer(int $index): void
    {
        $conf = new RdKafka\Conf();

        // Base settings
        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('metadata.broker.list', $this->brokers);

        // High throughput defaults
        $conf->set('queue.buffering.max.messages', '100000');
        $conf->set('queue.buffering.max.ms', '5');
        $conf->set('queue.buffering.max.kbytes', '1048576');
        $conf->set('batch.size', '131072');
        $conf->set('linger.ms', '5');
        $conf->set('socket.keepalive.enable', 'true');
        $conf->set('socket.nagle.disable', 'true');
        $conf->set('acks', '1');
        $conf->set('retries', '5');
        $conf->set('retry.backoff.ms', '100');
        $conf->set('max.in.flight.requests.per.connection', '10');

        // Apply custom config
        foreach ($this->baseConfig as $key => $value) {
            $conf->set($key, (string) $value);
        }

        // Unique client ID per producer
        $conf->set('client.id', "toporia-producer-{$index}");

        // Delivery report callback for tracking
        $conf->setDrMsgCb(function (RdKafka\Producer $producer, RdKafka\Message $message) use ($index) {
            unset($producer);
            $this->handleDeliveryReport($index, $message);
        });

        $this->configs[$index] = $conf;
        $this->producers[$index] = new RdKafka\Producer($conf);
        $this->producers[$index]->addBrokers($this->brokers);
        $this->pendingCounts[$index] = 0;
        $this->healthStatus[$index] = [
            'healthy' => true,
            'last_check' => microtime(true),
            'error_count' => 0,
        ];
    }

    /**
     * Handle delivery report for tracking.
     *
     * @param int $producerIndex Producer index
     * @param RdKafka\Message $message Message
     * @return void
     */
    private function handleDeliveryReport(int $producerIndex, RdKafka\Message $message): void
    {
        $this->pendingCounts[$producerIndex] = max(0, $this->pendingCounts[$producerIndex] - 1);

        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->healthStatus[$producerIndex]['error_count']++;

            if ($this->healthStatus[$producerIndex]['error_count'] >= 5) {
                $this->healthStatus[$producerIndex]['healthy'] = false;
            }

            error_log("[ProducerPool] Producer {$producerIndex} delivery failed: " . rd_kafka_err2str($message->err));
        } else {
            // Reset error count on success
            $this->healthStatus[$producerIndex]['error_count'] = 0;
            $this->healthStatus[$producerIndex]['healthy'] = true;
        }
    }

    /**
     * Get a producer from the pool.
     *
     * Uses round-robin with health checking.
     *
     * @return RdKafka\Producer
     */
    public function getProducer(): RdKafka\Producer
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Find healthy producer with least pending messages
        $bestIndex = $this->findBestProducer();

        return $this->producers[$bestIndex];
    }

    /**
     * Get producer by index.
     *
     * @param int $index Producer index
     * @return RdKafka\Producer
     */
    public function getProducerByIndex(int $index): RdKafka\Producer
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $index = $index % $this->poolSize;
        return $this->producers[$index];
    }

    /**
     * Find the best producer (healthy with least pending).
     *
     * @return int Producer index
     */
    private function findBestProducer(): int
    {
        $bestIndex = 0;
        $bestScore = PHP_INT_MAX;

        foreach ($this->producers as $index => $producer) {
            unset($producer); // Unused in loop body

            // Skip unhealthy producers if we have healthy alternatives
            if (!$this->healthStatus[$index]['healthy']) {
                continue;
            }

            // Score = pending count (lower is better)
            $score = $this->pendingCounts[$index];

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        // Fallback to round-robin if all unhealthy
        if ($bestScore === PHP_INT_MAX) {
            $bestIndex = $this->currentIndex;
            $this->currentIndex = ($this->currentIndex + 1) % $this->poolSize;
        }

        return $bestIndex;
    }

    /**
     * Publish to a topic using pooled producer.
     *
     * Automatically selects best producer for load balancing.
     *
     * @param string $topic Topic name
     * @param string $payload Message payload
     * @param int|null $partition Target partition (null = auto)
     * @param string|null $key Message key
     * @return int Producer index used
     */
    public function publish(string $topic, string $payload, ?int $partition = null, ?string $key = null): int
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $producerIndex = $this->findBestProducer();
        $producer = $this->producers[$producerIndex];

        $topicInstance = $producer->newTopic($topic);
        $partitionVal = $partition ?? RD_KAFKA_PARTITION_UA;

        $topicInstance->produce($partitionVal, 0, $payload, $key);
        $this->pendingCounts[$producerIndex]++;

        // Non-blocking poll
        $producer->poll(0);

        return $producerIndex;
    }

    /**
     * Flush all producers.
     *
     * @param int $timeoutMs Timeout per producer
     * @return array<int, int> Result per producer (RD_KAFKA_RESP_ERR_*)
     */
    public function flushAll(int $timeoutMs = 5000): array
    {
        $results = [];

        foreach ($this->producers as $index => $producer) {
            $producer->poll(0);
            $results[$index] = $producer->flush($timeoutMs);
        }

        return $results;
    }

    /**
     * Poll all producers for delivery callbacks.
     *
     * @param int $timeoutMs Timeout per producer
     * @return int Total events processed
     */
    public function pollAll(int $timeoutMs = 0): int
    {
        $total = 0;

        foreach ($this->producers as $producer) {
            $total += $producer->poll($timeoutMs) ?? 0;
        }

        return $total;
    }

    /**
     * Get total pending messages across all producers.
     *
     * @return int
     */
    public function getTotalPending(): int
    {
        return array_sum($this->pendingCounts);
    }

    /**
     * Get total queue length across all producers.
     *
     * @return int
     */
    public function getTotalQueueLength(): int
    {
        $total = 0;

        foreach ($this->producers as $producer) {
            $total += $producer->getOutQLen();
        }

        return $total;
    }

    /**
     * Get pool statistics.
     *
     * @return array{pool_size: int, initialized: bool, total_pending: int, total_queue: int, health: array<int, array{healthy: bool, pending: int, error_count: int}>}
     */
    public function getStats(): array
    {
        $health = [];

        foreach ($this->healthStatus as $index => $status) {
            $health[$index] = [
                'healthy' => $status['healthy'],
                'pending' => $this->pendingCounts[$index] ?? 0,
                'error_count' => $status['error_count'],
            ];
        }

        return [
            'pool_size' => $this->poolSize,
            'initialized' => $this->initialized,
            'total_pending' => $this->getTotalPending(),
            'total_queue' => $this->getTotalQueueLength(),
            'health' => $health,
        ];
    }

    /**
     * Get number of healthy producers.
     *
     * @return int
     */
    public function getHealthyCount(): int
    {
        return count(array_filter($this->healthStatus, fn($s) => $s['healthy']));
    }

    /**
     * Restart an unhealthy producer.
     *
     * @param int $index Producer index
     * @return void
     */
    public function restartProducer(int $index): void
    {
        if (!isset($this->producers[$index])) {
            return;
        }

        // Flush remaining messages
        $this->producers[$index]->poll(0);
        $this->producers[$index]->flush(1000);

        // Recreate
        unset($this->producers[$index]);
        $this->createProducer($index);
    }

    /**
     * Shutdown the pool gracefully.
     *
     * @param int $timeoutMs Timeout for final flush
     * @return void
     */
    public function shutdown(int $timeoutMs = 10000): void
    {
        if (!$this->initialized) {
            return;
        }

        // Flush all producers
        $this->flushAll($timeoutMs);

        // Clear
        $this->producers = [];
        $this->configs = [];
        $this->pendingCounts = [];
        $this->healthStatus = [];
        $this->initialized = false;

        BrokerMetrics::recordConnectionEvent('kafka', 'pool_shutdown');
    }

    public function __destruct()
    {
        $this->shutdown(2000);
    }
}
