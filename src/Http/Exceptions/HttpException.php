<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class HttpException
 *
 * Base class for all HTTP-related exceptions. Provides status code, headers, and message for HTTP error responses.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class HttpException extends \RuntimeException
{
    /**
     * @var int HTTP status code
     */
    protected int $statusCode;

    /**
     * @var array<string, string> HTTP headers
     */
    protected array $headers;

    /**
     * Create a new HTTP exception.
     *
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @param array<string, string> $headers Additional headers
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        int $statusCode = 500,
        string $message = '',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the HTTP headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set the HTTP headers.
     *
     * @param array<string, string> $headers
     * @return static
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    // =========================================================================
    // Factory Methods for Common HTTP Errors
    // =========================================================================

    /**
     * Create a 400 Bad Request exception.
     *
     * @param string $message
     * @return static
     */
    public static function badRequest(string $message = 'Bad Request'): static
    {
        return new static(400, $message);
    }

    /**
     * Create a 401 Unauthorized exception.
     *
     * @param string $message
     * @return static
     */
    public static function unauthorized(string $message = 'Unauthorized'): static
    {
        return new static(401, $message);
    }

    /**
     * Create a 403 Forbidden exception.
     *
     * @param string $message
     * @return static
     */
    public static function forbidden(string $message = 'Forbidden'): static
    {
        return new static(403, $message);
    }

    /**
     * Create a 404 Not Found exception.
     *
     * @param string $message
     * @return static
     */
    public static function notFound(string $message = 'Not Found'): static
    {
        return new static(404, $message);
    }

    /**
     * Create a 405 Method Not Allowed exception.
     *
     * @param array<string> $allowedMethods
     * @param string $message
     * @return static
     */
    public static function methodNotAllowed(array $allowedMethods = [], string $message = 'Method Not Allowed'): static
    {
        $headers = [];
        if (!empty($allowedMethods)) {
            $headers['Allow'] = implode(', ', $allowedMethods);
        }
        return new static(405, $message, $headers);
    }

    /**
     * Create a 409 Conflict exception.
     *
     * @param string $message
     * @return static
     */
    public static function conflict(string $message = 'Conflict'): static
    {
        return new static(409, $message);
    }

    /**
     * Create a 422 Unprocessable Entity exception.
     *
     * @param string $message
     * @return static
     */
    public static function unprocessableEntity(string $message = 'Unprocessable Entity'): static
    {
        return new static(422, $message);
    }

    /**
     * Create a 429 Too Many Requests exception.
     *
     * @param int|null $retryAfter Seconds until retry allowed
     * @param string $message
     * @return static
     */
    public static function tooManyRequests(?int $retryAfter = null, string $message = 'Too Many Requests'): static
    {
        $headers = [];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }
        return new static(429, $message, $headers);
    }

    /**
     * Create a 500 Internal Server Error exception.
     *
     * @param string $message
     * @return static
     */
    public static function serverError(string $message = 'Internal Server Error'): static
    {
        return new static(500, $message);
    }

    /**
     * Create a 503 Service Unavailable exception.
     *
     * @param int|null $retryAfter Seconds until service available
     * @param string $message
     * @return static
     */
    public static function serviceUnavailable(?int $retryAfter = null, string $message = 'Service Unavailable'): static
    {
        $headers = [];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }
        return new static(503, $message, $headers);
    }
}
