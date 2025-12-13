<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Security\Contracts\{CsrfTokenManagerInterface, ReplayAttackProtectionInterface};
use Toporia\Framework\Security\{SessionCsrfTokenManager, SessionReplayAttackProtection, XssService};
use Toporia\Framework\Auth\Contracts\{AuthManagerInterface, GateContract};
use Toporia\Framework\Auth\Access\Gate;
use Toporia\Framework\Http\CookieJar;
use Toporia\Framework\RateLimit\{CacheRateLimiter, Contracts\RateLimiterInterface, RateLimiter};
use Toporia\Framework\Session\Store;

/**
 * Class SecurityServiceProvider
 *
 * Registers security-related services including CSRF protection, Authorization (Gates), and Cookie management.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // CSRF Token Manager (requires Store from SessionServiceProvider)
        $container->singleton(CsrfTokenManagerInterface::class, function ($c) {
            $session = $c->get(Store::class);
            return new SessionCsrfTokenManager($session);
        });

        $container->bind('csrf', fn($c) => $c->get(CsrfTokenManagerInterface::class));

        // Authorization Gate
        $container->singleton(GateContract::class, function ($c) {
            $auth = $c->has('auth') ? $c->get('auth') : null;
            return new Gate($auth);
        });

        $container->bind('gate', fn($c) => $c->get(GateContract::class));

        // Cookie Jar
        $container->singleton(CookieJar::class, function () {
            $key = env('APP_KEY');
            return new CookieJar($key);
        });

        $container->bind('cookie', fn($c) => $c->get(CookieJar::class));

        // XSS Protection Service
        $container->singleton(XssService::class, function () {
            return new XssService();
        });

        $container->bind('xss', fn($c) => $c->get(XssService::class));

        // Replay Attack Protection (requires Store from SessionServiceProvider)
        $container->singleton(ReplayAttackProtectionInterface::class, function ($c) {
            $session = $c->get(Store::class);
            return new SessionReplayAttackProtection($session);
        });

        $container->bind('replay', fn($c) => $c->get(ReplayAttackProtectionInterface::class));

        // Rate Limiter (for API throttling)
        $container->singleton(RateLimiterInterface::class, function ($c) {
            $cache = $c->has('cache') ? $c->get('cache') : null;
            if ($cache === null) {
                throw new \RuntimeException('Cache service is required for rate limiting. Please register CacheServiceProvider.');
            }
            return new CacheRateLimiter($cache);
        });

        $container->bind('rate_limiter', fn($c) => $c->get(RateLimiterInterface::class));
    }

    public function boot(ContainerInterface $container): void
    {
        // Note: Session is started lazily by StartSession middleware
        // Do NOT call session_start() here - it adds unnecessary overhead

        // Set RateLimiter instance for named limiters
        // This allows AppServiceProvider to register named limiters
        $limiter = $container->get(RateLimiterInterface::class);
        RateLimiter::setLimiter($limiter);
    }
}
