<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface MessageInterface
 *
 * Contract defining the interface for MessageInterface implementations in
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
interface MessageInterface
{
    /**
     * Get message unique identifier.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Get message type.
     *
     * Types: event, subscribe, unsubscribe, error, ping, pong, presence
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get target channel.
     *
     * @return string|null
     */
    public function getChannel(): ?string;

    /**
     * Get event name.
     *
     * Example: message.sent, user.joined, typing.started
     *
     * @return string|null
     */
    public function getEvent(): ?string;

    /**
     * Get message data/payload.
     *
     * @return mixed
     */
    public function getData(): mixed;

    /**
     * Get message timestamp.
     *
     * @return int Unix timestamp
     */
    public function getTimestamp(): int;

    /**
     * Convert message to JSON.
     *
     * @return string JSON string
     */
    public function toJson(): string;

    /**
     * Convert message to array.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Create message from JSON.
     *
     * @param string $json JSON string
     * @return static
     */
    public static function fromJson(string $json): static;

    /**
     * Create message from array.
     *
     * @param array $data Message data
     * @return static
     */
    public static function fromArray(array $data): static;
}
