<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use Toporia\Framework\Routing\Contracts\{RouteCollectionInterface, UrlGeneratorInterface};
use Toporia\Framework\Http\Request;

/**
 * Class UrlGenerator
 *
 * URL Generator for generating URLs to routes, assets, and signed URLs.
 * Performance: O(1) for simple URLs, O(N) for route URLs where N = parameter count.
 * Memory: O(1) - caches previous URL only.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class UrlGenerator implements UrlGeneratorInterface
{
    /**
     * @var string Root URL (e.g., https://example.com)
     */
    private string $rootUrl;

    /**
     * @var string Forced scheme (http or https)
     */
    private ?string $forcedScheme = null;

    /**
     * @var string|null Previous URL for redirects
     */
    private ?string $previousUrl = null;

    /**
     * @var string Asset root URL
     */
    private string $assetRoot;

    /**
     * @var string Secret key for signing URLs
     */
    private string $secretKey;

    /**
     * @param RouteCollectionInterface $routes Route collection
     * @param Request $request Current request
     * @param string $secretKey Secret key for signing URLs
     */
    public function __construct(
        private RouteCollectionInterface $routes,
        private Request $request,
        string $secretKey = ''
    ) {
        // SECURITY: Require valid secret key for signed URLs - no fallback allowed
        if ($secretKey) {
            $this->secretKey = $secretKey;
        } else {
            $key = $_ENV['APP_KEY']
                ?? (function_exists('env') ? env('APP_KEY') : null)
                ?? getenv('APP_KEY');

            if (!$key || strlen($key) < 32) {
                throw new \RuntimeException(
                    'APP_KEY environment variable must be set with at least 32 characters for secure URL signing. ' .
                    'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }
            $this->secretKey = $key;
        }
        $this->initializeFromRequest();
    }

    /**
     * Initialize root URL from request.
     */
    private function initializeFromRequest(): void
    {
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->host();
        $this->rootUrl = "{$scheme}://{$host}";
        $this->assetRoot = $this->rootUrl;
    }

    /**
     * {@inheritdoc}
     */
    public function route(string $name, array $parameters = [], bool $absolute = true): string
    {
        $route = $this->routes->getByName($name);

        if ($route === null) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $uri = $this->bindParameters($route->getUri(), $parameters, $extraParams);

        // Add remaining parameters as query string
        if (!empty($extraParams)) {
            $uri .= '?' . http_build_query($extraParams);
        }

        return $absolute ? $this->to($uri, [], true) : $uri;
    }

    /**
     * {@inheritdoc}
     */
    public function to(string $path, array $query = [], bool $absolute = true): string
    {
        // Remove leading slash for consistency
        $path = ltrim($path, '/');

        // Add query parameters
        if (!empty($query)) {
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator . http_build_query($query);
        }

        if (!$absolute) {
            return '/' . $path;
        }

        $scheme = $this->forcedScheme ?? ($this->request->isSecure() ? 'https' : 'http');
        $root = str_replace(['http://', 'https://'], "{$scheme}://", $this->rootUrl);

        return rtrim($root, '/') . '/' . $path;
    }

    /**
     * {@inheritdoc}
     */
    public function asset(string $path, bool $absolute = false): string
    {
        // Remove leading slash
        $path = ltrim($path, '/');

        if (!$absolute) {
            return '/' . $path;
        }

        return rtrim($this->assetRoot, '/') . '/' . $path;
    }

    /**
     * {@inheritdoc}
     */
    public function secureAsset(string $path): string
    {
        $url = $this->asset($path, true);
        return str_replace('http://', 'https://', $url);
    }

    /**
     * {@inheritdoc}
     */
    public function signedRoute(string $name, array $parameters = [], ?int $expiration = null, bool $absolute = true): string
    {
        $url = $this->route($name, $parameters, $absolute);

        // Add expiration if provided
        if ($expiration !== null) {
            $parameters['expires'] = now()->getTimestamp() + $expiration;
        }

        // Generate signature
        $signature = $this->generateSignature($url, $parameters);

        // Add signature to URL
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'signature=' . $signature;
    }

    /**
     * {@inheritdoc}
     */
    public function temporarySignedRoute(string $name, int $expiration, array $parameters = [], bool $absolute = true): string
    {
        return $this->signedRoute($name, $parameters, $expiration, $absolute);
    }

    /**
     * {@inheritdoc}
     */
    public function hasValidSignature(string $url): bool
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        // Extract signature
        $signature = $query['signature'] ?? null;
        if ($signature === null) {
            return false;
        }

        // Remove signature from query for verification
        unset($query['signature']);

        // Check expiration
        if (isset($query['expires']) && now()->getTimestamp() > (int)$query['expires']) {
            return false;
        }

        // Rebuild URL without signature
        $urlWithoutSignature = ($parts['scheme'] ?? 'http') . '://' .
            ($parts['host'] ?? 'localhost') .
            ($parts['path'] ?? '/');

        if (!empty($query)) {
            $urlWithoutSignature .= '?' . http_build_query($query);
        }

        // Verify signature
        $expectedSignature = $this->generateSignature($urlWithoutSignature, $query);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * {@inheritdoc}
     */
    public function current(): string
    {
        return $this->to($this->request->path(), [], true);
    }

    /**
     * {@inheritdoc}
     */
    public function previous(?string $default = null): string
    {
        return $this->previousUrl ?? $this->request->header('referer') ?? $default ?? '/';
    }

    /**
     * {@inheritdoc}
     */
    public function setPreviousUrl(string $url): void
    {
        $this->previousUrl = $url;
    }

    /**
     * {@inheritdoc}
     */
    public function full(): string
    {
        $query = $this->request->query();
        return $this->to($this->request->path(), $query, true);
    }

    /**
     * {@inheritdoc}
     */
    public function setRootUrl(string $root): void
    {
        $this->rootUrl = rtrim($root, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function forceScheme(string $scheme): void
    {
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Invalid scheme [{$scheme}]. Must be 'http' or 'https'.");
        }

        $this->forcedScheme = $scheme;
    }

    /**
     * Set the asset root URL.
     *
     * @param string $root Asset root URL
     * @return void
     */
    public function setAssetRoot(string $root): void
    {
        $this->assetRoot = rtrim($root, '/');
    }

    /**
     * Bind parameters to route URI.
     *
     * @param string $uri Route URI with {param} placeholders
     * @param array<string, mixed> $parameters Parameters to bind
     * @param array<string, mixed> $extraParams Output: remaining parameters
     * @return string URI with parameters bound
     */
    private function bindParameters(string $uri, array $parameters, &$extraParams = []): string
    {
        $extraParams = $parameters;

        // Replace {param} with values
        $uri = preg_replace_callback('/\{(\w+)\??}/', function ($matches) use (&$extraParams) {
            $param = $matches[1];
            $optional = str_ends_with($matches[0], '?}');

            if (isset($extraParams[$param])) {
                $value = $extraParams[$param];
                unset($extraParams[$param]);
                return (string)$value;
            }

            if ($optional) {
                return '';
            }

            throw new \InvalidArgumentException("Missing required parameter [{$param}].");
        }, $uri);

        // Clean up double slashes from optional parameters
        $uri = preg_replace('#/+#', '/', $uri);
        $uri = rtrim($uri, '/') ?: '/';

        return $uri;
    }

    /**
     * Generate signature for URL.
     *
     * @param string $url URL to sign
     * @param array<string, mixed> $parameters Parameters
     * @return string Signature
     */
    private function generateSignature(string $url, array $parameters = []): string
    {
        // Sort parameters for consistent signature
        ksort($parameters);

        $payload = $url . json_encode($parameters);
        return hash_hmac('sha256', $payload, $this->secretKey);
    }
}
