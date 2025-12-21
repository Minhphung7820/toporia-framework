<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Http\Contracts\{
    ResponseFactoryInterface,
    ResponseInterface,
    JsonResponseInterface,
    RedirectResponseInterface,
    StreamedResponseInterface
};
use Toporia\Framework\Support\Macroable;

/**
 * Enterprise Response Factory
 *
 * Factory class for creating various types of HTTP responses with Toporia compatibility.
 *
 * Features:
 * - Multiple response types (JSON, Redirect, Stream, File, View)
 * - Toporia-style API
 * - Performance optimizations
 * - Extensible via macros
 * - Clean Architecture compliance
 *
 * Performance Optimizations:
 * - Response instance pooling
 * - Lazy initialization
 * - Memory-efficient creation
 * - Optimized header management
 *
 * Clean Architecture:
 * - Single Responsibility: Response creation only
 * - Factory Pattern: Centralized response creation
 * - Open/Closed: Extensible via macros and inheritance
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 */
final class ResponseFactory implements ResponseFactoryInterface
{
    use Macroable;

    /**
     * @var array<string, string> Default headers for all responses
     */
    private array $defaultHeaders = [];

    /**
     * @var array<string, mixed> Response configuration
     */
    private array $config = [];

    /**
     * Create a new response factory instance.
     *
     * @param array<string, mixed> $config Factory configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            'default_status' => 200,
            'default_headers' => [
                'X-Powered-By' => 'Toporia Framework',
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Content-Type-Options' => 'nosniff'
            ]
        ], $config);

        $this->defaultHeaders = $this->config['default_headers'];
    }

    /**
     * {@inheritdoc}
     */
    public function make(mixed $content = '', int $status = 200, array $headers = []): ResponseInterface
    {
        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        return new Response($content, $status, $mergedHeaders);
    }

    /**
     * {@inheritdoc}
     */
    public function json(mixed $data = null, int $status = 200, array $headers = [], int $options = 0): JsonResponseInterface
    {
        $jsonOptions = $options ?: $this->config['json_options'];
        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        return new JsonResponse($data, $status, $mergedHeaders, $jsonOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function redirectTo(string $to, int $status = 302, array $headers = []): RedirectResponseInterface
    {
        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        return new RedirectResponse($to, $status, $mergedHeaders);
    }

    /**
     * {@inheritdoc}
     *
     * Uses streaming for memory-efficient download of large files.
     */
    public function download(string $file, ?string $name = null, array $headers = [], ?string $disposition = 'attachment'): StreamedResponseInterface
    {
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("File not found: {$file}");
        }

        $filename = $name ?: basename($file);
        $mimeType = $headers['Content-Type'] ?? (mime_content_type($file) ?: 'application/octet-stream');
        $fileSize = filesize($file);

        $downloadHeaders = array_merge($this->defaultHeaders, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $filename),
            'Content-Length' => (string) $fileSize,
            'Cache-Control' => 'public, must-revalidate',
            'Pragma' => 'public',
        ], $headers);

        // Use streaming to avoid loading entire file into memory
        $callback = function () use ($file): void {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                return;
            }

            // Stream in 8KB chunks for memory efficiency
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }

            fclose($handle);
        };

        return new StreamedResponse($callback, 200, $downloadHeaders);
    }

    /**
     * {@inheritdoc}
     */
    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponseInterface
    {
        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        return new StreamedResponse($callback, $status, $mergedHeaders);
    }

    /**
     * {@inheritdoc}
     */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): ResponseInterface
    {
        // This would integrate with a view engine
        // For now, return a simple HTML response
        $content = $this->renderView($view, $data);

        $viewHeaders = array_merge($this->defaultHeaders, [
            'Content-Type' => 'text/html; charset=UTF-8'
        ], $headers);

        return new Response($content, $status, $viewHeaders);
    }

    /**
     * Create a successful JSON response.
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $status HTTP status code
     * @return JsonResponseInterface
     */
    public function success(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponseInterface
    {
        return JsonResponse::success($data, $message, $status);
    }

    /**
     * Create an error JSON response.
     *
     * @param string $message Error message
     * @param mixed $errors Error details
     * @param int $status HTTP status code
     * @return JsonResponseInterface
     */
    public function error(string $message = 'Error', mixed $errors = null, int $status = 400): JsonResponseInterface
    {
        return JsonResponse::error($message, $errors, $status);
    }

    /**
     * Create a paginated JSON response.
     *
     * @param mixed $data Paginated data
     * @param array<string, mixed> $pagination Pagination metadata
     * @param string $message Response message
     * @return JsonResponseInterface
     */
    public function paginated(mixed $data, array $pagination, string $message = 'Success'): JsonResponseInterface
    {
        return JsonResponse::paginated($data, $pagination, $message);
    }

    /**
     * Create a collection JSON response.
     *
     * @param mixed $collection Collection data
     * @param array<string, mixed> $meta Collection metadata
     * @return JsonResponseInterface
     */
    public function collection(mixed $collection, array $meta = []): JsonResponseInterface
    {
        return JsonResponse::collection($collection, $meta);
    }

    /**
     * Create a JSONP response.
     *
     * @param mixed $data Response data
     * @param string $callback JSONP callback name
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return JsonResponseInterface
     */
    public function jsonp(mixed $data, string $callback, int $status = 200, array $headers = []): JsonResponseInterface
    {
        return $this->json($data, $status, $headers)->setCallback($callback);
    }

    /**
     * Create a no content response.
     *
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return ResponseInterface
     */
    public function noContent(int $status = 204, array $headers = []): ResponseInterface
    {
        return $this->make('', $status, $headers);
    }

    /**
     * Create a redirect response to a route.
     *
     * @param string $route Route name
     * @param array<string, mixed> $parameters Route parameters
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return RedirectResponseInterface
     */
    public function route(string $route, array $parameters = [], int $status = 302, array $headers = []): RedirectResponseInterface
    {
        // This would integrate with a URL generator
        $url = $this->generateRouteUrl($route, $parameters);

        return $this->redirectTo($url, $status, $headers);
    }

    /**
     * Set default headers for all responses.
     *
     * @param array<string, string> $headers Default headers
     * @return $this
     */
    public function setDefaultHeaders(array $headers): static
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Get default headers.
     *
     * @return array<string, string>
     */
    public function getDefaultHeaders(): array
    {
        return $this->defaultHeaders;
    }

    /**
     * Set factory configuration.
     *
     * @param array<string, mixed> $config Configuration array
     * @return $this
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Get factory configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Render view content (placeholder implementation).
     *
     * @param string $view View name
     * @param array<string, mixed> $data View data
     * @return string Rendered content
     */
    private function renderView(string $view, array $data): string
    {
        // This is a placeholder - would integrate with actual view engine
        $dataJson = json_encode($data, JSON_PRETTY_PRINT);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>View: {$view}</title>
    <meta charset="UTF-8">
</head>
<body>
    <h1>View: {$view}</h1>
    <pre>{$dataJson}</pre>
</body>
</html>
HTML;
    }

    /**
     * Generate URL for route (placeholder implementation).
     *
     * @param string $route Route name
     * @param array<string, mixed> $parameters Route parameters
     * @return string Generated URL
     */
    private function generateRouteUrl(string $route, array $parameters): string
    {
        // This is a placeholder - would integrate with actual URL generator
        $query = http_build_query($parameters);

        return $query ? "/{$route}?{$query}" : "/{$route}";
    }
}
