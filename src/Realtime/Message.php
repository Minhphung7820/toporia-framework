<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Contracts\MessageInterface;
use Toporia\Framework\DateTime\Chronos;

/**
 * Class Message
 *
 * Immutable message object for realtime communication.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Message implements MessageInterface
{
    private string $id;
    private int $timestamp;

    public function __construct(
        private readonly string $type,
        private readonly ?string $channel = null,
        private readonly ?string $event = null,
        private readonly mixed $data = null,
        ?string $id = null,
        ?int $timestamp = null
    ) {
        $this->id = $id ?? uniqid('msg_', true);
        // Use Chronos directly instead of now() helper for better standalone usage
        $this->timestamp = $timestamp ?? Chronos::now()->getTimestamp();
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getChannel(): ?string
    {
        return $this->channel;
    }

    /**
     * {@inheritdoc}
     */
    public function getEvent(): ?string
    {
        return $this->event;
    }

    /**
     * {@inheritdoc}
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * {@inheritdoc}
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type,
            'channel' => $this->channel,
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ], fn($value) => $value !== null);
    }

    /**
     * {@inheritdoc}
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return static::fromArray($data);
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data): static
    {
        return new static(
            type: $data['type'] ?? 'event',
            channel: $data['channel'] ?? null,
            event: $data['event'] ?? null,
            data: $data['data'] ?? null,
            id: $data['id'] ?? null,
            timestamp: $data['timestamp'] ?? null
        );
    }

    /**
     * Create event message.
     *
     * @param string|null $channel Channel name (null for direct connection messages)
     * @param string $event Event name
     * @param mixed $data Event data
     * @return static
     */
    public static function event(?string $channel, string $event, mixed $data): static
    {
        return new static('event', $channel, $event, $data);
    }

    /**
     * Create subscribe message.
     *
     * @param string $channel Channel name
     * @return static
     */
    public static function subscribe(string $channel): static
    {
        return new static('subscribe', $channel);
    }

    /**
     * Create unsubscribe message.
     *
     * @param string $channel Channel name
     * @return static
     */
    public static function unsubscribe(string $channel): static
    {
        return new static('unsubscribe', $channel);
    }

    /**
     * Create error message.
     *
     * @param string $error Error message
     * @param int $code Error code
     * @return static
     */
    public static function error(string $error, int $code = 0): static
    {
        return new static('error', null, null, [
            'message' => $error,
            'code' => $code
        ]);
    }

    /**
     * Create ping message.
     *
     * @return static
     */
    public static function ping(): static
    {
        return new static('ping');
    }

    /**
     * Create pong message.
     *
     * @return static
     */
    public static function pong(): static
    {
        return new static('pong');
    }
}
