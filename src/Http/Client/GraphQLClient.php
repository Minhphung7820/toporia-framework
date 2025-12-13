<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Client;

use Toporia\Framework\Http\Contracts\{HttpClientInterface, HttpResponseInterface};

/**
 * Class GraphQLClient
 *
 * Specialized client for GraphQL APIs.
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
final class GraphQLClient
{
    public function __construct(
        private HttpClientInterface $client
    ) {
    }

    /**
     * Execute GraphQL query
     *
     * @param string $query GraphQL query string
     * @param array $variables Query variables
     * @param string|null $operationName Operation name
     * @return HttpResponseInterface
     */
    public function query(string $query, array $variables = [], ?string $operationName = null): HttpResponseInterface
    {
        $payload = [
            'query' => $query,
        ];

        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        if ($operationName !== null) {
            $payload['operationName'] = $operationName;
        }

        return $this->client
            ->asJson()
            ->acceptJson()
            ->post('', $payload);
    }

    /**
     * Execute GraphQL mutation
     *
     * @param string $mutation GraphQL mutation string
     * @param array $variables Mutation variables
     * @param string|null $operationName Operation name
     * @return HttpResponseInterface
     */
    public function mutate(string $mutation, array $variables = [], ?string $operationName = null): HttpResponseInterface
    {
        return $this->query($mutation, $variables, $operationName);
    }

    /**
     * Set base URL
     *
     * @param string $url
     * @return self
     */
    public function withBaseUrl(string $url): self
    {
        $this->client = $this->client->withBaseUrl($url);
        return $this;
    }

    /**
     * Set authorization token
     *
     * @param string $token
     * @return self
     */
    public function withToken(string $token): self
    {
        $this->client = $this->client->withToken($token);
        return $this;
    }

    /**
     * Set custom headers
     *
     * @param array $headers
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        $this->client = $this->client->withHeaders($headers);
        return $this;
    }

    /**
     * Set timeout
     *
     * @param int $seconds
     * @return self
     */
    public function timeout(int $seconds): self
    {
        $this->client = $this->client->timeout($seconds);
        return $this;
    }
}
