<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Auth\AuthManager;
use Toporia\Framework\Auth\Contracts\{AuthManagerInterface, TokenRepositoryInterface, UserProviderInterface};
use Toporia\Framework\Auth\Guards\{PersonalTokenGuard, SessionGuard, TokenGuard};
use Toporia\Framework\Auth\Repositories\TokenRepository;
use Toporia\Framework\Auth\EmailVerification\EmailVerificationBroker;
use Toporia\Framework\Auth\EmailVerification\Contracts\EmailVerificationBrokerInterface;
use Toporia\Framework\Auth\Passwords\PasswordBroker;
use Toporia\Framework\Auth\Passwords\DatabaseTokenRepository;
use Toporia\Framework\Auth\Passwords\Contracts\PasswordBrokerInterface;
use Toporia\Framework\Auth\Passwords\Contracts\TokenRepositoryInterface as PasswordTokenRepositoryInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\Request;


/**
 * Class AuthServiceProvider
 *
 * Abstract base class for service providers responsible for registering
 * and booting framework services following two-phase lifecycle (register
 * then boot).
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
class AuthServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        $this->registerTokenRepository($container);
        $this->registerAuthManager($container);
        $this->registerAuthAlias($container);
        $this->registerEmailVerification($container);
        $this->registerPasswordBroker($container);
    }

    /**
     * Register Auth Manager with guard factories.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerAuthManager(ContainerInterface $container): void
    {
        $container->singleton(AuthManagerInterface::class, function (ContainerInterface $c) {
            $guardFactories = [
                'web' => fn() => $this->createSessionGuard($c, 'web'),
                'api' => fn() => $this->createTokenGuard($c, 'api'),
                'personal-token' => fn() => $this->createPersonalTokenGuard($c),
                // Add more guards here as needed:
                // 'admin' => fn() => $this->createSessionGuard($c, 'admin'),
            ];

            $defaultGuard = $this->getDefaultGuard($c);

            return new AuthManager($guardFactories, $defaultGuard);
        });
    }

    /**
     * Register 'auth' alias for helper function.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerAuthAlias(ContainerInterface $container): void
    {
        $container->singleton('auth', fn(ContainerInterface $c) => $c->get(AuthManagerInterface::class));
    }

    /**
     * Create a session guard instance.
     *
     * Framework gets UserProviderInterface from container (registered by App).
     *
     * @param ContainerInterface $container
     * @param string $name Guard name.
     * @return SessionGuard
     */
    protected function createSessionGuard(ContainerInterface $container, string $name): SessionGuard
    {
        // Get UserProviderInterface from container (App must register it)
        $userProvider = $container->get(UserProviderInterface::class);

        return new SessionGuard($userProvider, $name);
    }

    /**
     * Get default guard name from config.
     *
     * @param ContainerInterface $container
     * @return string
     */
    protected function getDefaultGuard(ContainerInterface $container): string
    {
        try {
            if ($container->has('config')) {
                $config = $container->get('config');
                return $config->get('auth.defaults.guard', 'web');
            }
        } catch (\Throwable $e) {
            // Fall back to 'web'
        }

        return 'web';
    }

    /**
     * Create a token guard instance for API authentication.
     *
     * Framework gets UserProviderInterface from container (registered by App).
     *
     * @param ContainerInterface $container
     * @param string $name Guard name.
     * @return TokenGuard
     */
    protected function createTokenGuard(ContainerInterface $container, string $name): TokenGuard
    {
        // Get UserProviderInterface from container (App must register it)
        $userProvider = $container->get(UserProviderInterface::class);
        $request = $container->get(Request::class);

        return new TokenGuard($userProvider, $request, $name);
    }

    /**
     * Register Token Repository for Personal Token guard.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerTokenRepository(ContainerInterface $container): void
    {
        $container->singleton(TokenRepositoryInterface::class, function (ContainerInterface $c) {
            $connection = $c->has('db') ? $c->get('db') : null;
            $cache = $c->has('cache') ? $c->get('cache') : null;

            if ($connection === null) {
                throw new \RuntimeException('Database connection required for TokenRepository. Please register DatabaseServiceProvider.');
            }

            return new TokenRepository($connection, $cache);
        });

        $container->bind('auth.tokens', fn($c) => $c->get(TokenRepositoryInterface::class));
    }

    /**
     * Create a Personal Token guard instance for API token authentication.
     *
     * Personal Token guard uses database-stored tokens (personal access tokens).
     * Provides token management, scopes, and revocation capabilities.
     *
     * @param ContainerInterface $container
     * @return PersonalTokenGuard
     */
    protected function createPersonalTokenGuard(ContainerInterface $container): PersonalTokenGuard
    {
        $userProvider = $container->get(UserProviderInterface::class);
        $request = $container->get(Request::class);
        $tokens = $container->get(TokenRepositoryInterface::class);

        return new PersonalTokenGuard($request, $userProvider, $tokens);
    }

    /**
     * Register Email Verification Broker.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerEmailVerification(ContainerInterface $container): void
    {
        $container->singleton(EmailVerificationBrokerInterface::class, function (ContainerInterface $c) {
            $key = $this->getAppKey($c);
            $expiration = $this->getConfig($c, 'auth.verification.expire', 60);

            $broker = new EmailVerificationBroker($key, $expiration);

            // Set mailer if available
            if ($c->has('mailer')) {
                $broker->setMailer($c->get('mailer'));
            }

            return $broker;
        });

        $container->bind('auth.verification', fn($c) => $c->get(EmailVerificationBrokerInterface::class));
    }

    /**
     * Register Password Reset Broker.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerPasswordBroker(ContainerInterface $container): void
    {
        // Register password token repository
        $container->singleton(PasswordTokenRepositoryInterface::class, function (ContainerInterface $c) {
            $connection = $c->get('db');
            $hasher = $c->get('hash')->driver();

            $table = $this->getConfig($c, 'auth.passwords.table', 'password_reset_tokens');
            $key = $this->getAppKey($c);
            $expires = $this->getConfig($c, 'auth.passwords.expire', 3600);
            $throttle = $this->getConfig($c, 'auth.passwords.throttle', 60);

            return new DatabaseTokenRepository(
                $connection,
                $hasher,
                $table,
                $key,
                $expires,
                $throttle
            );
        });

        // Register password broker
        $container->singleton(PasswordBrokerInterface::class, function (ContainerInterface $c) {
            $tokens = $c->get(PasswordTokenRepositoryInterface::class);

            // User resolver callback
            $userResolver = function (array $credentials) use ($c) {
                $userProvider = $c->get(UserProviderInterface::class);
                return $userProvider->retrieveByCredentials($credentials);
            };

            return new PasswordBroker($tokens, $userResolver);
        });

        $container->bind('auth.password', fn($c) => $c->get(PasswordBrokerInterface::class));
    }

    /**
     * Get application key.
     *
     * @param ContainerInterface $container
     * @return string
     */
    protected function getAppKey(ContainerInterface $container): string
    {
        return $this->getConfig($container, 'app.key', '');
    }

    /**
     * Get config value.
     *
     * @param ContainerInterface $container
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getConfig(ContainerInterface $container, string $key, mixed $default = null): mixed
    {
        try {
            if ($container->has('config')) {
                return $container->get('config')->get($key, $default);
            }
        } catch (\Throwable) {
            // Fall back to default
        }

        return $default;
    }
}
