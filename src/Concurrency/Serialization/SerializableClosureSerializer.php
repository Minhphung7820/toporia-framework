<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Serialization;

use Closure;
use Toporia\Framework\Concurrency\Contracts\ClosureSerializerInterface;
use Toporia\Framework\Concurrency\Exceptions\SerializationException;
use Throwable;

/**
 * Serializable Closure Serializer
 *
 * Implementation using Toporia's own SerializableClosure class.
 * Provides secure closure serialization with support for:
 * - Use variables
 * - Static variables
 * - Arrow functions (fn())
 * - Traditional closures (function())
 *
 * Note: Closures using $this cannot be serialized. Use static closures.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class SerializableClosureSerializer implements ClosureSerializerInterface
{
    /**
     * Secret key for closure signing (optional security feature).
     */
    private ?string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey;

        // Configure SerializableClosure if secret key is provided
        if ($secretKey !== null) {
            SerializableClosure::setSecretKey($secretKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function serializeClosure(callable $closure): string
    {
        try {
            // Ensure we have a Closure
            if (!$closure instanceof Closure) {
                // If it's a callable but not a Closure, wrap it
                $closure = Closure::fromCallable($closure);
            }

            // Wrap the closure in SerializableClosure
            $wrapper = new SerializableClosure($closure);

            return serialize($wrapper);
        } catch (Throwable $e) {
            throw SerializationException::closureSerializationFailed($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unserializeClosure(string $payload): callable
    {
        try {
            /** @var SerializableClosure $wrapper */
            $wrapper = unserialize($payload);

            if (!$wrapper instanceof SerializableClosure) {
                throw SerializationException::invalidPayload(
                    'Expected SerializableClosure instance'
                );
            }

            return $wrapper->getClosure();
        } catch (SerializationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SerializationException::closureDeserializationFailed($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function serializeResult(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (Throwable $e) {
            throw SerializationException::resultSerializationFailed($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unserializeResult(string $payload): mixed
    {
        try {
            return unserialize($payload);
        } catch (Throwable $e) {
            throw SerializationException::resultDeserializationFailed($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * {@inheritdoc}
     */
    public function decode(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw SerializationException::invalidPayload('Invalid base64 encoding');
        }

        return $decoded;
    }
}
