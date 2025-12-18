<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation\Bootstrap;

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Foundation\FrameworkServiceProvider;
use Toporia\Framework\Foundation\PackageManifest;

/**
 * Class RegisterProviders
 *
 * Registers all framework, package, and application service providers.
 * Supports automatic package discovery from composer.json extra.toporia config.
 *
 * Provider Loading Order:
 * 1. Framework providers (core framework services)
 * 2. Auto-discovered package providers (from packages/ and vendor/)
 * 3. Application providers (user-defined providers)
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Foundation\Bootstrap
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RegisterProviders
{
    /**
     * Bootstrap provider registration.
     *
     * Loading Order:
     * 1. Framework providers (core framework services)
     * 2. Auto-discovered package providers (from packages/ and vendor/)
     * 3. Application providers (from config/providers.php)
     *
     * @param Application $app Application instance
     * @return void
     */
    public static function bootstrap(Application $app): void
    {
        $providers = [];

        // =====================================================================
        // 1. FRAMEWORK PROVIDERS (auto-loaded from FrameworkServiceProvider)
        // =====================================================================
        $providers = array_merge($providers, FrameworkServiceProvider::providers());

        // =====================================================================
        // 2. AUTO-DISCOVERED PACKAGE PROVIDERS
        // =====================================================================
        $packageProviders = static::discoverPackageProviders($app);
        $providers = array_merge($providers, $packageProviders);

        // =====================================================================
        // 3. APPLICATION PROVIDERS (from config/providers.php)
        // =====================================================================
        $appProviders = static::loadApplicationProviders($app);
        $providers = array_merge($providers, $appProviders);

        // Register all providers
        $app->registerProviders($providers);
    }

    /**
     * Load application providers from config/providers.php.
     *
     * This separates business logic providers from framework core,
     * following the Clean Architecture principle.
     *
     * @param Application $app
     * @return array<string> Provider class names
     */
    private static function loadApplicationProviders(Application $app): array
    {
        $configPath = $app->getBasePath() . '/config/providers.php';

        if (!file_exists($configPath)) {
            return [];
        }

        $config = require $configPath;

        return $config['providers'] ?? [];
    }

    /**
     * Discover package providers from manifest.
     *
     * Uses PackageManifest to get cached list of providers from packages.
     * Manifest is automatically rebuilt when composer.lock changes.
     *
     * @param Application $app
     * @return array<string> Provider class names
     */
    private static function discoverPackageProviders(Application $app): array
    {
        $basePath = $app->getBasePath();

        // Logger is not available yet during provider registration,
        // so pass null (logging will be disabled during discovery)
        $manifest = new PackageManifest(
            $basePath . '/bootstrap/cache/packages.php',
            $basePath,
            $basePath . '/vendor',
            $basePath . '/packages',
            null  // No logger available during early bootstrap
        );

        return $manifest->providers();
    }
}
