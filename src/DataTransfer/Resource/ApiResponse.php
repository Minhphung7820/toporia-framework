<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Resource;

use JsonSerializable;
use Toporia\Framework\DataTransfer\Contracts\ResponseDTOInterface;
use Toporia\Framework\DataTransfer\Contracts\DTOInterface;

/**
 * Class ApiResponse
 *
 * Standardized API response envelope.
 * Provides consistent response structure across the API.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Resource
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ApiResponse implements ResponseDTOInterface
{
    /**
     * Whether the request was successful.
     *
     * @var bool
     */
    protected bool $success;

    /**
     * Response data.
     *
     * @var mixed
     */
    protected mixed $data;

    /**
     * Response message.
     *
     * @var string|null
     */
    protected ?string $message;

    /**
     * HTTP status code.
     *
     * @var int
     */
    protected int $statusCode;

    /**
     * Error details.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $errors;

    /**
     * Response metadata.
     *
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * HATEOAS links.
     *
     * @var array<string, string>
     */
    protected array $links = [];

    /**
     * Create new API response.
     *
     * @param bool $success
     * @param mixed $data
     * @param string|null $message
     * @param int $statusCode
     * @param array<string, mixed>|null $errors
     */
    public function __construct(
        bool $success,
        mixed $data = null,
        ?string $message = null,
        int $statusCode = 200,
        ?array $errors = null
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->errors = $errors;
    }

    /**
     * Create success response.
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $statusCode
     * @return static
     */
    public static function success(mixed $data = null, ?string $message = null, int $statusCode = 200): static
    {
        return new static(true, $data, $message, $statusCode);
    }

    /**
     * Create error response.
     *
     * @param string $message
     * @param int $statusCode
     * @param array<string, mixed>|null $errors
     * @return static
     */
    public static function error(string $message, int $statusCode = 400, ?array $errors = null): static
    {
        return new static(false, null, $message, $statusCode, $errors);
    }

    /**
     * Create created response (201).
     *
     * @param mixed $data
     * @param string|null $message
     * @return static
     */
    public static function created(mixed $data = null, ?string $message = 'Resource created successfully'): static
    {
        return static::success($data, $message, 201);
    }

    /**
     * Create no content response (204).
     *
     * @return static
     */
    public static function noContent(): static
    {
        return new static(true, null, null, 204);
    }

    /**
     * Create not found response (404).
     *
     * @param string $message
     * @return static
     */
    public static function notFound(string $message = 'Resource not found'): static
    {
        return static::error($message, 404);
    }

    /**
     * Create unauthorized response (401).
     *
     * @param string $message
     * @return static
     */
    public static function unauthorized(string $message = 'Unauthorized'): static
    {
        return static::error($message, 401);
    }

    /**
     * Create forbidden response (403).
     *
     * @param string $message
     * @return static
     */
    public static function forbidden(string $message = 'Forbidden'): static
    {
        return static::error($message, 403);
    }

    /**
     * Create validation error response (422).
     *
     * @param array<string, array<string>> $errors
     * @param string $message
     * @return static
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): static
    {
        return static::error($message, 422, $errors);
    }

    /**
     * Create server error response (500).
     *
     * @param string $message
     * @return static
     */
    public static function serverError(string $message = 'Internal server error'): static
    {
        return static::error($message, 500);
    }

    /**
     * Create paginated response.
     *
     * @param mixed $paginator
     * @param class-string<JsonResource>|null $resourceClass
     * @return static
     */
    public static function paginated(mixed $paginator, ?string $resourceClass = null): static
    {
        if ($resourceClass !== null) {
            $collection = $resourceClass::collection($paginator);
            $data = $collection->resolve();
        } else {
            $data = $paginator;
        }

        return static::success($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'api_response';
    }

    /**
     * {@inheritDoc}
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * {@inheritDoc}
     */
    public function withMeta(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->meta[$key] = $value;
        return $clone;
    }

    /**
     * Add multiple metadata at once.
     *
     * @param array<string, mixed> $meta
     * @return static
     */
    public function withMetaArray(array $meta): static
    {
        $clone = clone $this;
        $clone->meta = array_merge($clone->meta, $meta);
        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function getLinks(): array
    {
        return $this->links;
    }

    /**
     * {@inheritDoc}
     */
    public function withLink(string $rel, string $href): static
    {
        $clone = clone $this;
        $clone->links[$rel] = $href;
        return $clone;
    }

    /**
     * Add multiple links at once.
     *
     * @param array<string, string> $links
     * @return static
     */
    public function withLinks(array $links): static
    {
        $clone = clone $this;
        $clone->links = array_merge($clone->links, $links);
        return $clone;
    }

    /**
     * Set response message.
     *
     * @param string $message
     * @return static
     */
    public function withMessage(string $message): static
    {
        $clone = clone $this;
        $clone->message = $message;
        return $clone;
    }

    /**
     * Set HTTP status code.
     *
     * @param int $statusCode
     * @return static
     */
    public function withStatus(int $statusCode): static
    {
        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }

    /**
     * Get HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Check if response is successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get response data.
     *
     * @return mixed
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get response message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get errors.
     *
     * @return array<string, mixed>|null
     */
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        return new static(
            $data['success'] ?? true,
            $data['data'] ?? null,
            $data['message'] ?? null,
            $data['status_code'] ?? 200,
            $data['errors'] ?? null
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
        ];

        if ($this->data !== null) {
            $response['data'] = $this->resolveData();
        }

        if ($this->message !== null) {
            $response['message'] = $this->message;
        }

        if (!empty($this->meta)) {
            $response['meta'] = $this->meta;
        }

        if (!empty($this->links)) {
            $response['links'] = $this->links;
        }

        if ($this->errors !== null) {
            $response['errors'] = $this->errors;
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->toArray());
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->toArray()[$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Resolve data to array if needed.
     *
     * @return mixed
     */
    protected function resolveData(): mixed
    {
        if ($this->data instanceof JsonSerializable) {
            return $this->data->jsonSerialize();
        }

        if ($this->data instanceof JsonResource) {
            return $this->data->toArray();
        }

        if ($this->data instanceof ResourceCollection) {
            return $this->data->toArray();
        }

        return $this->data;
    }
}
