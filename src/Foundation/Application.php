<?php

declare(strict_types=1);

namespace Toporia\Framework\Foundation;

use Closure;
use Toporia\Framework\Foundation\Contracts\ServiceProviderInterface;
use Toporia\Framework\Container\Container;
use Toporia\Framework\Container\Contracts\ContainerInterface;


/**
 * Class Application
 *
 * The central application bootstrapper managing dependency injection
 * container, service provider registration, and application boot process
 * following Clean Architecture principles.
 *
 * Features:
 * - Two-phase service provider lifecycle (register then boot)
 * - Deferred service providers for lazy-loading
 * - Booting/Booted lifecycle callbacks
 * - Environment detection and configuration
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Foundation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
class Application
{
    /**
     * @var ContainerInterface Dependency injection container
     */
    private ContainerInterface $container;

    /**
     * @var array<ServiceProviderInterface> Registered service providers
     */
    private array $providers = [];

    /**
     * @var array<string, ServiceProviderInterface> Loaded providers by class name
     */
    private array $loadedProviders = [];

    /**
     * @var array<string, string> Deferred services mapping (service => provider class)
     */
    private array $deferredServices = [];

    /**
     * @var bool Whether service providers have been booted
     */
    private bool $booted = false;

    /**
     * @var array<Closure> Callbacks to run before booting
     */
    private array $bootingCallbacks = [];

    /**
     * @var array<Closure> Callbacks to run after booting
     */
    private array $bootedCallbacks = [];

    /**
     * @var array<Closure> Callbacks to run on termination
     */
    private array $terminatingCallbacks = [];

    /**
     * @param string $basePath Application base path
     * @param ContainerInterface|null $container Optional custom container
     */
    public function __construct(
        private string $basePath,
        ?ContainerInterface $container = null
    ) {
        $this->container = $container ?? new Container();

        // Register the container itself
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance('app', $this);
        $this->container->instance(Application::class, $this);

        // Register core services
        $this->registerCoreServices();

        // Setup deferred service resolution
        $this->setupDeferredServiceResolution();
    }

    /**
     * Setup automatic resolution of deferred services.
     *
     * Registers a before resolving callback that loads deferred providers
     * when their services are requested from the container.
     *
     * @return void
     */
    private function setupDeferredServiceResolution(): void
    {
        // Register callback to load deferred providers when container resolves services
        $this->container->beforeResolving(function (string $abstract) {
            $this->loadDeferredProviderIfNeeded($abstract);
        });
    }

    /**
     * Register a service provider.
     *
     * Deferred providers are stored for lazy-loading instead of being registered immediately.
     *
     * @param string|ServiceProviderInterface $provider Provider class name or instance
     * @param bool $force Force registration even if already registered
     * @return ServiceProviderInterface The registered provider
     */
    public function register(string|ServiceProviderInterface $provider, bool $force = false): ServiceProviderInterface
    {
        $providerClass = is_string($provider) ? $provider : $provider::class;

        // Check if already registered
        if (isset($this->loadedProviders[$providerClass]) && !$force) {
            return $this->loadedProviders[$providerClass];
        }

        // Instantiate if string
        if (is_string($provider)) {
            $provider = new $provider();
        }

        // Handle deferred providers
        if ($provider->isDeferred() && !$this->booted) {
            $this->registerDeferredProvider($provider);
            return $provider;
        }

        // Register immediately
        $provider->register($this->container);
        $this->markAsRegistered($provider);

        // If already booted, boot this provider immediately
        if ($this->booted) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Register a deferred provider for lazy-loading.
     *
     * @param ServiceProviderInterface $provider
     * @return void
     */
    private function registerDeferredProvider(ServiceProviderInterface $provider): void
    {
        $providerClass = $provider::class;

        // Map each provided service to this provider
        foreach ($provider->provides() as $service) {
            $this->deferredServices[$service] = $providerClass;
        }

        // Store the provider instance for later
        $this->loadedProviders[$providerClass] = $provider;
    }

    /**
     * Load a deferred provider by service name.
     *
     * @param string $service Service identifier
     * @return void
     */
    public function loadDeferredProvider(string $service): void
    {
        if (!isset($this->deferredServices[$service])) {
            return;
        }

        $providerClass = $this->deferredServices[$service];

        // Already fully registered?
        if (isset($this->providers[$providerClass])) {
            return;
        }

        $provider = $this->loadedProviders[$providerClass] ?? new $providerClass();

        // Register the provider
        $provider->register($this->container);

        // Remove from deferred list
        foreach ($provider->provides() as $providedService) {
            unset($this->deferredServices[$providedService]);
        }

        // Mark as registered
        $this->providers[] = $provider;

        // Boot if application is already booted
        if ($this->booted) {
            $this->bootProvider($provider);
        }
    }

    /**
     * Load all deferred providers for a given service.
     *
     * @param string $service
     * @return void
     */
    public function loadDeferredProviderIfNeeded(string $service): void
    {
        if (isset($this->deferredServices[$service])) {
            $this->loadDeferredProvider($service);
        }
    }

    /**
     * Mark a provider as registered.
     *
     * @param ServiceProviderInterface $provider
     * @return void
     */
    private function markAsRegistered(ServiceProviderInterface $provider): void
    {
        $this->providers[] = $provider;
        $this->loadedProviders[$provider::class] = $provider;
    }

    /**
     * Register multiple service providers.
     *
     * @param array<string|ServiceProviderInterface> $providers
     * @return self
     */
    public function registerProviders(array $providers): self
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }

        return $this;
    }

    /**
     * Boot all registered service providers.
     *
     * @return self
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        // Fire booting callbacks
        $this->fireAppCallbacks($this->bootingCallbacks);

        // Boot each provider
        foreach ($this->providers as $provider) {
            $this->bootProvider($provider);
        }

        $this->booted = true;

        // Fire booted callbacks
        $this->fireAppCallbacks($this->bootedCallbacks);

        return $this;
    }

    /**
     * Boot a single service provider.
     *
     * @param ServiceProviderInterface $provider
     * @return void
     */
    private function bootProvider(ServiceProviderInterface $provider): void
    {
        $provider->boot($this->container);
    }

    /**
     * Register a callback to run before booting.
     *
     * @param Closure $callback
     * @return self
     */
    public function booting(Closure $callback): self
    {
        $this->bootingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to run after booting.
     *
     * @param Closure $callback
     * @return self
     */
    public function booted(Closure $callback): self
    {
        $this->bootedCallbacks[] = $callback;

        // If already booted, fire immediately
        if ($this->booted) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Fire application callbacks.
     *
     * @param array<Closure> $callbacks
     * @return void
     */
    private function fireAppCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Get deferred services and their providers.
     *
     * @return array<string, string>
     */
    public function getDeferredServices(): array
    {
        return $this->deferredServices;
    }

    /**
     * Set deferred services (useful for caching).
     *
     * @param array<string, string> $services
     * @return void
     */
    public function setDeferredServices(array $services): void
    {
        $this->deferredServices = $services;
    }

    /**
     * Determine if a service is deferred.
     *
     * @param string $service
     * @return bool
     */
    public function isDeferredService(string $service): bool
    {
        return isset($this->deferredServices[$service]);
    }

    /**
     * Get all registered providers.
     *
     * @return array<ServiceProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get a provider instance by class name.
     *
     * @param string $providerClass
     * @return ServiceProviderInterface|null
     */
    public function getProvider(string $providerClass): ?ServiceProviderInterface
    {
        return $this->loadedProviders[$providerClass] ?? null;
    }

    /**
     * Get the container instance.
     *
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get the base path of the application.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Get a path relative to the base path.
     *
     * @param string $path
     * @return string
     */
    public function path(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    /**
     * Check if a service exists in the container.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * Register core framework services.
     *
     * These services are essential for the framework operation and should
     * be available immediately after application instantiation.
     *
     * Performance: O(1) - Singleton registration
     *
     * @return void
     */
    private function registerCoreServices(): void
    {
        // Core services are registered here if needed
        // Reflection is handled by PHP's native Reflection API - no wrapper service needed
    }

    // =========================================================================
    // ENVIRONMENT & CONFIGURATION METHODS
    // =========================================================================

    /**
     * Get the application environment.
     *
     * @return string
     */
    public function environment(): string
    {
        return $this->make('config')->get('app.env', 'production');
    }

    /**
     * Check if the application is in the given environment(s).
     *
     * @param string|array $environments
     * @return bool
     */
    public function isEnvironment(string|array $environments): bool
    {
        $currentEnv = $this->environment();

        if (is_string($environments)) {
            return $currentEnv === $environments;
        }

        return in_array($currentEnv, $environments, true);
    }

    /**
     * Check if the application is in local environment.
     *
     * @return bool
     */
    public function isLocal(): bool
    {
        return $this->isEnvironment('local');
    }

    /**
     * Check if the application is in development environment.
     *
     * @return bool
     */
    public function isDevelopment(): bool
    {
        return $this->isEnvironment(['local', 'development']);
    }

    /**
     * Check if the application is in production environment.
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this->isEnvironment('production');
    }

    /**
     * Check if the application is in staging environment.
     *
     * @return bool
     */
    public function isStaging(): bool
    {
        return $this->isEnvironment('staging');
    }

    /**
     * Check if debug mode is enabled.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool) $this->make('config')->get('app.debug', false);
    }

    /**
     * Get the application name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->make('config')->get('app.name', 'Toporia Application');
    }

    /**
     * Get the application version.
     *
     * @return string
     */
    public function version(): string
    {
        return '1.0.0'; // Could be loaded from composer.json or version file
    }

    /**
     * Get the application locale.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->make('config')->get('app.locale', 'en');
    }

    /**
     * Get the application timezone.
     *
     * @return string
     */
    public function getTimezone(): string
    {
        return $this->make('config')->get('app.timezone', 'UTC');
    }

    // =========================================================================
    // PATH HELPER METHODS
    // =========================================================================

    /**
     * Get the path to the application configuration files.
     *
     * @param string $path
     * @return string
     */
    public function configPath(string $path = ''): string
    {
        return $this->path('config' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    /**
     * Get the path to the database directory.
     *
     * @param string $path
     * @return string
     */
    public function databasePath(string $path = ''): string
    {
        return $this->path('database' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    /**
     * Get the path to the storage directory.
     *
     * @param string $path
     * @return string
     */
    public function storagePath(string $path = ''): string
    {
        return $this->path('storage' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    /**
     * Get the path to the public directory.
     *
     * @param string $path
     * @return string
     */
    public function publicPath(string $path = ''): string
    {
        return $this->path('public' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    /**
     * Get the path to the resources directory.
     *
     * @param string $path
     * @return string
     */
    public function resourcePath(string $path = ''): string
    {
        return $this->path('resources' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path
     * @return string
     */
    public function bootstrapPath(string $path = ''): string
    {
        return $this->path('bootstrap' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole(): bool
    {
        return php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg';
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests(): bool
    {
        return $this->isEnvironment('testing') ||
            defined('PHPUNIT_RUNNING') ||
            class_exists('PHPUnit\\Framework\\TestCase');
    }

    /**
     * Get or check the current application locale.
     *
     * @param string|null $locale
     * @return string|bool
     */
    public function locale(?string $locale = null): string|bool
    {
        if ($locale === null) {
            return $this->getLocale();
        }

        // Set locale logic would go here
        return $this->getLocale() === $locale;
    }

    /**
     * Determine if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped(): bool
    {
        return $this->booted;
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return 'App\\';
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->providers = [];
        $this->booted = false;

        // Reset container if it supports flushing
        if (method_exists($this->container, 'flush')) {
            $this->container->flush();
        }
    }

    /**
     * Register a terminating callback with the application.
     *
     * @param Closure $callback
     * @return self
     */
    public function terminating(Closure $callback): self
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate(): void
    {
        foreach ($this->terminatingCallbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Resolve a service, loading deferred provider if needed.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    public function makeWith(string $abstract, array $parameters = []): mixed
    {
        // Load deferred provider if this service is deferred
        $this->loadDeferredProviderIfNeeded($abstract);

        return $this->container->make($abstract, $parameters);
    }

    /**
     * Resolve a service, loading deferred provider if needed.
     *
     * Override make to support deferred providers.
     *
     * @param string $id
     * @return mixed
     */
    public function make(string $id): mixed
    {
        // Load deferred provider if this service is deferred
        $this->loadDeferredProviderIfNeeded($id);

        return $this->container->get($id);
    }

    /**
     * Determine if a provider has been loaded.
     *
     * @param string $provider Provider class name
     * @return bool
     */
    public function providerIsLoaded(string $provider): bool
    {
        return isset($this->loadedProviders[$provider]);
    }

    /**
     * Get the registered service provider instances if any exist.
     *
     * @param string $provider Provider class name
     * @return array
     */
    public function getLoadedProviders(): array
    {
        return $this->loadedProviders;
    }
}
