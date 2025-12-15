<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Client;

use Toporia\Framework\Http\Contracts\{HttpClientInterface, HttpResponseInterface};

/**
 * Class PendingRequest
 *
 * Represents a pending HTTP request that can be executed later.
 * Used for concurrent/async request pooling.
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
final class PendingRequest
{
    private string $method;
    private string $url;
    private mixed $data;
    private array $headers;
    private HttpClientInterface $client;
    private ?string $key = null;

    public function __construct(
        HttpClientInterface $client,
        string $method,
        string $url,
        mixed $data = null,
        array $headers = []
    ) {
        $this->client = $client;
        $this->method = $method;
        $this->url = $url;
        $this->data = $data;
        $this->headers = $headers;
    }

    /**
     * Set a key for this request (used in pool results).
     *
     * @param string $key
     * @return self
     */
    public function as(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Get the request key.
     *
     * @return string|null
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Execute the request.
     *
     * @return HttpResponseInterface
     */
    public function execute(): HttpResponseInterface
    {
        return match ($this->method) {
            'GET' => $this->client->get($this->url, $this->data ?? [], $this->headers),
            'POST' => $this->client->post($this->url, $this->data, $this->headers),
            'PUT' => $this->client->put($this->url, $this->data, $this->headers),
            'PATCH' => $this->client->patch($this->url, $this->data, $this->headers),
            'DELETE' => $this->client->delete($this->url, $this->headers),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$this->method}"),
        };
    }

    /**
     * Get the underlying client.
     *
     * @return HttpClientInterface
     */
    public function getClient(): HttpClientInterface
    {
        return $this->client;
    }

    /**
     * Get request details for cURL multi handle.
     *
     * @return array{method: string, url: string, data: mixed, headers: array}
     */
    public function getRequestDetails(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'data' => $this->data,
            'headers' => $this->headers,
        ];
    }
}
