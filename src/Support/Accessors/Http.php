<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Http\Contracts\{ClientManagerInterface, HttpClientInterface, HttpResponseInterface};
use Toporia\Framework\Http\Client\GraphQLClient;

/**
 * Class Http
 *
 * HTTP Accessor - Static-like access to HTTP client services.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @method static HttpClientInterface client(?string $name = null)
 * @method static GraphQLClient graphql(?string $name = null)
 * @method static HttpResponseInterface get(string $url, array $query = [], array $headers = [])
 * @method static HttpResponseInterface post(string $url, mixed $data = null, array $headers = [])
 * @method static HttpResponseInterface put(string $url, mixed $data = null, array $headers = [])
 * @method static HttpResponseInterface patch(string $url, mixed $data = null, array $headers = [])
 * @method static HttpResponseInterface delete(string $url, array $headers = [])
 * @method static HttpClientInterface withBaseUrl(string $baseUrl)
 * @method static HttpClientInterface withHeaders(array $headers)
 * @method static HttpClientInterface withToken(string $token)
 * @method static HttpClientInterface withBasicAuth(string $username, string $password)
 * @method static HttpClientInterface timeout(int $seconds)
 * @method static HttpClientInterface retry(int $times, int $sleep = 100)
 * @method static HttpClientInterface acceptJson()
 * @method static HttpClientInterface asJson()
 * @method static HttpClientInterface asForm()
 * @method static HttpClientInterface asMultipart()
 */
final class Http extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return ClientManagerInterface::class;
    }
}
