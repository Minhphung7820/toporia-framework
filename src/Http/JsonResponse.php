<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Http\Contracts\JsonResponseInterface;
use Toporia\Framework\Http\Serialization\JsonSerializer;
use Toporia\Framework\Support\Macroable;

/**
 * Enterprise JSON Response
 *
 * Advanced JSON response implementation with Toporia compatibility and performance optimizations.
 *
 * Features:
 * - Advanced JSON serialization with JsonSerializer
 * - JSONP callback support
 * - Configurable encoding options
 * - Error handling and validation
 * - Performance optimizations
 * - Toporia-style API
 *
 * Performance Optimizations:
 * - Lazy JSON encoding (only when needed)
 * - Serialization caching
 * - Memory-efficient processing
 * - Optimized header management
 *
 * Clean Architecture:
 * - Single Responsibility: JSON response handling
 * - Open/Closed: Extensible via macros
 * - Dependency Inversion: Uses JsonSerializer abstraction
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 */
final class JsonResponse extends Response implements JsonResponseInterface
{
    use Macroable;

    /**
     * @var mixed Original data before JSON encoding
     */
    private mixed $data = null;

    /**
     * @var int JSON encoding options
     */
    private int $encodingOptions;

    /**
     * @var string|null JSONP callback name
     */
    private ?string $callback = null;

    /**
     * @var JsonSerializer JSON serializer instance
     */
    private JsonSerializer $serializer;

    /**
     * @var string|null Cached JSON content
     */
    private ?string $jsonCache = null;

    /**
     * @var bool Whether content has been modified since last cache
     */
    private bool $contentModified = true;

    /**
     * Create a new JSON response instance.
     *
     * @param mixed $data Data to be JSON encoded
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @param int $options JSON encoding options
     */
    public function __construct(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $options = 0
    ) {
        parent::__construct('', $status, $headers);

        $this->serializer = new JsonSerializer();
        $this->encodingOptions = $options ?: JsonSerializer::getDefaultOptions();

        $this->setData($data);
        $this->header('Content-Type', 'application/json');
    }

    /**
     * {@inheritdoc}
     */
    public function setData(mixed $data): static
    {
        $this->data = $data;
        $this->contentModified = true;
        $this->jsonCache = null;

        return $this;
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
    public function setEncodingOptions(int $options): static
    {
        if ($this->encodingOptions !== $options) {
            $this->encodingOptions = $options;
            $this->contentModified = true;
            $this->jsonCache = null;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getEncodingOptions(): int
    {
        return $this->encodingOptions;
    }

    /**
     * {@inheritdoc}
     */
    public function setCallback(?string $callback): static
    {
        if ($this->callback !== $callback) {
            $this->callback = $callback;
            $this->contentModified = true;
            $this->jsonCache = null;

            // Update content type for JSONP
            if ($callback !== null) {
                $this->header('Content-Type', 'application/javascript');
            } else {
                $this->header('Content-Type', 'application/json');
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCallback(): ?string
    {
        return $this->callback;
    }

    /**
     * {@inheritdoc}
     */
    public function toJson(): string
    {
        // Use cached JSON if available and not modified
        if (!$this->contentModified && $this->jsonCache !== null) {
            return $this->jsonCache;
        }

        try {
            $json = $this->serializer->serialize($this->data, $this->encodingOptions);

            // Cache the result for performance
            $this->jsonCache = $json;
            $this->contentModified = false;

            return $json;
        } catch (\JsonException $e) {
            // Fallback to error response
            $errorData = [
                'error' => 'JSON Encoding Error',
                'message' => $e->getMessage(),
                'data' => null
            ];

            return json_encode($errorData, JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isValidJson(): bool
    {
        try {
            $this->toJson();
            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Get the response content.
     *
     * @return string Response content
     */
    public function getContent(): string
    {
        $json = $this->toJson();

        // Handle JSONP callback
        if ($this->callback !== null) {
            // Sanitize callback name for security
            $callback = preg_replace('/[^a-zA-Z0-9_$.]/', '', $this->callback);
            return sprintf('%s(%s);', $callback, $json);
        }

        return $json;
    }

    /**
     * Send the response content.
     *
     * @return void
     */
    public function sendContent(): void
    {
        echo $this->getContent();
    }

    /**
     * Send the response with content (override parent method).
     *
     * @param string $content Content to send (required by parent)
     * @return void
     */
    public function send(string $content): void
    {
        // Set status code before sending (important for proper HTTP response)
        // This ensures the HTTP status code is set correctly via http_response_code()
        $this->setStatus($this->getStatusCode());

        // Ensure all headers are sent (header() method handles headersSent check)
        foreach ($this->getHeaders() as $name => $value) {
            $this->header($name, $value);
        }

        // Use parent's send method which handles content output
        parent::send($content ?: $this->getContent());
    }

    /**
     * Send the complete JSON response (convenience method).
     *
     * @return void
     */
    public function sendResponse(): void
    {
        $this->send($this->getContent());
    }

    /**
     * Set response content (overrides JSON data).
     *
     * @param string $content Response content
     * @return $this
     */
    public function setContent(string $content): static
    {
        // When content is set directly, clear data and cache
        $this->data = null;
        $this->jsonCache = $content;
        $this->contentModified = false;

        return $this;
    }

    /**
     * Create JSON response with success format.
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $status HTTP status code
     * @return static
     */
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): static
    {
        return new static([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }

    /**
     * Create JSON response with error format.
     *
     * @param string $message Error message
     * @param mixed $errors Error details
     * @param int $status HTTP status code
     * @return static
     */
    public static function error(string $message = 'Error', mixed $errors = null, int $status = 400): static
    {
        return new static([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $status);
    }

    /**
     * Create JSON response with pagination format.
     *
     * @param mixed $data Paginated data
     * @param array<string, mixed> $pagination Pagination metadata
     * @param string $message Response message
     * @return static
     */
    public static function paginated(mixed $data, array $pagination, string $message = 'Success'): static
    {
        return new static([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => $pagination
        ]);
    }

    /**
     * Create JSON response with collection format.
     *
     * @param mixed $collection Collection data
     * @param array<string, mixed> $meta Collection metadata
     * @return static
     */
    public static function collection(mixed $collection, array $meta = []): static
    {
        return new static([
            'data' => $collection,
            'meta' => $meta
        ]);
    }

    /**
     * Clean up resources when object is destroyed.
     */
    public function __destruct()
    {
        // Clear serializer cache for memory efficiency
        $this->serializer?->clearCache();
    }
}
