<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Class ArrayTransport
 *
 * Store emails in memory for testing without actually sending emails.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Transport
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ArrayTransport extends AbstractTransport
{
    /**
     * @var array<array{id: string, message: MessageInterface, timestamp: int}> Sent messages.
     */
    private static array $messages = [];

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'array';
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSend(MessageInterface $message): TransportResult
    {
        $messageId = uniqid('test_');

        self::$messages[] = [
            'id' => $messageId,
            'message' => $message,
            'timestamp' => now()->getTimestamp(),
        ];

        return TransportResult::success($messageId, [
            'stored_count' => count(self::$messages),
        ]);
    }

    /**
     * Get all sent messages.
     *
     * @return array<array{id: string, message: MessageInterface, timestamp: int}>
     */
    public static function getMessages(): array
    {
        return self::$messages;
    }

    /**
     * Get last sent message.
     *
     * @return array{id: string, message: MessageInterface, timestamp: int}|null
     */
    public static function getLastMessage(): ?array
    {
        if (empty(self::$messages)) {
            return null;
        }

        return self::$messages[array_key_last(self::$messages)];
    }

    /**
     * Get message count.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::$messages);
    }

    /**
     * Check if any message was sent to given email.
     *
     * @param string $email Email address.
     * @return bool
     */
    public static function hasSentTo(string $email): bool
    {
        foreach (self::$messages as $entry) {
            if (in_array($email, $entry['message']->getTo(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any message has given subject.
     *
     * @param string $subject Subject to search for.
     * @return bool
     */
    public static function hasSubject(string $subject): bool
    {
        foreach (self::$messages as $entry) {
            if ($entry['message']->getSubject() === $subject) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find messages by callback.
     *
     * @param callable(MessageInterface): bool $callback Filter callback.
     * @return array<MessageInterface>
     */
    public static function findMessages(callable $callback): array
    {
        $results = [];

        foreach (self::$messages as $entry) {
            if ($callback($entry['message'])) {
                $results[] = $entry['message'];
            }
        }

        return $results;
    }

    /**
     * Clear all stored messages.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$messages = [];
    }

    /**
     * Assert that a message was sent.
     *
     * @param callable(MessageInterface): bool $callback Assertion callback.
     * @throws \RuntimeException If no matching message found.
     */
    public static function assertSent(callable $callback): void
    {
        foreach (self::$messages as $entry) {
            if ($callback($entry['message'])) {
                return;
            }
        }

        throw new \RuntimeException('No matching message was sent.');
    }

    /**
     * Assert that no message was sent.
     *
     * @param callable(MessageInterface): bool|null $callback Optional filter callback.
     * @throws \RuntimeException If matching message found.
     */
    public static function assertNotSent(?callable $callback = null): void
    {
        if ($callback === null) {
            if (!empty(self::$messages)) {
                throw new \RuntimeException('Messages were sent when none were expected.');
            }
            return;
        }

        foreach (self::$messages as $entry) {
            if ($callback($entry['message'])) {
                throw new \RuntimeException('A matching message was sent.');
            }
        }
    }

    /**
     * Assert message count.
     *
     * @param int $expected Expected count.
     * @throws \RuntimeException If count doesn't match.
     */
    public static function assertCount(int $expected): void
    {
        $actual = count(self::$messages);

        if ($actual !== $expected) {
            throw new \RuntimeException("Expected {$expected} messages, got {$actual}.");
        }
    }
}
