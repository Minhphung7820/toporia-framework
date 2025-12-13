<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

/**
 * Response Factory Interface
 *
 * Provides a clean interface for creating various types of HTTP responses.
 * Follows Toporia's ResponseFactory pattern for maximum compatibility.
 *
 * Clean Architecture:
 * - Interface Segregation: Focused on response creation only
 * - Dependency Inversion: Depend on abstraction, not concrete implementation
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 */
interface ResponseFactoryInterface
{
    /**
     * Create a new response instance.
     *
     * @param mixed $content Response content
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return ResponseInterface
     */
    public function make(mixed $content = '', int $status = 200, array $headers = []): ResponseInterface;

    /**
     * Create a new JSON response instance.
     *
     * @param mixed $data Data to be JSON encoded
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @param int $options JSON encoding options
     * @return JsonResponseInterface
     */
    public function json(mixed $data = null, int $status = 200, array $headers = [], int $options = 0): JsonResponseInterface;

    /**
     * Create a new redirect response instance.
     *
     * @param string $to Redirect URL
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return RedirectResponseInterface
     */
    public function redirectTo(string $to, int $status = 302, array $headers = []): RedirectResponseInterface;

    /**
     * Create a new file download response.
     *
     * @param string $file File path
     * @param string|null $name Download filename
     * @param array<string, string> $headers Response headers
     * @param string|null $disposition Content disposition
     * @return ResponseInterface
     */
    public function download(string $file, ?string $name = null, array $headers = [], ?string $disposition = 'attachment'): ResponseInterface;

    /**
     * Create a new streamed response instance.
     *
     * @param callable $callback Stream callback
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return StreamedResponseInterface
     */
    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponseInterface;

    /**
     * Create a new view response instance.
     *
     * @param string $view View name
     * @param array<string, mixed> $data View data
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return ResponseInterface
     */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): ResponseInterface;
}
