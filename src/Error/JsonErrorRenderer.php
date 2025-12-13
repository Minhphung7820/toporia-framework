<?php

declare(strict_types=1);

namespace Toporia\Framework\Error;

use Toporia\Framework\Error\Contracts\ErrorRendererInterface;
use Toporia\Framework\Http\Exceptions\HttpException;
use Toporia\Framework\Http\ValidationException;
use Throwable;

/**
 * Class JsonErrorRenderer
 *
 * Renders exceptions as JSON responses for API requests.
 * Provides clean JSON format with stack trace in debug mode.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Error
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class JsonErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        private bool $debug = true
    ) {
        // Security: Force debug=false in production to prevent information disclosure
        // Use $_ENV directly since env() helper may not be loaded yet
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'local';
        if ($appEnv === 'production') {
            // Always disable debug in production (security)
            $this->debug = false;
        }
        // In non-production, respect the $debug parameter passed in
    }

    /**
     * {@inheritdoc}
     */
    public function render(Throwable $exception): void
    {
        http_response_code($this->getStatusCode($exception));
        header('Content-Type: application/json; charset=UTF-8');

        // Set custom headers from HttpException
        if ($exception instanceof HttpException) {
            foreach ($exception->getHeaders() as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo json_encode($this->formatException($exception), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Format exception as array.
     *
     * @param Throwable $exception
     * @return array
     */
    private function formatException(Throwable $exception): array
    {
        // Handle ValidationException specially
        if ($exception instanceof ValidationException) {
            return $exception->toArray();
        }

        // Handle HttpException
        if ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();
            $response = [
                'error' => [
                    'message' => $exception->getMessage() ?: $this->getDefaultMessage($statusCode),
                    'code' => $statusCode,
                ]
            ];

            if ($this->debug) {
                $response['error']['exception'] = get_class($exception);
                $response['error']['file'] = $exception->getFile();
                $response['error']['line'] = $exception->getLine();
                $response['error']['trace'] = $this->formatTrace($exception->getTrace());
            }

            return $response;
        }

        if ($this->debug) {
            return [
                'error' => [
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $this->formatTrace($exception->getTrace())
                ]
            ];
        }

        // Production: minimal information
        return [
            'error' => [
                'message' => 'Internal Server Error',
                'code' => 500
            ]
        ];
    }

    /**
     * Format stack trace.
     *
     * @param array $trace
     * @return array
     */
    private function formatTrace(array $trace): array
    {
        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }, $trace);
    }

    /**
     * Get HTTP status code for exception.
     *
     * @param Throwable $exception
     * @return int
     */
    private function getStatusCode(Throwable $exception): int
    {
        // HttpException has its own status code
        if ($exception instanceof HttpException) {
            return $exception->getStatusCode();
        }

        // ValidationException returns 422
        if ($exception instanceof ValidationException) {
            return 422;
        }

        // Use exception code if it's a valid HTTP status
        $code = $exception->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Get default message for HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    private function getDefaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Error',
        };
    }
}
