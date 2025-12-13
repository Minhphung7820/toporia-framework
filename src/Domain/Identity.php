<?php

declare(strict_types=1);

namespace Toporia\Framework\Domain;

use Toporia\Framework\Domain\Contracts\ValueObjectInterface;

/**
 * Abstract Class Identity
 *
 * Base class for typed identity value objects in Domain-Driven Design.
 * Provides type safety for entity identifiers.
 *
 * Usage:
 * ```php
 * final class OrderId extends Identity
 * {
 *     public static function generate(): self
 *     {
 *         return new self(self::generateUuid());
 *     }
 * }
 *
 * final class UserId extends Identity
 * {
 *     // Can use integer IDs
 *     public static function fromInt(int $id): self
 *     {
 *         return new self((string) $id);
 *     }
 *
 *     public function toInt(): int
 *     {
 *         return (int) $this->value;
 *     }
 * }
 *
 * // Type-safe usage
 * function processOrder(OrderId $orderId, UserId $userId): void
 * {
 *     // Cannot accidentally pass UserId where OrderId expected
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Domain
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Identity implements ValueObjectInterface
{
    /**
     * @param string $value The identity value.
     */
    public function __construct(
        public readonly string $value
    ) {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('Identity value cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function equals(ValueObjectInterface $other): bool
    {
        if (!($other instanceof static)) {
            return false;
        }

        return $this->value === $other->value;
    }

    /**
     * {@inheritdoc}
     */
    public function hashCode(): string
    {
        return md5(static::class . ':' . $this->value);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    /**
     * Create from string value.
     *
     * @param string $value Identity value.
     * @return static New identity instance.
     */
    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /**
     * Generate a UUID v4.
     *
     * @return string UUID string.
     */
    protected static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Check if a string is a valid UUID.
     *
     * @param string $value Value to check.
     * @return bool True if valid UUID.
     */
    protected static function isValidUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
