<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Client;

use Toporia\Framework\Http\Contracts\{ClientManagerInterface, HttpClientInterface, HttpResponseInterface};
use Toporia\Framework\Http\Client\Exceptions\HttpClientException;

/**
 * Class ClientManager
 *
 * Factory for creating and managing HTTP clients with different configurations.
 * Supports multiple protocols: REST, GraphQL, etc.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Client
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ClientManager implements ClientManagerInterface
{
    /**
     * @var array<string, HttpClientInterface>
     */
    private array $clients = [];

    /**
     * @var array<string, GraphQLClient>
     */
    private array $graphqlClients = [];

    /**
     * @param array $config Client configurations
     */
    public function __construct(
        private array $config = []
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function client(?string $name = null): HttpClientInterface
    {
        $name = $name ?? $this->getDefaultClient();

        if (!isset($this->clients[$name])) {
            $this->clients[$name] = $this->createClient($name);
        }

        return $this->clients[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function graphql(?string $name = null): GraphQLClient
    {
        $name = $name ?? $this->getDefaultClient();

        if (!isset($this->graphqlClients[$name])) {
            $httpClient = $this->client($name);
            $this->graphqlClients[$name] = new GraphQLClient($httpClient);
        }

        return $this->graphqlClients[$name];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultClient(): string
    {
        return $this->config['default'] ?? 'default';
    }

    /**
     * Create HTTP client instance
     *
     * @param string $name
     * @return HttpClientInterface
     */
    private function createClient(string $name): HttpClientInterface
    {
        $clientConfig = $this->config['clients'][$name] ?? [];

        if (empty($clientConfig)) {
            throw new HttpClientException("HTTP client '{$name}' not configured");
        }

        $driver = $clientConfig['driver'] ?? 'rest';

        $client = match ($driver) {
            'rest' => $this->createRestClient($clientConfig),
            default => throw new HttpClientException("Unsupported HTTP client driver: {$driver}")
        };

        return $client;
    }

    /**
     * Create REST client
     *
     * @param array $config
     * @return HttpClientInterface
     */
    private function createRestClient(array $config): HttpClientInterface
    {
        $client = new RestClient();

        // Base URL
        if (isset($config['base_url'])) {
            $client = $client->withBaseUrl($config['base_url']);
        }

        // Headers
        if (isset($config['headers'])) {
            $client = $client->withHeaders($config['headers']);
        }

        // Timeout
        if (isset($config['timeout'])) {
            $client = $client->timeout($config['timeout']);
        }

        // Retry
        if (isset($config['retry'])) {
            $client = $client->retry(
                $config['retry']['times'] ?? 0,
                $config['retry']['sleep'] ?? 100
            );
        }

        // Authentication
        if (isset($config['auth']['type'])) {
            if ($config['auth']['type'] === 'bearer') {
                $client = $client->withToken($config['auth']['token']);
            } elseif ($config['auth']['type'] === 'basic') {
                $client = $client->withBasicAuth(
                    $config['auth']['username'],
                    $config['auth']['password']
                );
            }
        }

        return $client;
    }

    // Delegate methods to default client

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResponseInterface
    {
        return $this->client()->get($url, $query, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $url, mixed $data = null, array $headers = []): HttpResponseInterface
    {
        return $this->client()->post($url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $url, mixed $data = null, array $headers = []): HttpResponseInterface
    {
        return $this->client()->put($url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function patch(string $url, mixed $data = null, array $headers = []): HttpResponseInterface
    {
        return $this->client()->patch($url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $url, array $headers = []): HttpResponseInterface
    {
        return $this->client()->delete($url, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function withBaseUrl(string $baseUrl): HttpClientInterface
    {
        return $this->client()->withBaseUrl($baseUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function withHeaders(array $headers): HttpClientInterface
    {
        return $this->client()->withHeaders($headers);
    }

    /**
     * {@inheritdoc}
     */
    public function withToken(string $token): HttpClientInterface
    {
        return $this->client()->withToken($token);
    }

    /**
     * {@inheritdoc}
     */
    public function withBasicAuth(string $username, string $password): HttpClientInterface
    {
        return $this->client()->withBasicAuth($username, $password);
    }

    /**
     * {@inheritdoc}
     */
    public function timeout(int $seconds): HttpClientInterface
    {
        return $this->client()->timeout($seconds);
    }

    /**
     * {@inheritdoc}
     */
    public function retry(int $times, int $sleep = 100): HttpClientInterface
    {
        return $this->client()->retry($times, $sleep);
    }

    /**
     * {@inheritdoc}
     */
    public function acceptJson(): HttpClientInterface
    {
        return $this->client()->acceptJson();
    }

    /**
     * {@inheritdoc}
     */
    public function asJson(): HttpClientInterface
    {
        return $this->client()->asJson();
    }

    /**
     * {@inheritdoc}
     */
    public function asForm(): HttpClientInterface
    {
        return $this->client()->asForm();
    }

    /**
     * {@inheritdoc}
     */
    public function asMultipart(): HttpClientInterface
    {
        return $this->client()->asMultipart();
    }

    /**
     * Execute multiple HTTP requests concurrently.
     *
     * Significantly improves performance when making multiple API calls by running them in parallel
     * using cURL multi handle instead of sequential execution.
     *
     * Performance Example:
     * - Sequential: 10 requests @ 200ms each = 2000ms total
     * - Concurrent: 10 requests @ 200ms each = ~200ms total (10x faster!)
     *
     * Usage:
     * ```php
     * $responses = Http::pool(fn($pool) => [
     *     $pool->get('https://api1.com/users')->as('users'),
     *     $pool->get('https://api2.com/posts')->as('posts'),
     *     $pool->post('https://api3.com/logs', ['event' => 'click'])->as('log'),
     * ]);
     *
     * $users = $responses['users']->json();
     * $posts = $responses['posts']->json();
     * ```
     *
     * @param callable $callback Function that receives Pool instance and returns array of PendingRequest
     * @param string|null $clientName Optional client name to use (default: default client)
     * @return array<int|string, HttpResponseInterface> Array of responses indexed by request key or position
     */
    public function pool(callable $callback, ?string $clientName = null): array
    {
        $client = $this->client($clientName);

        if (!$client instanceof RestClient) {
            throw new HttpClientException('Pool only supports RestClient instances');
        }

        $pool = new Pool($client);
        $requests = $callback($pool);

        // If callback returns array of requests, execute them
        if (is_array($requests)) {
            return $pool->execute();
        }

        // Otherwise, execute the pool directly
        return $pool->execute();
    }
}
