<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

/**
 * JSON Response Interface
 *
 * Specialized interface for JSON responses with advanced serialization capabilities.
 * Provides Toporia-style JSON response functionality with performance optimizations.
 *
 * Features:
 * - Advanced JSON serialization with JsonSerializable support
 * - Configurable JSON encoding options
 * - JSONP callback support
 * - Content-Type negotiation
 * - Performance optimizations with caching
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 */
interface JsonResponseInterface extends ResponseInterface
{
    /**
     * Set the data to be JSON encoded.
     *
     * @param mixed $data Data to encode
     * @return $this
     */
    public function setData(mixed $data): static;

    /**
     * Get the data to be JSON encoded.
     *
     * @return mixed
     */
    public function getData(): mixed;

    /**
     * Set JSON encoding options.
     *
     * @param int $options JSON encoding options
     * @return $this
     */
    public function setEncodingOptions(int $options): static;

    /**
     * Get JSON encoding options.
     *
     * @return int
     */
    public function getEncodingOptions(): int;

    /**
     * Set JSONP callback name.
     *
     * @param string|null $callback Callback name
     * @return $this
     */
    public function setCallback(?string $callback): static;

    /**
     * Get JSONP callback name.
     *
     * @return string|null
     */
    public function getCallback(): ?string;

    /**
     * Convert data to JSON string with error handling.
     *
     * @return string
     * @throws \JsonException
     */
    public function toJson(): string;

    /**
     * Check if response contains valid JSON.
     *
     * @return bool
     */
    public function isValidJson(): bool;

    /**
     * Send the complete JSON response.
     *
     * @return void
     */
    public function sendResponse(): void;
}
