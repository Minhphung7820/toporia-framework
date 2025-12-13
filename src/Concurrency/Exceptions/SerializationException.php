<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Serialization Exception
 *
 * Thrown when closure or result serialization/deserialization fails.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class SerializationException extends RuntimeException
{
    /**
     * Create exception for closure serialization failure.
     */
    public static function closureSerializationFailed(Throwable $previous): self
    {
        return new self(
            'Failed to serialize closure: ' . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * Create exception for closure deserialization failure.
     */
    public static function closureDeserializationFailed(Throwable $previous): self
    {
        return new self(
            'Failed to deserialize closure: ' . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * Create exception for result serialization failure.
     */
    public static function resultSerializationFailed(Throwable $previous): self
    {
        return new self(
            'Failed to serialize result: ' . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * Create exception for result deserialization failure.
     */
    public static function resultDeserializationFailed(Throwable $previous): self
    {
        return new self(
            'Failed to deserialize result: ' . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * Create exception for invalid payload.
     */
    public static function invalidPayload(string $reason): self
    {
        return new self('Invalid serialized payload: ' . $reason);
    }
}
