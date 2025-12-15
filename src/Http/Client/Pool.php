<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Client;

use Toporia\Framework\Http\Contracts\HttpResponseInterface;
use Toporia\Framework\Http\Client\Exceptions\HttpClientException;

/**
 * Class Pool
 *
 * Handles concurrent HTTP requests using cURL multi handle.
 * Significantly improves performance when making multiple API calls.
 *
 * Performance Benefits:
 * - 10 sequential requests @ 200ms each = 2000ms total
 * - 10 concurrent requests @ 200ms each = ~200ms total (10x faster!)
 *
 * Usage:
 * ```php
 * $responses = Http::pool(fn($pool) => [
 *     $pool->get('https://api1.com'),
 *     $pool->get('https://api2.com'),
 *     $pool->get('https://api3.com'),
 * ]);
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Client
 * @since       2025-01-15
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Pool
{
    /**
     * Pending requests to execute.
     *
     * @var array<int, PendingRequest>
     */
    private array $requests = [];

    public function __construct(
        private RestClient $client
    ) {
    }

    /**
     * Add a GET request to the pool.
     *
     * @param string $url
     * @param array $query
     * @param array $headers
     * @return PendingRequest
     */
    public function get(string $url, array $query = [], array $headers = []): PendingRequest
    {
        $request = new PendingRequest($this->client, 'GET', $url, $query, $headers);
        $this->requests[] = $request;
        return $request;
    }

    /**
     * Add a POST request to the pool.
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return PendingRequest
     */
    public function post(string $url, mixed $data = null, array $headers = []): PendingRequest
    {
        $request = new PendingRequest($this->client, 'POST', $url, $data, $headers);
        $this->requests[] = $request;
        return $request;
    }

    /**
     * Add a PUT request to the pool.
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return PendingRequest
     */
    public function put(string $url, mixed $data = null, array $headers = []): PendingRequest
    {
        $request = new PendingRequest($this->client, 'PUT', $url, $data, $headers);
        $this->requests[] = $request;
        return $request;
    }

    /**
     * Add a PATCH request to the pool.
     *
     * @param string $url
     * @param mixed $data
     * @param array $headers
     * @return PendingRequest
     */
    public function patch(string $url, mixed $data = null, array $headers = []): PendingRequest
    {
        $request = new PendingRequest($this->client, 'PATCH', $url, $data, $headers);
        $this->requests[] = $request;
        return $request;
    }

    /**
     * Add a DELETE request to the pool.
     *
     * @param string $url
     * @param array $headers
     * @return PendingRequest
     */
    public function delete(string $url, array $headers = []): PendingRequest
    {
        $request = new PendingRequest($this->client, 'DELETE', $url, null, $headers);
        $this->requests[] = $request;
        return $request;
    }

    /**
     * Execute all requests concurrently using cURL multi handle.
     *
     * Performance: O(max(request_times)) instead of O(sum(request_times))
     *
     * @return array<int|string, HttpResponseInterface>
     * @throws HttpClientException
     */
    public function execute(): array
    {
        if (empty($this->requests)) {
            return [];
        }

        // Create multi handle
        $multiHandle = curl_multi_init();
        $handles = [];
        $requestMap = [];

        // Add all requests to multi handle
        foreach ($this->requests as $index => $request) {
            $handle = $this->createCurlHandle($request);
            $handles[$index] = $handle;
            $requestMap[(int) $handle] = $index;
            curl_multi_add_handle($multiHandle, $handle);
        }

        // Execute all requests concurrently
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect responses
        $responses = [];

        foreach ($handles as $index => $handle) {
            $response = curl_multi_getcontent($handle);
            $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

            if ($response === false) {
                $error = curl_error($handle);
                throw new HttpClientException("cURL error for request {$index}: {$error}");
            }

            // Parse response
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);
            $parsedHeaders = $this->parseHeaders($responseHeaders);

            $httpResponse = new HttpResponse(
                $statusCode,
                $responseBody,
                $parsedHeaders,
                []
            );

            // Use custom key if provided, otherwise use index
            $key = $this->requests[$index]->getKey() ?? $index;
            $responses[$key] = $httpResponse;

            // Clean up
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }

    /**
     * Create a cURL handle for a pending request.
     *
     * @param PendingRequest $request
     * @return resource|\CurlHandle
     */
    private function createCurlHandle(PendingRequest $request): mixed
    {
        $client = $request->getClient();
        $details = $request->getRequestDetails();

        // Build full URL
        $url = $this->buildUrl($client, $details['url'], $details['method'], $details['data']);

        // Initialize cURL
        $ch = curl_init();

        // Set URL
        curl_setopt($ch, CURLOPT_URL, $url);

        // Set method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $details['method']);

        // Return response
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Return headers
        curl_setopt($ch, CURLOPT_HEADER, true);

        // Timeout (access via reflection to get client's timeout)
        $timeout = $this->getClientTimeout($client);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // Follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // SSL verification
        $verifySsl = $this->getClientSslVerification($client);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        // Build headers
        $headers = $this->buildHeaders($client, $details['headers']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Set request body
        if ($details['data'] !== null && in_array($details['method'], ['POST', 'PUT', 'PATCH'])) {
            $body = $this->prepareBody($client, $details['data']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return $ch;
    }

    /**
     * Build full URL from client and relative URL.
     *
     * @param RestClient $client
     * @param string $url
     * @param string $method
     * @param mixed $data
     * @return string
     */
    private function buildUrl(RestClient $client, string $url, string $method, mixed $data): string
    {
        // Use reflection to access baseUrl
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $baseUrl = $baseUrlProperty->getValue($client);

        // Build full URL
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $fullUrl = $url;
        } else {
            if (empty($baseUrl)) {
                throw new HttpClientException('Base URL is required for relative URLs');
            }
            $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }

        // Add query parameters for GET requests
        if ($method === 'GET' && is_array($data) && !empty($data)) {
            $fullUrl .= '?' . http_build_query($data);
        }

        return $fullUrl;
    }

    /**
     * Get client timeout via reflection.
     *
     * @param RestClient $client
     * @return int
     */
    private function getClientTimeout(RestClient $client): int
    {
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('timeout');
        $property->setAccessible(true);
        return $property->getValue($client);
    }

    /**
     * Get client SSL verification setting via reflection.
     *
     * @param RestClient $client
     * @return bool
     */
    private function getClientSslVerification(RestClient $client): bool
    {
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('verifySsl');
        $property->setAccessible(true);
        return $property->getValue($client);
    }

    /**
     * Build headers from client.
     *
     * @param RestClient $client
     * @param array $additionalHeaders
     * @return array
     */
    private function buildHeaders(RestClient $client, array $additionalHeaders): array
    {
        $reflection = new \ReflectionClass($client);

        // Get base headers
        $headersProperty = $reflection->getProperty('headers');
        $headersProperty->setAccessible(true);
        $headers = $headersProperty->getValue($client);

        // Get content type
        $contentTypeProperty = $reflection->getProperty('contentType');
        $contentTypeProperty->setAccessible(true);
        $contentType = $contentTypeProperty->getValue($client);

        // Get accept
        $acceptProperty = $reflection->getProperty('accept');
        $acceptProperty->setAccessible(true);
        $accept = $acceptProperty->getValue($client);

        // Merge headers
        $headers = array_merge($headers, $additionalHeaders);

        // Add Content-Type
        if ($contentType !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $contentType;
        }

        // Add Accept
        if ($accept !== null && !isset($headers['Accept'])) {
            $headers['Accept'] = $accept;
        }

        // Convert to cURL format
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        return $curlHeaders;
    }

    /**
     * Prepare request body.
     *
     * @param RestClient $client
     * @param mixed $data
     * @return string
     */
    private function prepareBody(RestClient $client, mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        $reflection = new \ReflectionClass($client);
        $contentTypeProperty = $reflection->getProperty('contentType');
        $contentTypeProperty->setAccessible(true);
        $contentType = $contentTypeProperty->getValue($client);

        if ($contentType === 'application/json') {
            return json_encode($data);
        }

        if ($contentType === 'application/x-www-form-urlencoded') {
            return http_build_query($data);
        }

        // Default: JSON
        return json_encode($data);
    }

    /**
     * Parse response headers.
     *
     * @param string $headerString
     * @return array
     */
    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }
}
