<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;

use Toporia\Framework\Realtime\Contracts\{BrokerInterface, ConnectionInterface, MessageInterface, TransportInterface};
use Toporia\Framework\Realtime\Message;


/**
 * Trait InteractsWithRealtime
 *
 * Trait providing reusable functionality for InteractsWithRealtime in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithRealtime
{
    /**
     * Fake broker (disable real broker).
     */
    protected bool $fakeBroker = false;

    /**
     * Fake transport (disable real transport).
     */
    protected bool $fakeTransport = false;

    /**
     * Published messages to broker.
     *
     * @var array<array{channel: string, message: MessageInterface}>
     */
    protected array $publishedMessages = [];

    /**
     * Broadcasted messages via transport.
     *
     * @var array<array{channel: string|null, message: MessageInterface}>
     */
    protected array $broadcastedMessages = [];

    /**
     * Mock connections.
     *
     * @var array<string, mixed>
     */
    protected array $mockConnections = [];

    /**
     * Mock channels.
     *
     * @var array<string, array>
     */
    protected array $mockChannels = [];

    /**
     * Fake broker.
     *
     * Performance: O(1)
     */
    protected function fakeBroker(): void
    {
        $this->fakeBroker = true;
        $this->publishedMessages = [];
    }

    /**
     * Fake transport.
     *
     * Performance: O(1)
     */
    protected function fakeTransport(): void
    {
        $this->fakeTransport = true;
        $this->broadcastedMessages = [];
    }

    /**
     * Fake both broker and transport.
     *
     * Performance: O(1)
     */
    protected function fakeRealtime(): void
    {
        $this->fakeBroker();
        $this->fakeTransport();
    }

    /**
     * Assert that a message was published to broker.
     *
     * Performance: O(N) where N = number of published messages
     */
    protected function assertMessagePublished(string $channel, string $event = null, mixed $data = null): void
    {
        $found = false;

        foreach ($this->publishedMessages as $published) {
            if ($published['channel'] === $channel) {
                $message = $published['message'];

                if ($event === null || $message->getEvent() === $event) {
                    if ($data === null || $message->getData() === $data) {
                        $found = true;
                        break;
                    }
                }
            }
        }

        $this->assertTrue($found, "Message was not published to channel: {$channel}");
    }

    /**
     * Assert that a message was not published to broker.
     *
     * Performance: O(N) where N = number of published messages
     */
    protected function assertMessageNotPublished(string $channel): void
    {
        $found = false;

        foreach ($this->publishedMessages as $published) {
            if ($published['channel'] === $channel) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Message was unexpectedly published to channel: {$channel}");
    }

    /**
     * Assert that a message was broadcasted via transport.
     *
     * Performance: O(N) where N = number of broadcasted messages
     */
    protected function assertMessageBroadcasted(string $channel = null, string $event = null, mixed $data = null): void
    {
        $found = false;

        foreach ($this->broadcastedMessages as $broadcasted) {
            $broadcastChannel = $broadcasted['channel'];
            $message = $broadcasted['message'];

            if ($channel === null || $broadcastChannel === $channel) {
                if ($event === null || $message->getEvent() === $event) {
                    if ($data === null || $message->getData() === $data) {
                        $found = true;
                        break;
                    }
                }
            }
        }

        $channelInfo = $channel ? " to channel: {$channel}" : '';
        $this->assertTrue($found, "Message was not broadcasted{$channelInfo}");
    }

    /**
     * Assert that a message was not broadcasted via transport.
     *
     * Performance: O(N) where N = number of broadcasted messages
     */
    protected function assertMessageNotBroadcasted(string $channel = null): void
    {
        $found = false;

        foreach ($this->broadcastedMessages as $broadcasted) {
            if ($channel === null || $broadcasted['channel'] === $channel) {
                $found = true;
                break;
            }
        }

        $channelInfo = $channel ? " to channel: {$channel}" : '';
        $this->assertFalse($found, "Message was unexpectedly broadcasted{$channelInfo}");
    }

    /**
     * Record a published message.
     *
     * Performance: O(1)
     */
    protected function recordPublishedMessage(string $channel, MessageInterface $message): void
    {
        $this->publishedMessages[] = [
            'channel' => $channel,
            'message' => $message,
        ];
    }

    /**
     * Record a broadcasted message.
     *
     * Performance: O(1)
     */
    protected function recordBroadcastedMessage(?string $channel, MessageInterface $message): void
    {
        $this->broadcastedMessages[] = [
            'channel' => $channel,
            'message' => $message,
        ];
    }

    /**
     * Create a mock broker.
     *
     * Performance: O(1)
     */
    protected function mockBroker(): BrokerInterface
    {
        $test = $this;
        $fakeBroker = &$this->fakeBroker;
        $recordPublished = function ($channel, $message) use ($test) {
            $test->recordPublishedMessage($channel, $message);
        };

        return new class($fakeBroker, $recordPublished) implements BrokerInterface {
            private $fakeBroker;
            private $recordPublished;

            public function __construct(&$fakeBroker, $recordPublished)
            {
                $this->fakeBroker = &$fakeBroker;
                $this->recordPublished = $recordPublished;
            }

            public function publish(string $channel, MessageInterface $message): void
            {
                if ($this->fakeBroker) {
                    ($this->recordPublished)($channel, $message);
                }
            }

            public function subscribe(string $channel, callable $callback): void
            {
                // Mock subscription
            }

            public function unsubscribe(string $channel): void
            {
                // Mock unsubscription
            }

            public function getSubscriberCount(string $channel): int
            {
                return 0;
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function disconnect(): void
            {
                // Mock disconnect
            }

            public function getName(): string
            {
                return 'mock';
            }
        };
    }

    /**
     * Create a mock transport.
     *
     * Performance: O(1)
     */
    protected function mockTransport(): TransportInterface
    {
        $test = $this;
        $fakeTransport = &$this->fakeTransport;
        $mockConnections = &$this->mockConnections;
        $recordBroadcasted = function ($channel, $message) use ($test) {
            $test->recordBroadcastedMessage($channel, $message);
        };

        return new class($fakeTransport, $mockConnections, $recordBroadcasted) implements TransportInterface {
            private $fakeTransport;
            private $mockConnections;
            private $recordBroadcasted;

            public function __construct(&$fakeTransport, &$mockConnections, $recordBroadcasted)
            {
                $this->fakeTransport = &$fakeTransport;
                $this->mockConnections = &$mockConnections;
                $this->recordBroadcasted = $recordBroadcasted;
            }

            public function send(ConnectionInterface $connection, MessageInterface $message): void
            {
                if ($this->fakeTransport) {
                    ($this->recordBroadcasted)(null, $message);
                }
            }

            public function broadcast(MessageInterface $message): void
            {
                if ($this->fakeTransport) {
                    ($this->recordBroadcasted)(null, $message);
                }
            }

            public function broadcastToChannel(string $channel, MessageInterface $message): void
            {
                if ($this->fakeTransport) {
                    ($this->recordBroadcasted)($channel, $message);
                }
            }

            public function getConnectionCount(): int
            {
                return count($this->mockConnections);
            }

            public function hasConnection(string $connectionId): bool
            {
                return isset($this->mockConnections[$connectionId]);
            }

            public function close(ConnectionInterface $connection, int $code = 1000, string $reason = ''): void
            {
                // Mock close
            }

            public function start(string $host, int $port): void
            {
                // Mock start
            }

            public function stop(): void
            {
                // Mock stop
            }

            public function getName(): string
            {
                return 'mock';
            }
        };
    }

    /**
     * Create a test message.
     *
     * Performance: O(1)
     */
    protected function createRealtimeMessage(string $channel, string $event, mixed $data): MessageInterface
    {
        return Message::event($channel, $event, $data);
    }

    /**
     * Assert published message count.
     *
     * Performance: O(1)
     */
    protected function assertPublishedMessageCount(int $expected, string $channel = null): void
    {
        $count = 0;
        foreach ($this->publishedMessages as $published) {
            if ($channel === null || $published['channel'] === $channel) {
                $count++;
            }
        }

        $channelInfo = $channel ? " to channel: {$channel}" : '';
        $this->assertEquals($expected, $count, "Expected {$expected} published messages{$channelInfo}, found {$count}");
    }

    /**
     * Assert broadcasted message count.
     *
     * Performance: O(1)
     */
    protected function assertBroadcastedMessageCount(int $expected, string $channel = null): void
    {
        $count = 0;
        foreach ($this->broadcastedMessages as $broadcasted) {
            if ($channel === null || $broadcasted['channel'] === $channel) {
                $count++;
            }
        }

        $channelInfo = $channel ? " to channel: {$channel}" : '';
        $this->assertEquals($expected, $count, "Expected {$expected} broadcasted messages{$channelInfo}, found {$count}");
    }

    /**
     * Cleanup realtime after test.
     */
    protected function tearDownRealtime(): void
    {
        $this->publishedMessages = [];
        $this->broadcastedMessages = [];
        $this->mockConnections = [];
        $this->mockChannels = [];
        $this->fakeBroker = false;
        $this->fakeTransport = false;
    }
}
