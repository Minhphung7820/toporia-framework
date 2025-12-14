<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning;

use Toporia\Framework\Http\Request;
use Toporia\Framework\ApiVersioning\Contracts\VersionResolverInterface;

/**
 * Class ApiVersion
 *
 * Central manager for API versioning.
 * Handles version resolution, validation, and deprecation.
 *
 * Features:
 * - Multiple resolution strategies (header, path, query, accept header)
 * - Version validation and normalization
 * - Deprecation warnings
 * - Version-specific routing
 *
 * Usage:
 *   // Get current version
 *   $version = ApiVersion::current(); // 'v1'
 *
 *   // Check version
 *   if (ApiVersion::is('v2')) { ... }
 *   if (ApiVersion::isAtLeast('v2')) { ... }
 *
 *   // Route with version
 *   $router->group(['version' => 'v1'], function ($router) {
 *       $router->get('/users', [UserController::class, 'index']);
 *   });
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class ApiVersion
{
    /**
     * Current API version.
     */
    private static ?string $currentVersion = null;

    /**
     * Default API version.
     */
    private static string $defaultVersion = 'v1';

    /**
     * Supported versions (newest first).
     *
     * @var array<string>
     */
    private static array $supportedVersions = ['v1'];

    /**
     * Deprecated versions with sunset dates.
     *
     * @var array<string, string> version => sunset date
     */
    private static array $deprecatedVersions = [];

    /**
     * Registered version resolvers.
     *
     * @var array<VersionResolverInterface>
     */
    private static array $resolvers = [];

    /**
     * Whether resolvers are sorted.
     */
    private static bool $resolversSorted = false;

    /**
     * Cached resolution results.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    /**
     * Version format pattern.
     */
    private static string $versionPattern = '/^v\d+$/';

    /**
     * Get current API version.
     *
     * @return string
     */
    public static function current(): string
    {
        return self::$currentVersion ?? self::$defaultVersion;
    }

    /**
     * Set current API version.
     *
     * @param string $version
     * @return void
     */
    public static function set(string $version): void
    {
        self::$currentVersion = self::normalize($version);
    }

    /**
     * Check if current version matches given version.
     *
     * @param string $version
     * @return bool
     */
    public static function is(string $version): bool
    {
        return self::current() === self::normalize($version);
    }

    /**
     * Check if current version is at least given version.
     *
     * @param string $minVersion
     * @return bool
     */
    public static function isAtLeast(string $minVersion): bool
    {
        return self::compare(self::current(), $minVersion) >= 0;
    }

    /**
     * Check if current version is at most given version.
     *
     * @param string $maxVersion
     * @return bool
     */
    public static function isAtMost(string $maxVersion): bool
    {
        return self::compare(self::current(), $maxVersion) <= 0;
    }

    /**
     * Check if current version is between two versions (inclusive).
     *
     * @param string $minVersion
     * @param string $maxVersion
     * @return bool
     */
    public static function isBetween(string $minVersion, string $maxVersion): bool
    {
        return self::isAtLeast($minVersion) && self::isAtMost($maxVersion);
    }

    /**
     * Resolve version from request.
     *
     * @param Request $request
     * @return string
     */
    public static function resolveFromRequest(Request $request): string
    {
        // Check cache
        $cacheKey = self::getCacheKey($request);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Sort resolvers
        if (!self::$resolversSorted && !empty(self::$resolvers)) {
            usort(self::$resolvers, fn($a, $b) => $b->priority() <=> $a->priority());
            self::$resolversSorted = true;
        }

        // Try resolvers
        foreach (self::$resolvers as $resolver) {
            $version = $resolver->resolve($request);
            if ($version !== null) {
                $normalized = self::normalize($version);
                if (self::isSupported($normalized)) {
                    self::$cache[$cacheKey] = $normalized;
                    return $normalized;
                }
            }
        }

        // Fallback to default
        self::$cache[$cacheKey] = self::$defaultVersion;
        return self::$defaultVersion;
    }

    /**
     * Initialize version from request and set as current.
     *
     * @param Request $request
     * @return string
     */
    public static function initialize(Request $request): string
    {
        $version = self::resolveFromRequest($request);
        self::set($version);
        return $version;
    }

    /**
     * Check if version is supported.
     *
     * @param string $version
     * @return bool
     */
    public static function isSupported(string $version): bool
    {
        return in_array(self::normalize($version), self::$supportedVersions, true);
    }

    /**
     * Check if version is deprecated.
     *
     * @param string $version
     * @return bool
     */
    public static function isDeprecated(string $version): bool
    {
        return isset(self::$deprecatedVersions[self::normalize($version)]);
    }

    /**
     * Get deprecation sunset date for version.
     *
     * @param string $version
     * @return string|null
     */
    public static function getSunsetDate(string $version): ?string
    {
        return self::$deprecatedVersions[self::normalize($version)] ?? null;
    }

    /**
     * Normalize version string.
     *
     * @param string $version
     * @return string
     */
    public static function normalize(string $version): string
    {
        $version = strtolower(trim($version));

        // Add 'v' prefix if missing
        if (preg_match('/^\d+$/', $version)) {
            $version = 'v' . $version;
        }

        return $version;
    }

    /**
     * Compare two versions.
     *
     * @param string $a
     * @param string $b
     * @return int -1 if a < b, 0 if equal, 1 if a > b
     */
    public static function compare(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        // Extract numeric parts
        $numA = (int) filter_var($a, FILTER_SANITIZE_NUMBER_INT);
        $numB = (int) filter_var($b, FILTER_SANITIZE_NUMBER_INT);

        return $numA <=> $numB;
    }

    /**
     * Get latest supported version.
     *
     * @return string
     */
    public static function latest(): string
    {
        return self::$supportedVersions[0] ?? self::$defaultVersion;
    }

    /**
     * Get all supported versions.
     *
     * @return array<string>
     */
    public static function getSupportedVersions(): array
    {
        return self::$supportedVersions;
    }

    /**
     * Set supported versions.
     *
     * @param array<string> $versions Newest first
     * @return void
     */
    public static function setSupportedVersions(array $versions): void
    {
        self::$supportedVersions = array_map([self::class, 'normalize'], $versions);
    }

    /**
     * Set default version.
     *
     * @param string $version
     * @return void
     */
    public static function setDefaultVersion(string $version): void
    {
        self::$defaultVersion = self::normalize($version);
    }

    /**
     * Mark version as deprecated.
     *
     * @param string $version
     * @param string $sunsetDate ISO date when version will be removed
     * @return void
     */
    public static function deprecate(string $version, string $sunsetDate): void
    {
        self::$deprecatedVersions[self::normalize($version)] = $sunsetDate;
    }

    /**
     * Add version resolver.
     *
     * @param VersionResolverInterface $resolver
     * @return void
     */
    public static function addResolver(VersionResolverInterface $resolver): void
    {
        self::$resolvers[] = $resolver;
        self::$resolversSorted = false;
    }

    /**
     * Set resolvers (replaces all).
     *
     * @param array<VersionResolverInterface> $resolvers
     * @return void
     */
    public static function setResolvers(array $resolvers): void
    {
        self::$resolvers = $resolvers;
        self::$resolversSorted = false;
    }

    /**
     * Clear cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Reset all state (for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$currentVersion = null;
        self::$defaultVersion = 'v1';
        self::$supportedVersions = ['v1'];
        self::$deprecatedVersions = [];
        self::$resolvers = [];
        self::$resolversSorted = false;
        self::$cache = [];
    }

    /**
     * Get deprecation headers for current version.
     *
     * @return array<string, string>
     */
    public static function getDeprecationHeaders(): array
    {
        $version = self::current();
        $headers = [];

        if (self::isDeprecated($version)) {
            $sunsetDate = self::getSunsetDate($version);
            $headers['Deprecation'] = 'true';
            $headers['Sunset'] = $sunsetDate;
            $headers['X-API-Deprecated'] = "API version {$version} is deprecated. Please upgrade to " . self::latest();
        }

        return $headers;
    }

    /**
     * Generate cache key for request.
     *
     * @param Request $request
     * @return string
     */
    private static function getCacheKey(Request $request): string
    {
        return md5(
            $request->getPath() .
            ($request->header('Accept-Version') ?? '') .
            ($request->header('X-API-Version') ?? '') .
            ($request->input('api_version') ?? '')
        );
    }
}
