<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;


/**
 * Interface HttpClientInterface
 *
 * Contract defining the interface for HttpClientInterface implementations
 * in the HTTP request and response handling layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface HttpClientInterface
{
    /**
     * Send GET request
     *
     * @param string $url
     * @param array $query Query parameters
     * @param array $headers
     * @return HttpResponseInterface
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResponseInterface;

    /**
     * Send POST request
     *
     * @param string $url
     * @param mixed $data Request body
     * @param array $headers
     * @return HttpResponseInterface
     */
    public function post(string $url, mixed $data = null, array $headers = []): HttpResponseInterface;

    /**
     * Send PUT request
     *
     * @param string $url
     * @param mixed $data Request body
     * @param array $headers
     * @return HttpResponseInterface
     */
    public function put(string $url, mixed $data = null, array $headers = []): HttpResponseInterface;

    /**
     * Send PATCH request
     *
     * @param string $url
     * @param mixed $data Request body
     * @param array $headers
     * @return HttpResponseInterface
     */
    public function patch(string $url, mixed $data = null, array $headers = []): HttpResponseInterface;

    /**
     * Send DELETE request
     *
     * @param string $url
     * @param array $headers
     * @return HttpResponseInterface
     */
    public function delete(string $url, array $headers = []): HttpResponseInterface;

    /**
     * Set base URL for all requests
     *
     * @param string $baseUrl
     * @return self
     */
    public function withBaseUrl(string $baseUrl): self;

    /**
     * Set default headers for all requests
     *
     * @param array $headers
     * @return self
     */
    public function withHeaders(array $headers): self;

    /**
     * Set bearer token authentication
     *
     * @param string $token
     * @return self
     */
    public function withToken(string $token): self;

    /**
     * Set basic authentication
     *
     * @param string $username
     * @param string $password
     * @return self
     */
    public function withBasicAuth(string $username, string $password): self;

    /**
     * Set timeout in seconds
     *
     * @param int $seconds
     * @return self
     */
    public function timeout(int $seconds): self;

    /**
     * Set retry attempts
     *
     * @param int $times
     * @param int $sleep Sleep between retries in milliseconds
     * @return self
     */
    public function retry(int $times, int $sleep = 100): self;

    /**
     * Accept JSON response
     *
     * @return self
     */
    public function acceptJson(): self;

    /**
     * Send request as JSON
     *
     * @return self
     */
    public function asJson(): self;

    /**
     * Send request as form data
     *
     * @return self
     */
    public function asForm(): self;

    /**
     * Send request as multipart (file uploads)
     *
     * @return self
     */
    public function asMultipart(): self;
}
