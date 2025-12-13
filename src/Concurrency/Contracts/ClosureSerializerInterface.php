<?php

declare(strict_types=1);

namespace Toporia\Framework\Concurrency\Contracts;

/**
 * Closure Serializer Interface
 *
 * Abstraction for closure serialization/deserialization.
 * This allows swapping the underlying serialization library
 * (e.g., toporia/serializable-closure, opis/closure) without
 * affecting the rest of the concurrency system.
 *
 * @author      Toporia Framework
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
interface ClosureSerializerInterface
{
    /**
     * Serialize a closure for transmission to another process.
     *
     * @param callable $closure The closure to serialize
     * @return string Serialized representation
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\SerializationException
     */
    public function serializeClosure(callable $closure): string;

    /**
     * Unserialize a closure from its serialized form.
     *
     * @param string $payload The serialized closure
     * @return callable The restored closure
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\SerializationException
     */
    public function unserializeClosure(string $payload): callable;

    /**
     * Serialize a result value for transmission.
     *
     * @param mixed $value The value to serialize
     * @return string Serialized representation
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\SerializationException
     */
    public function serializeResult(mixed $value): string;

    /**
     * Unserialize a result value.
     *
     * @param string $payload The serialized result
     * @return mixed The restored value
     *
     * @throws \Toporia\Framework\Concurrency\Exceptions\SerializationException
     */
    public function unserializeResult(string $payload): mixed;

    /**
     * Encode serialized data for safe environment variable transmission.
     *
     * @param string $data Raw serialized data
     * @return string Encoded data (base64)
     */
    public function encode(string $data): string;

    /**
     * Decode data from environment variable format.
     *
     * @param string $encoded Encoded data
     * @return string Raw serialized data
     */
    public function decode(string $encoded): string;
}
