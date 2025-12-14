<?php

declare(strict_types=1);

namespace Toporia\Framework\MultiTenancy;

use Toporia\Framework\MultiTenancy\Contracts\TenantInterface;
use Toporia\Framework\MultiTenancy\Contracts\TenantResolverInterface;
use Toporia\Framework\MultiTenancy\Events\TenantIdentified;
use Toporia\Framework\MultiTenancy\Events\TenantNotFound;
use Toporia\Framework\MultiTenancy\Events\TenantSwitched;
use Toporia\Framework\MultiTenancy\Exceptions\TenantException;
use Toporia\Framework\Http\Request;

/**
 * Class TenantManager
 *
 * Central manager for multi-tenancy operations.
 * Handles tenant resolution, context management, and lifecycle events.
 *
 * Features:
 * - Multiple resolution strategies (subdomain, header, path)
 * - Tenant context stack for nested operations
 * - Event-driven lifecycle (identified, switched, not found)
 * - Thread-safe singleton pattern
 *
 * Usage:
 *   // Get current tenant
 *   $tenant = TenantManager::current();
 *
 *   // Check tenant context
 *   if (TenantManager::check()) { ... }
 *
 *   // Run code as different tenant
 *   TenantManager::run($tenant, function() {
 *       // Code runs in $tenant context
 *   });
 *
 * Performance:
 * - O(1) tenant retrieval after resolution
 * - Lazy loading of tenant data
 * - Cached resolution results
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
final class TenantManager
{
    /**
     * Current active tenant.
     */
    private static ?TenantInterface $currentTenant = null;

    /**
     * Tenant context stack for nested operations.
     *
     * @var array<TenantInterface|null>
     */
    private static array $tenantStack = [];

    /**
     * Registered tenant resolvers (sorted by priority).
     *
     * @var array<TenantResolverInterface>
     */
    private static array $resolvers = [];

    /**
     * Whether resolvers are sorted.
     */
    private static bool $resolversSorted = false;

    /**
     * Cached resolution results by request hash.
     *
     * @var array<string, TenantInterface|null>
     */
    private static array $resolutionCache = [];

    /**
     * Event dispatcher instance.
     */
    private static mixed $eventDispatcher = null;

    /**
     * Whether tenant was identified from request.
     */
    private static bool $identified = false;

    /**
     * Resolver that identified the tenant.
     */
    private static ?string $identifiedBy = null;

    /**
     * Get current tenant.
     *
     * @return TenantInterface|null
     */
    public static function current(): ?TenantInterface
    {
        return self::$currentTenant;
    }

    /**
     * Get current tenant or fail.
     *
     * @return TenantInterface
     * @throws TenantException
     */
    public static function currentOrFail(): TenantInterface
    {
        if (self::$currentTenant === null) {
            throw TenantException::noTenantContext();
        }

        return self::$currentTenant;
    }

    /**
     * Check if tenant context is active.
     *
     * @return bool
     */
    public static function check(): bool
    {
        return self::$currentTenant !== null;
    }

    /**
     * Get current tenant ID.
     *
     * @return int|string|null
     */
    public static function id(): int|string|null
    {
        return self::$currentTenant?->getTenantKey();
    }

    /**
     * Set current tenant.
     *
     * @param TenantInterface|null $tenant
     * @param bool $dispatchEvents
     * @return void
     */
    public static function set(?TenantInterface $tenant, bool $dispatchEvents = true): void
    {
        $previous = self::$currentTenant;
        self::$currentTenant = $tenant;

        if ($dispatchEvents && self::$eventDispatcher !== null && $tenant !== $previous) {
            self::dispatchEvent(new TenantSwitched($tenant, $previous));
        }
    }

    /**
     * Forget current tenant.
     *
     * @return void
     */
    public static function forget(): void
    {
        self::$currentTenant = null;
        self::$identified = false;
        self::$identifiedBy = null;
    }

    /**
     * Resolve tenant from request using registered resolvers.
     *
     * @param Request $request
     * @return TenantInterface|null
     */
    public static function resolveFromRequest(Request $request): ?TenantInterface
    {
        // Check cache first
        $cacheKey = self::getRequestCacheKey($request);
        if (isset(self::$resolutionCache[$cacheKey])) {
            return self::$resolutionCache[$cacheKey];
        }

        // Sort resolvers by priority if needed
        if (!self::$resolversSorted) {
            usort(self::$resolvers, fn($a, $b) => $b->priority() <=> $a->priority());
            self::$resolversSorted = true;
        }

        // Try each resolver
        foreach (self::$resolvers as $resolver) {
            $tenant = $resolver->resolve($request);

            if ($tenant !== null) {
                self::$resolutionCache[$cacheKey] = $tenant;
                self::$identified = true;
                self::$identifiedBy = $resolver->name();

                // Dispatch identified event
                if (self::$eventDispatcher !== null) {
                    self::dispatchEvent(new TenantIdentified($tenant, $resolver->name()));
                }

                return $tenant;
            }
        }

        // No tenant found
        self::$resolutionCache[$cacheKey] = null;

        if (self::$eventDispatcher !== null) {
            self::dispatchEvent(new TenantNotFound($request));
        }

        return null;
    }

    /**
     * Initialize tenant from request and set as current.
     *
     * @param Request $request
     * @return TenantInterface|null
     */
    public static function initialize(Request $request): ?TenantInterface
    {
        $tenant = self::resolveFromRequest($request);
        self::set($tenant, true);
        return $tenant;
    }

    /**
     * Run callback in context of specific tenant.
     *
     * @template T
     * @param TenantInterface|null $tenant
     * @param callable(): T $callback
     * @return T
     */
    public static function run(?TenantInterface $tenant, callable $callback): mixed
    {
        // Push current tenant to stack
        self::$tenantStack[] = self::$currentTenant;

        try {
            self::set($tenant, false);
            return $callback();
        } finally {
            // Restore previous tenant
            self::$currentTenant = array_pop(self::$tenantStack);
        }
    }

    /**
     * Run callback without tenant context.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runWithoutTenant(callable $callback): mixed
    {
        return self::run(null, $callback);
    }

    /**
     * Register a tenant resolver.
     *
     * @param TenantResolverInterface $resolver
     * @return void
     */
    public static function addResolver(TenantResolverInterface $resolver): void
    {
        self::$resolvers[] = $resolver;
        self::$resolversSorted = false;
    }

    /**
     * Set resolvers (replaces all).
     *
     * @param array<TenantResolverInterface> $resolvers
     * @return void
     */
    public static function setResolvers(array $resolvers): void
    {
        self::$resolvers = $resolvers;
        self::$resolversSorted = false;
    }

    /**
     * Get registered resolvers.
     *
     * @return array<TenantResolverInterface>
     */
    public static function getResolvers(): array
    {
        return self::$resolvers;
    }

    /**
     * Set event dispatcher.
     *
     * @param mixed $dispatcher
     * @return void
     */
    public static function setEventDispatcher(mixed $dispatcher): void
    {
        self::$eventDispatcher = $dispatcher;
    }

    /**
     * Check if tenant was identified from request.
     *
     * @return bool
     */
    public static function wasIdentified(): bool
    {
        return self::$identified;
    }

    /**
     * Get resolver name that identified current tenant.
     *
     * @return string|null
     */
    public static function identifiedBy(): ?string
    {
        return self::$identifiedBy;
    }

    /**
     * Clear resolution cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$resolutionCache = [];
    }

    /**
     * Reset all state (for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$currentTenant = null;
        self::$tenantStack = [];
        self::$resolvers = [];
        self::$resolversSorted = false;
        self::$resolutionCache = [];
        self::$identified = false;
        self::$identifiedBy = null;
    }

    /**
     * Generate cache key for request.
     *
     * @param Request $request
     * @return string
     */
    private static function getRequestCacheKey(Request $request): string
    {
        return md5(
            $request->getHost() .
            $request->getPath() .
            ($request->header('X-Tenant-ID') ?? '')
        );
    }

    /**
     * Dispatch event through dispatcher.
     *
     * @param object $event
     * @return void
     */
    private static function dispatchEvent(object $event): void
    {
        if (self::$eventDispatcher === null) {
            return;
        }

        if (method_exists(self::$eventDispatcher, 'dispatch')) {
            self::$eventDispatcher->dispatch($event);
        }
    }
}
