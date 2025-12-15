<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Broadcasting;

use Toporia\Framework\Queue\Job;
use Toporia\Framework\Events\Contracts\ShouldBroadcast;
use Toporia\Framework\Realtime\Broadcast;
use Toporia\Framework\Events\Contracts\BroadcastChannelInterface;

/**
 * Class BroadcastEvent
 *
 * Queue job for broadcasting events to realtime channels.
 * Automatically dispatched when an event implements ShouldBroadcast and ShouldQueue.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events
 * @since       2025-01-15
 */
final class BroadcastEvent extends Job
{
    protected int $maxAttempts = 3;
    protected ?int $retryAfter = 5;

    /**
     * Create a new broadcast event job.
     *
     * @param ShouldBroadcast $event Event to broadcast
     */
    public function __construct(
        private ShouldBroadcast $event
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // Check conditional broadcasting
        if (method_exists($this->event, 'broadcastIf') && !$this->event->broadcastIf()) {
            return;
        }

        $channels = $this->event->broadcastOn();
        $eventName = $this->event->broadcastAs() ?? $this->event->getName();
        $data = $this->event->broadcastWith();

        // If broadcastWith() returns empty array, serialize all public properties
        if (empty($data)) {
            $data = $this->getEventData();
        }

        // Normalize channels to array
        $channels = is_array($channels) ? $channels : [$channels];

        // Broadcast to each channel
        foreach ($channels as $channel) {
            $channelName = $this->extractChannelName($channel);

            $broadcaster = Broadcast::channel($channelName)
                ->event($eventName)
                ->with($data);

            // Determine if private or presence
            if ($this->isPrivateChannel($channel)) {
                $broadcaster = Broadcast::private($channelName);
            } elseif ($this->isPresenceChannel($channel)) {
                $broadcaster = Broadcast::presence($channelName);
            }

            // Send broadcast
            $broadcaster->event($eventName)->with($data)->now();
        }
    }

    /**
     * Extract channel name from channel object or string.
     *
     * @param string|BroadcastChannelInterface $channel
     * @return string
     */
    private function extractChannelName(string|BroadcastChannelInterface $channel): string
    {
        if (is_string($channel)) {
            return $channel;
        }

        return $channel->getName();
    }

    /**
     * Check if channel is private.
     *
     * @param string|BroadcastChannelInterface $channel
     * @return bool
     */
    private function isPrivateChannel(string|BroadcastChannelInterface $channel): bool
    {
        if ($channel instanceof BroadcastChannelInterface) {
            return $channel->isPrivate() && !$channel->isPresence();
        }

        return str_starts_with($channel, 'private-');
    }

    /**
     * Check if channel is presence.
     *
     * @param string|BroadcastChannelInterface $channel
     * @return bool
     */
    private function isPresenceChannel(string|BroadcastChannelInterface $channel): bool
    {
        if ($channel instanceof BroadcastChannelInterface) {
            return $channel->isPresence();
        }

        return str_starts_with($channel, 'presence-');
    }

    /**
     * Get event data by serializing public properties.
     *
     * @return array<string, mixed>
     */
    private function getEventData(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this->event);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $property->setAccessible(true);
                $value = $property->getValue($this->event);

                // Handle objects with toArray() method
                if (is_object($value) && method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                }

                $data[$property->getName()] = $value;
            }
        }

        return $data;
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Log broadcast failure
        if (function_exists('log_error')) {
            log_error('Event broadcast failed: ' . $exception->getMessage(), [
                'event' => get_class($this->event),
                'exception' => $exception,
            ]);
        }
    }
}
