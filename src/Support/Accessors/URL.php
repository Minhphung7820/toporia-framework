<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Routing\Contracts\UrlGeneratorInterface;

/**
 * Class URL
 *
 * URL Service Accessor - Provides static-like access to the URL generator.
 * All methods are automatically delegated to the underlying service via __callStatic().
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
 * @method static string to(string $path, array $query = [], bool $absolute = true) Generate URL to path
 * @method static string route(string $name, array $parameters = [], bool $absolute = true) Generate URL to named route
 * @method static string asset(string $path, bool $absolute = false) Generate asset URL
 * @method static string secureAsset(string $path) Generate secure asset URL (HTTPS)
 * @method static string signedRoute(string $name, array $parameters = [], ?int $expiration = null, bool $absolute = true) Generate signed route URL
 * @method static string temporarySignedRoute(string $name, int $expiration, array $parameters = [], bool $absolute = true) Generate temporary signed route URL
 * @method static bool hasValidSignature(string $url) Verify signed URL
 * @method static string current() Get current URL
 * @method static string previous(?string $default = null) Get previous URL
 * @method static string full() Get full URL with query string
 * @method static void setRootUrl(string $root) Set root URL
 * @method static void forceScheme(string $scheme) Force URL scheme
 *
 * @see UrlGeneratorInterface
 *
 * @example
 * $url = URL::to('/products');
 * $route = URL::route('product.show', ['id' => 1]);
 * $asset = URL::asset('css/app.css');
 * $signed = URL::signedRoute('unsubscribe', ['email' => $email], 3600);
 */
final class URL extends ServiceAccessor
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
        return 'url';
    }
}
